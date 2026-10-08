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

it('tells a withheld value from a real null', function () {
    expect(Present::withheld(['disclosed' => false, 'value' => null, 'set' => true]))
        ->toBe(['disclosed' => false, 'set' => true, 'withheld' => true])
        ->and(Present::withheld(['disclosed' => true, 'value' => null, 'set' => false]))
        ->toBe(['disclosed' => true, 'value' => null, 'set' => false, 'withheld' => false]);
});

it('measures a distance in one unit, in either direction', function (int $from, int $to, string $expected) {
    expect(Present::distance($from, $to))->toBe($expected);
})->with([
    [100, 101, '1 second ago'],
    [0, 600, '10 minutes ago'],
    [0, 18000, '5 hours ago'],
    [0, 432000, '5 days ago'],
    [600, 0, 'in 10 minutes'],
]);
