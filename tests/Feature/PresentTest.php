<?php

use App\Support\Present;

it('adds an ISO time beside a timestamp and leaves everything else alone', function () {
    expect(Present::timestamps([
        'now' => 1791422596,
        'since' => 0,
        'oldest_overdue_date' => null,
        'count' => 1791422596,
        'line' => 1285,
        'day' => '2026-10-08',
        'entries' => [['next_run' => 1791422642, 'overdue' => false]],
    ], 'UTC'))->toBe([
        'now' => 1791422596,
        'now_iso' => '2026-10-08T01:23:16Z',
        'since' => 0,
        'oldest_overdue_date' => null,
        'count' => 1791422596,
        'line' => 1285,
        'day' => '2026-10-08',
        'entries' => [['next_run' => 1791422642, 'next_run_iso' => '2026-10-08T01:24:02Z', 'overdue' => false]],
    ]);
});

it('adds a local time with its offset when a zone is set, and ignores a zone PHP does not know', function () {
    expect(Present::timestamps(['now' => 1791422596], 'Australia/Sydney')['now_local'])->toBe('2026-10-08T12:23:16+11:00')
        ->and(Present::timestamps(['now' => 1791422596], 'Mars/Olympus'))->not->toHaveKey('now_local');
});

it('tells a withheld value from a real null, in either mode', function (array $entry, array $expected) {
    expect(Present::disclosure($entry))->toBe($expected);
})->with([
    'shape mode, withheld' => [
        ['disclosed' => false, 'value' => null, 'set' => true],
        ['disclosure' => 'withheld', 'set' => true],
    ],
    'shape mode, a real null' => [
        ['disclosed' => true, 'value' => null, 'set' => false],
        ['disclosure' => 'shown', 'value' => null, 'set' => false],
    ],
    'values mode, a real null' => [
        ['disclosed' => true, 'value' => null, 'redactions' => []],
        ['disclosure' => 'shown', 'value' => null, 'redactions' => []],
    ],
    'values mode, a nested part withheld beside a real null' => [
        ['disclosed' => false, 'value' => ['a' => ['key' => null], 'b' => null], 'redactions' => [['path' => 'a.key', 'segments' => ['a', 'key'], 'reason' => 'name']]],
        [
            'disclosure' => 'partly_withheld',
            'value' => ['a' => ['key' => '[withheld]'], 'b' => null],
            'redactions' => [['path' => 'a.key', 'reason' => 'name']],
        ],
    ],
    'values mode, a key with a dot in it and a list index' => [
        [
            'disclosed' => false,
            'value' => ['mail.example.test' => ['password' => null], 'list' => ['a', null]],
            'redactions' => [
                ['path' => 'mail.example.test.password', 'segments' => ['mail.example.test', 'password'], 'reason' => 'name'],
                ['path' => 'list.1', 'segments' => ['list', '1'], 'reason' => 'value'],
            ],
        ],
        [
            'disclosure' => 'partly_withheld',
            'value' => ['mail.example.test' => ['password' => '[withheld]'], 'list' => ['a', '[withheld]']],
            'redactions' => [
                ['path' => 'mail.example.test.password', 'reason' => 'name'],
                ['path' => 'list.1', 'reason' => 'value'],
            ],
        ],
    ],
    'a configuration setting withheld because a key above it was' => [
        ['disclosed' => false, 'value' => null, 'redactions' => [['path' => '', 'segments' => [], 'inherited_from' => 'acmeService', 'reason' => 'untrusted_add_on']]],
        ['disclosure' => 'withheld', 'redactions' => [[
            'path' => '', 'inherited_from' => 'acmeService', 'reason' => 'untrusted_add_on',
        ]]],
    ],
    'a path that is not in the value creates nothing' => [
        ['disclosed' => false, 'value' => ['a' => 1], 'redactions' => [['path' => 'b.c', 'segments' => ['b', 'c'], 'reason' => 'name']]],
        ['disclosure' => 'partly_withheld', 'value' => ['a' => 1], 'redactions' => [['path' => 'b.c', 'reason' => 'name']]],
    ],
    'a secret that holds nothing is withheld all the same, and says so' => [
        ['disclosed' => false, 'value' => ['password' => null], 'redactions' => [['path' => 'password', 'segments' => ['password'], 'reason' => 'name', 'set' => false]]],
        [
            'disclosure' => 'partly_withheld',
            'value' => ['password' => '[withheld: not set]'],
            'redactions' => [['path' => 'password', 'reason' => 'name', 'set' => false]],
        ],
    ],
    'values mode, a reason this tool has not heard of' => [
        ['disclosed' => false, 'value' => null, 'redactions' => [['path' => '', 'reason' => 'novel']]],
        ['disclosure' => 'withheld', 'redactions' => [['path' => '', 'reason' => 'novel']]],
    ],
]);

it('explains each redaction reason once, members included, and an unknown one as unknown', function () {
    $entries = Present::disclosures([
        ['value' => null, 'disclosed' => false, 'redactions' => [['path' => '', 'reason' => 'known_secret']]],
        ['value' => null, 'disclosed' => false, 'redactions' => [['path' => '', 'reason' => 'known_secret']]],
        ['value' => ['a' => 1], 'members' => [
            ['value' => null, 'disclosed' => false, 'redactions' => [['path' => '', 'reason' => 'novel']]],
        ]],
        ['value' => 'shown', 'redactions' => []],
    ]);

    expect(Present::reasonLegend($entries))->toBe([
        'known_secret' => 'it is on the list of known secrets',
        'novel' => 'the forum gave a reason this tool does not know',
    ])
        ->and(Present::reasonLegend([['disclosure' => 'shown', 'value' => 1]]))->toBe([]);
});

it('puts the legend just before the list it explains, and only when something was withheld', function () {
    $entries = Present::disclosures([['value' => null, 'disclosed' => false, 'redactions' => [['path' => '', 'reason' => 'name']]]]);

    expect(array_keys(Present::withReasons(['mode' => 'values', 'options' => $entries], $entries)))
        ->toBe(['mode', 'redaction_reasons', 'options'])
        ->and(Present::withReasons(['options' => []], []))->toBe(['options' => []]);
});

it('measures a distance in one unit, in either direction', function (int $from, int $to, string $expected) {
    expect(Present::distance($from, $to))->toBe($expected);
})->with([
    [100, 101, '1 second ago'],
    [0, 600, '10 minutes ago'],
    [0, 18000, '5 hours ago'],
    [0, 432000, '5 days ago'],
    [0, 59 * 86400, '59 days ago'],
    [0, 90 * 86400, '2 months ago'],
    [0, 729 * 86400, '23 months ago'],
    [0, 2377 * 86400, '6.5 years ago'],
    [0, 731 * 86400, '2 years ago'],
    [600, 0, 'in 10 minutes'],
]);
