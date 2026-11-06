<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOns;
use App\Mcp\Tools\ApprovalQueue;
use App\Mcp\Tools\CronEntries;
use App\Mcp\Tools\ErrorLogEntries;
use App\Mcp\Tools\ErrorLogSummary;
use App\Mcp\Tools\ForumActivity;
use App\Mcp\Tools\ForumConfiguration;
use App\Mcp\Tools\ForumLogs;
use App\Mcp\Tools\ForumOptions;
use App\Mcp\Tools\JobQueue;
use App\Mcp\Tools\ListForums;
use App\Mcp\Tools\Reports;
use App\Mcp\Tools\UserUpgrades;
use App\Mcp\Tools\WhoIsOnline;
use App\Support\ForumInventoryException;

/**
 * The structured content of a tool call, for the assertions assertStructuredContent()
 * cannot make - key order, and the absence of a key.
 *
 * @return array<mixed>
 */
function structured(string $tool, array $arguments): array
{
    $content = [];

    XenForoServer::tool($tool, $arguments)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(function ($json) use (&$content) {
            $content = $json->toArray();
            $json->etc();
        });

    return $content;
}

it('lists the configured forums without contacting any', function () {
    $content = structured(ListForums::class, []);

    expect($content['count'])->toBe(2)
        ->and($content['forums'][0])->toBe([
            'id' => 'acme', 'name' => 'Acme Forum', 'url' => 'https://forum.example.test', 'detail_key_configured' => true,
        ])
        ->and($content['forums'][1]['detail_key_configured'])->toBeFalse()
        ->and(sentRequests())->toBe([]);
});

it('reports an unreadable inventory as an error, not as no forums', function () {
    useForums(new ForumInventoryException('file not found'));

    XenForoServer::tool(ListForums::class)->assertHasErrors(['file not found']);
});

it('summarises the error log with a headline and an ISO time beside every timestamp', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-all']);

    $content = structured(ErrorLogSummary::class, ['forum' => 'acme', 'since' => 'all', 'limit' => 3]);

    expect($content['forum'])->toBe(['id' => 'acme', 'name' => 'Acme Forum'])
        ->and($content['headline'])->toStartWith('61 errors in this window across 18 signatures; the newest is from 2026-10-08T01:16:40Z')
        ->and($content['headline'])->toContain('None came from a web request.')
        ->and($content['log']['newest_date'])->toBe(1791422200)
        ->and($content['log']['newest_date_iso'])->toBe('2026-10-08T01:16:40Z')
        ->and($content['signatures'][0]['last_date_iso'])->toBe('2026-10-08T01:16:40Z')
        ->and($content['by_origin_add_on'][0]['newest_date_iso'])->toBe('2026-10-08T01:16:40Z');
});

it('puts whose-fault before where-it-surfaced', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-all']);

    $keys = array_keys(structured(ErrorLogSummary::class, ['forum' => 'acme']));

    expect(array_slice($keys, 0, 4))->toBe(['forum', 'times', 'headline', 'notes'])
        ->and(array_search('by_origin_add_on', $keys))->toBeLessThan(array_search('by_location', $keys))
        ->and(end($keys))->toBe('by_add_on');
});

it('says an empty window is not an empty log', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-empty-window']);

    $content = structured(ErrorLogSummary::class, ['forum' => 'acme']);

    expect($content['headline'])
        ->toStartWith('Nothing in this window, but the log holds 61 entries; the newest is from 2026-10-08T01:16:40Z')
        ->toContain('since "all"')
        ->and($content['notes'])->toContain('No logged error is not the same as nothing being wrong.');
});

it('notes a window that starts before the log does, and signatures left out', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-all']);

    $notes = implode(' ', structured(ErrorLogSummary::class, ['forum' => 'acme'])['notes']);

    expect($notes)->toContain('starts before the oldest entry')->toContain('more signatures than are listed');
});

it('says when an add-on is blamed on the strength of the message alone', function () {
    // not captured: no forum the fixtures came from had an error attributed this way
    $log = captured('error-log-all');
    $log['by_origin_add_on'][0] = ['from_message' => 2] + $log['by_origin_add_on'][0];

    fakeForum(['admin-api/error-log/' => Http::response($log)]);

    expect(implode(' ', structured(ErrorLogSummary::class, ['forum' => 'acme'])['notes']))
        ->toContain('2 of these errors are attributed to an add-on because the message names it, not the trace');
});

it('says nothing about messages when every attribution came from a trace', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-all']);

    expect(implode(' ', structured(ErrorLogSummary::class, ['forum' => 'acme'])['notes']))->not->toContain('message names it');
});

it('sends the window the agent asked for', function (array $arguments, array $query) {
    fakeForum(['admin-api/error-log/' => 'error-log-24h']);

    structured(ErrorLogSummary::class, ['forum' => 'acme'] + $arguments);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $sent);

    expect($sent)->toEqual($query);
})->with([
    'nothing: the add-on defaults apply' => [[], []],
    'all is the whole log' => [['since' => 'all'], ['since' => '0']],
    'ISO 8601' => [['since' => '2026-10-01T00:00:00Z'], ['since' => '1790812800']],
    'a time with no zone is UTC' => [['since' => '2026-10-01 00:00:00'], ['since' => '1790812800']],
    'unix seconds' => [['since' => '1790812800', 'until' => '1790899200'], ['since' => '1790812800', 'until' => '1790899200']],
    'limit is clamped' => [['limit' => 500], ['limit' => '100']],
]);

it('refuses a time it cannot read without contacting the forum', function () {
    XenForoServer::tool(ErrorLogSummary::class, ['forum' => 'acme', 'since' => 'the day before the thing'])
        ->assertHasErrors(['`since` is not a time this tool can read']);

    expect(sentRequests())->toBe([]);
});

it('adds a local time beside each timestamp when a display zone is set', function () {
    config(['xfmcp.timezone' => 'Australia/Sydney']);
    fakeForum(['admin-api/cron/' => 'cron']);

    $content = structured(CronEntries::class, ['forum' => 'acme']);

    expect($content['now_local'])->toEndWith('+11:00')
        ->and($content['times'])->toHaveKey('local');
});

it('reads individual entries with the detail key, and without the request by default', function () {
    fakeForum(['admin-api/error-entries/' => 'error-entries']);

    $content = structured(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 15853]);

    $request = sentRequests()[0];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sent);

    expect($content['signature_total'])->toBe(33)
        ->and($content['entries'][0]['exception_date_iso'])->toBe('2026-10-08T01:16:40Z')
        ->and($request->header('XF-Api-Key'))->toBe([DETAIL_KEY])
        ->and($request->hasHeader('XF-Api-User'))->toBeFalse()
        ->and($sent)->toEqual(['error_id' => '15853']);
});

it('says when the forum took secrets out of an entry\'s text', function () {
    // not captured: built from the add-on's description; no released build scrubs yet
    $entries = captured('error-entries');
    $entries['entries'][0]['message'] = 'Slack said no: https://hooks.example.test/[withheld]';
    $entries['entries'][0]['scrubbed'] = 1;

    fakeForum(['admin-api/error-entries/' => Http::response($entries)]);

    $content = structured(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 15853]);

    expect($content['notes'])->toBe(['The forum removed 1 secret from the text of these entries. Each `[withheld]` marks one; it is not what was logged.']);
});

it('adds no note to entries nothing was removed from', function () {
    fakeForum(['admin-api/error-entries/' => 'error-entries']);

    expect(structured(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 15853]))->not->toHaveKey('notes');
});

it('asks for the request only when told to', function () {
    fakeForum(['admin-api/error-entries/' => 'error-entries']);

    structured(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 15853, 'include_request' => true, 'limit' => 99]);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $sent);

    expect($sent)->toEqual(['error_id' => '15853', 'limit' => '20', 'request' => '1']);
});

it('will not read entries from a forum with no detail key, and does not try the read key', function () {
    XenForoServer::tool(ErrorLogEntries::class, ['forum' => 'plain', 'error_id' => 1])
        ->assertHasErrors(['has no detail key configured']);

    expect(sentRequests())->toBe([]);
});

it('sends every summary with the read key and never as a super user', function (string $tool, string $path, string $fixture) {
    fakeForum([$path => $fixture]);

    structured($tool, ['forum' => 'acme']);

    $request = sentRequests()[0];

    expect($request->method())->toBe('GET')
        ->and($request->header('XF-Api-Key'))->toBe([READ_KEY])
        ->and($request->hasHeader('XF-Api-User'))->toBeFalse();
})->with([
    [ErrorLogSummary::class, 'admin-api/error-log/', 'error-log-24h'],
    [AddOns::class, 'admin-api/add-ons/', 'add-ons'],
    [CronEntries::class, 'admin-api/cron/', 'cron'],
    [JobQueue::class, 'admin-api/jobs/', 'jobs'],
    [WhoIsOnline::class, 'admin-api/online/', 'online'],
    [ForumLogs::class, 'admin-api/logs/', 'logs'],
    [ApprovalQueue::class, 'admin-api/approval-queue/', 'approval-queue'],
    [Reports::class, 'admin-api/reports/', 'reports'],
    [ForumActivity::class, 'admin-api/activity/', 'activity'],
    [UserUpgrades::class, 'admin-api/user-upgrades/', 'user-upgrades'],
    [ForumOptions::class, 'admin-api/options/', 'options-changed'],
    [ForumConfiguration::class, 'admin-api/config/', 'config-changed'],
]);

it('says whether cron is running', function () {
    fakeForum(['admin-api/cron/' => 'cron']);

    $content = structured(CronEntries::class, ['forum' => 'acme']);

    expect($content['headline'])->toMatch('/^The cron runner last ran \d+ seconds? ago; no entry is overdue\.$/')
        ->and($content['runner']['last_run_date_iso'])->toEndWith('Z')
        ->and($content['entries'][0]['next_run_iso'])->toEndWith('Z');
});

it('says so loudly when the cron runner is not queued', function () {
    $cron = captured('cron');
    $cron['runner']['queued'] = false;

    fakeForum(['admin-api/cron/' => Http::response($cron)]);

    expect(structured(CronEntries::class, ['forum' => 'acme'])['headline'])->toContain('NOT queued');
});

it('says what an empty failed-jobs list is worth', function (bool $recorded, string $expected) {
    $jobs = captured('jobs');
    $jobs['failed']['recorded'] = $recorded;

    fakeForum(['admin-api/jobs/' => Http::response($jobs)]);

    expect(implode(' ', structured(JobQueue::class, ['forum' => 'acme'])['notes']))->toContain($expected);
})->with([
    'recorded' => [true, 'records failed jobs now'],
    'not recorded, as on production' => [false, '`failed` being empty says nothing'],
]);

it('says a null attempt count is not recorded, as on XenForo 2.2', function () {
    // not captured: built from the add-on's description of its answer on a 2.2 forum
    $jobs = captured('jobs');
    $jobs['retrying'] = null;
    $jobs['by_class'] = array_map(fn (array $class): array => ['retrying' => null, 'max_attempts' => null] + $class, $jobs['by_class']);
    $jobs['failed'] = ['recorded' => false, 'total' => 0, 'oldest_date' => null, 'newest_date' => null, 'by_class' => [], 'signatures' => [], 'examined' => ['rows' => 0, 'complete' => true]];

    fakeForum(['admin-api/jobs/' => Http::response($jobs)]);

    $content = structured(JobQueue::class, ['forum' => 'acme']);

    expect($content['retrying'])->toBeNull()
        ->and(implode(' ', $content['notes']))->toContain('keeps no count of job attempts')->toContain('`failed` being empty says nothing');
});

it('names the add-ons that need a look', function () {
    $addOns = captured('add-ons');
    $addOns['add_ons'][0] = ['pending_action' => 'upgrade', 'is_processing' => false] + $addOns['add_ons'][0];
    $addOns['add_ons'][1] = ['can_upgrade' => true] + $addOns['add_ons'][1];

    fakeForum(['admin-api/add-ons/' => Http::response($addOns)]);

    $content = structured(AddOns::class, ['forum' => 'acme']);

    expect($content['attention']['unfinished_action'])->toBe([$addOns['add_ons'][0]['addon_id']])
        ->and($content['attention']['upgrade_not_run'])->toBe([$addOns['add_ons'][1]['addon_id']])
        ->and($content['xf']['version_string'])->toBe('2.3.12');
});

it('shows option values with each withheld part marked and explained', function () {
    fakeForum(['admin-api/options/' => 'options-values']);

    $content = structured(ForumOptions::class, ['forum' => 'acme']);
    $options = collect($content['options'])->keyBy('option_id');

    expect($content['headline'])->toBe(
        'Values are shown, with secrets removed: 4 of the 7 options listed have something withheld, each with its reason. '
        .'For 1 of those it is only that the add-on has not declared its secrets, so its text is hidden as a precaution.'
    )
        ->and($content['mode'])->toBe('values')
        ->and($options['boardTitle'])->toMatchArray(['disclosure' => 'shown', 'value' => 'Example Forum'])
        // where the mail goes is shown; the login is a secret whether or not there is one,
        // and "there is none" is said, not left to look like a hidden password
        ->and($options['emailTransport']['disclosure'])->toBe('partly_withheld')
        ->and($options['emailTransport']['value'])->toBe([
            'emailTransport' => 'smtp', 'smtpHost' => '127.0.0.1', 'smtpPort' => 1025, 'smtpAuth' => 'none',
            'smtpLoginUsername' => '[withheld: not set]', 'smtpLoginPassword' => '[withheld: not set]', 'smtpSsl' => false,
        ])
        // a secret that IS set gets the plain marker
        ->and($options['emailDkim']['value'])->toMatchArray(['enabled' => false, 'privateKey' => '[withheld]'])
        ->and($options['emailDkim']['redactions'][0])->toBe([
            'path' => 'privateKey', 'segments' => ['privateKey'], 'reason' => 'known_secret', 'set' => true,
            'because' => 'it is on the list of known secrets',
        ])
        // withheld whole: no value key to misread
        ->and($options['extraCaptchaKeys']['disclosure'])->toBe('withheld')
        ->and($options['extraCaptchaKeys'])->not->toHaveKey('value')
        ->and($options['extraCaptchaKeys'])->toMatchArray(['set' => true, 'count' => 2])
        // an add-on that has not declared its secrets: its text is hidden, its switches are not
        ->and($options['acmeEndpoint'])->not->toHaveKey('value')
        ->and($options['acmeEndpoint']['redactions'][0]['because'])->toContain('has not declared which of its settings are secrets')
        ->and($options['acmeExampleFlag'])->toMatchArray(['disclosure' => 'shown', 'value' => true]);
});

it('leaves a number inside an option value alone', function () {
    fakeForum(['admin-api/options/' => 'options-values']);

    $options = collect(structured(ForumOptions::class, ['forum' => 'acme'])['options'])->keyBy('option_id');

    expect($options['currentVersionId'])->not->toHaveKey('value_iso');
});

it('says a real null is a null, and why an undeclared add-on\'s text is hidden', function () {
    fakeForum(['admin-api/config/' => 'config-values']);

    $content = structured(ForumConfiguration::class, ['forum' => 'acme']);
    $settings = collect($content['settings'])->keyBy('key');

    expect($content['headline'])->toStartWith('Values are shown, with secrets removed: 4 of the 11 settings listed have something withheld, each with its reason. For 2 of those')
        ->and($settings['db.host'])->toMatchArray(['disclosure' => 'shown', 'value' => 'localhost'])
        ->and($settings['db.socket'])->toMatchArray(['disclosure' => 'shown', 'value' => null])
        ->and($settings['db.password'])->toMatchArray(['disclosure' => 'withheld', 'set' => true])
        ->and($settings['db.password'])->not->toHaveKey('value')
        ->and($settings['acmeService.endpoint']['disclosure'])->toBe('withheld')
        ->and($settings['acmeService.endpoint']['redactions'][0]['because'])->toContain('has not declared which of its settings are secrets');
});

it('keeps what is left of a URL, and says the rest was cut', function () {
    // not captured: the forum the fixtures came from had no option redacted this way
    $options = captured('options-values');
    $options['options'] = [[
        'value' => 'https://hooks.example.test', 'redactions' => [['path' => '', 'reason' => 'url']],
    ] + $options['options'][0]];

    fakeForum(['admin-api/options/' => Http::response($options)]);

    $option = structured(ForumOptions::class, ['forum' => 'acme'])['options'][0];

    expect($option)->toMatchArray(['disclosure' => 'partly_withheld', 'value' => 'https://hooks.example.test'])
        ->and($option['redactions'][0]['because'])->toContain('only its scheme and host are shown');
});

it('says so when the key is given no values, with the forum\'s own reason', function () {
    fakeForum(['admin-api/options/' => 'options-changed']);

    $content = structured(ForumOptions::class, ['forum' => 'acme']);
    $options = collect($content['options'])->keyBy('option_id');

    expect($content['headline'])->toStartWith('Values are not shown, only whether each is set, because the key does not hold the hampel_admin_api:values scope')
        ->and($options['boardTitle'])->toMatchArray(['disclosure' => 'withheld', 'set' => true])
        ->and($options['boardTitle'])->not->toHaveKey('value')->not->toHaveKey('redactions')
        ->and($options['acmeExampleFlag'])->toMatchArray(['disclosure' => 'shown', 'value' => true])
        ->and($options['emailTransport']['members']['emailTransport'])->toMatchArray(['disclosure' => 'shown', 'value' => 'smtp']);
});

it('asks a forum for options by id, and passes on what it has none by', function () {
    // the reply of an add-on that understands option_id: not captured, no released build has it
    $reply = ['not_found' => ['noSuchOption']] + captured('options-values');
    $reply['options'] = [$reply['options'][0]];

    fakeForum(['admin-api/options/' => Http::response($reply)]);

    $content = structured(ForumOptions::class, ['forum' => 'acme', 'option_ids' => ['boardTitle', 'noSuchOption'], 'add_on_id' => 'XF']);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $sent);

    // by id overrides the other arguments, so neither is sent
    expect($sent)->toEqual(['option_id' => 'boardTitle,noSuchOption'])
        ->and($content['headline'])->toEndWith('This forum has no option called noSuchOption.')
        ->and($content['listed'])->toBe('the options asked for by id, changed or not')
        ->and(array_column($content['options'], 'option_id'))->toBe(['boardTitle'])
        ->and($content['not_found'])->toBe(['noSuchOption']);
});

it('picks the options out itself when the forum\'s add-on ignores the id', function () {
    // an older add-on returns its whole list and no `not_found`: the captured fixture is exactly that
    fakeForum(['admin-api/options/' => 'options-values']);

    $content = structured(ForumOptions::class, ['forum' => 'acme', 'option_ids' => 'emailTransport, noSuchOption']);

    expect(array_column($content['options'], 'option_id'))->toBe(['emailTransport'])
        ->and($content['not_found'])->toBe(['noSuchOption'])
        ->and($content['headline'])->toContain('1 of the 1 options listed has something withheld')
        // these counted the whole forum, and would read as counting what was asked for
        ->and($content)->not->toHaveKey('total')->not->toHaveKey('by_add_on');
});

it('asks for configuration by key, matching a key and everything beneath it', function () {
    fakeForum(['admin-api/config/' => 'config-values']);

    $content = structured(ForumConfiguration::class, ['forum' => 'acme', 'keys' => ['db', 'debug', 'acme', 'nope']]);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $sent);

    expect($sent)->toEqual(['key' => 'db,debug,acme,nope'])
        ->and(array_column($content['settings'], 'key'))->toBe(['db.dbname', 'db.host', 'db.password', 'db.port', 'db.socket', 'db.username', 'debug'])
        // `acme` is not `acmeService` or `acmeLog`: a key matches itself or what sits under it
        ->and($content['not_found'])->toBe(['acme', 'nope'])
        ->and($content['headline'])->toEndWith('This forum has no setting called acme, nope.');
});

it('reads a trailing dot on a key the way the forum does, and ids by exact case', function () {
    fakeForum(['admin-api/config/' => 'config-values', 'admin-api/options/' => 'options-values']);

    expect(array_column(structured(ForumConfiguration::class, ['forum' => 'acme', 'keys' => ['db.']])['settings'], 'key'))
        ->toContain('db.host', 'db.port')
        ->and(structured(ForumOptions::class, ['forum' => 'acme', 'option_ids' => ['boardtitle']])['not_found'])->toBe(['boardtitle']);
});

it('lists only changed options unless asked for all of them', function (array $arguments, array $query, string $listed) {
    fakeForum(['admin-api/options/' => 'options-changed']);

    $content = structured(ForumOptions::class, ['forum' => 'acme'] + $arguments);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $sent);

    expect($sent)->toEqual($query)->and($content['listed'])->toBe($listed);
})->with([
    [[], ['changed' => '1'], 'changed options only'],
    [['include_unchanged' => true], [], 'every option'],
    [['add_on_id' => 'Acme/AddOnA'], ['changed' => '1', 'add_on_id' => 'Acme/AddOnA'], 'changed options only'],
]);

it('shows no string from config.php to a key without the values scope', function () {
    fakeForum(['admin-api/config/' => 'config-changed']);

    $settings = collect(structured(ForumConfiguration::class, ['forum' => 'acme'])['settings'])->keyBy('key');

    expect($settings['db.password'])->toMatchArray(['disclosure' => 'withheld', 'set' => true, 'type' => 'string'])
        ->and($settings['db.password'])->not->toHaveKey('value')
        ->and($settings['db.host'])->not->toHaveKey('value')
        ->and($settings['debug'])->toMatchArray(['disclosure' => 'shown', 'value' => true]);
});

it('says nobody is online when nobody is', function () {
    fakeForum(['admin-api/online/' => 'online']);

    $content = structured(WhoIsOnline::class, ['forum' => 'acme']);

    expect($content['headline'])->toBe('Nobody is online: no session in the last 15 minutes.')
        ->and($content['now_iso'])->toEndWith('Z')
        ->and(array_slice(array_keys($content), 0, 4))->toBe(['forum', 'times', 'headline', 'now']);
});

it('says how concentrated the guests are once there are enough to say', function () {
    // the captured forum was idle, so the figures are set here; the shape is the captured one
    $online = [
        'total' => 1840, 'members' => 37, 'guests' => 1512, 'robots' => 291, 'newest_date' => 1791453579,
        'guest_networks' => ['sessions' => 1512, 'ipv4' => 1490, 'largest_24' => 640, 'distinct_24' => 212, 'largest_16' => 911, 'distinct_16' => 87],
    ] + captured('online');

    fakeForum(['admin-api/online/' => Http::response($online)]);

    $content = structured(WhoIsOnline::class, ['forum' => 'acme']);

    expect($content['headline'])->toBe(
        '1840 online in the last 15 minutes: 37 members, 1512 guests, 291 recognised robots. '
        .'The busiest /24 network holds 640 of the 1490 IPv4 guests (43%), across 212 networks.'
    )->and($content['newest_date_iso'])->toBe('2026-10-08T09:59:39Z');
});

it('does not call one guest a swarm', function () {
    $online = ['total' => 3, 'guests' => 3, 'guest_networks' => ['sessions' => 3, 'ipv4' => 3, 'largest_24' => 1, 'distinct_24' => 3, 'largest_16' => 1, 'distinct_16' => 3]]
        + captured('online');

    fakeForum(['admin-api/online/' => Http::response($online)]);

    expect(structured(WhoIsOnline::class, ['forum' => 'acme'])['headline'])
        ->toBe('3 online in the last 15 minutes: 0 members, 3 guests, 0 recognised robots.');
});

it('passes on the remainder of the actions, which names no controller', function () {
    // built by hand from the add-on's description: no captured forum has more than 25 distinct actions
    $online = [
        'total' => 9, 'guests' => 9,
        'by_action' => [
            ['controller' => 'XF:Thread', 'action' => 'Index', 'count' => 6, 'members' => 0, 'guests' => 6, 'robots' => 0],
            ['controller' => null, 'action' => null, 'count' => 3, 'members' => 0, 'guests' => 3, 'robots' => 0],
        ],
    ] + captured('online');

    fakeForum(['admin-api/online/' => Http::response($online)]);

    $content = structured(WhoIsOnline::class, ['forum' => 'acme']);

    expect($content['by_action'])->toBe($online['by_action'])
        ->and(array_sum(array_column($content['by_action'], 'count')))->toBe($content['total']);
});

it('names the logs that recorded something, busiest first', function () {
    fakeForum(['admin-api/logs/' => 'logs']);

    $content = structured(ForumLogs::class, ['forum' => 'acme']);

    expect($content['headline'])->toBe('2 of 18 logs recorded something in this window: admin 29, server_error 11.')
        ->and($content['logs'][1])->toMatchArray(['log' => 'moderator', 'total' => 0, 'log_oldest_date' => 1752127630])
        ->and($content['logs'][1])->not->toHaveKey('oldest_date')
        ->and($content['logs'][0]['log_newest_date_iso'])->toEndWith('Z')
        ->and(sentRequests()[0]->url())->not->toContain('log=');
});

it('describes one log, and the whole of it when asked for all', function () {
    fakeForum(['admin-api/logs/' => 'logs-admin']);

    $content = structured(ForumLogs::class, ['forum' => 'acme', 'log' => ' Admin ', 'since' => 'all']);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe(['log' => 'admin', 'since' => '0'])
        ->and($content['headline'])->toStartWith('3061 entries in the admin log in this window; the newest is from 2026-10-')
        ->and($content['headline'])->toEndWith('2 distinct members acted.')
        ->and(implode(' ', $content['notes']))->not->toContain('because entries were removed')
        ->and(implode(' ', $content['notes']))->not->toContain('at most 366 days')
        ->and(array_slice(array_keys($content), 2, 4))->toBe(['headline', 'notes', 'log', 'window']);
});

it('says an empty window on a log that is not empty is not an empty log', function () {
    $log = ['window' => ['since' => 1792748575, 'until' => 1792834975, 'precedes_log' => false], 'total' => 0, 'oldest_date' => null, 'newest_date' => null]
        + captured('logs-moderator');

    fakeForum(['admin-api/logs/' => Http::response($log)]);

    expect(structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'moderator'])['headline'])
        ->toStartWith('Nothing in the moderator log in this window, but it holds 2 entries; the newest is from 2025-07-10');
});

it('says a log that is empty is empty, without counting members it does not have', function () {
    fakeForum(['admin-api/logs/' => 'logs-login-attempt']);

    expect(structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'login_attempt'])['headline'])
        ->toBe('The login_attempt log is empty.');
});

it('passes on the forum\'s list of logs when asked for one it does not keep', function () {
    fakeForum(['admin-api/logs/' => ['logs-unknown', 400]]);

    $response = XenForoServer::tool(ForumLogs::class, ['forum' => 'acme', 'log' => 'nope']);

    $response->assertHasErrors()->assertSee('spam_trigger');
});

it('counts the upgrades and says the amounts are list prices', function () {
    $upgrades = captured('user-upgrades');
    $upgrades['active'] = 3;
    $upgrades['payments'] = ['total' => 2, 'by_provider' => [['provider' => 'stripe', 'payments' => 2, 'newest_date' => 1792803483]], 'by_currency' => [['currency' => 'usd', 'payments' => 2, 'amount' => '100.00']]];

    fakeForum(['admin-api/user-upgrades/' => Http::response($upgrades)]);

    $content = structured(UserUpgrades::class, ['forum' => 'acme', 'days' => 500]);

    parse_str((string) parse_url(sentRequests()[0]->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe(['days' => '366'])
        ->and($content['headline'])->toBe('3 active records across 1 upgrade; 0 end in the next 30 days and 0 expired in the last 30. 2 payments reported in the last 30 days.')
        ->and(implode(' ', $content['notes']))->toContain('list price');
});

it('says a null cancelled count is not recorded, as on XenForo 2.2', function () {
    // not captured: the dev 2.2 forum's key lacks the permission; built from the add-on's description
    $upgrades = captured('user-upgrades');
    $upgrades['upgrades'][0]['cancelled'] = null;

    fakeForum(['admin-api/user-upgrades/' => Http::response($upgrades)]);

    expect(implode(' ', structured(UserUpgrades::class, ['forum' => 'acme'])['notes']))->toContain('It is not zero.');
});

it('says how much awaits approval and for how long', function () {
    fakeForum(['admin-api/approval-queue/' => 'approval-queue']);

    $content = structured(ApprovalQueue::class, ['forum' => 'acme']);

    expect($content['headline'])->toMatch('/^100 items are awaiting approval; the oldest was posted .+ ago\. 100 are older than a week\.$/')
        ->and($content['oldest_date_iso'])->toEndWith('Z');
});

it('measures the queue\'s age by the forum\'s clock when it sends one, from 1.0.0a10', function () {
    $queue = captured('approval-queue');
    $queue['now'] = (int) $queue['oldest_date'] + 3 * 86400;

    fakeForum(['admin-api/approval-queue/' => Http::response($queue)]);

    expect(structured(ApprovalQueue::class, ['forum' => 'acme'])['headline'])->toContain('the oldest was posted 3 days ago');
});

it('says when moderators are shown the wrong count', function () {
    $queue = ['total' => 0, 'oldest_date' => null, 'newest_date' => null, 'by_age' => ['under_hour' => 0, 'under_day' => 0, 'under_week' => 0, 'older' => 0], 'by_content_type' => []]
        + captured('approval-queue');

    fakeForum(['admin-api/approval-queue/' => Http::response($queue)]);

    expect(structured(ApprovalQueue::class, ['forum' => 'acme'])['headline'])
        ->toBe('Nothing is awaiting approval. Moderators are shown 100, which is wrong.');
});

it('says when by_day stops short of the window, and does not blame pruning for it', function () {
    // captured: an install log older than 366 days, asked for since "all"
    fakeForum(['admin-api/logs/' => 'logs-upgrade']);

    $content = structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'upgrade', 'since' => 'all']);
    $notes = implode(' ', $content['notes']);

    expect($notes)->toContain('`by_day` starts at 2025-10-24: the add-on reports at most 366 days. 2 of the 7 entries in this window are older than that and are counted')
        ->and($notes)->not->toContain('because entries were removed')
        ->and(array_column($content['by_day'], 'count'))->toBe([4, 1]);
});

it('says a window before the oldest entry may have been pruned', function () {
    $log = captured('logs-moderator');
    $log['window'] = ['since' => 1751000000, 'until' => 1752200000, 'precedes_log' => true];

    fakeForum(['admin-api/logs/' => Http::response($log)]);

    expect(implode(' ', structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'moderator', 'since' => '1751000000'])['notes']))
        ->toContain('because entries were removed');
});

it('lists only the days on which an upgrade ends or expired', function () {
    fakeForum(['admin-api/user-upgrades/' => 'user-upgrades']);

    $content = structured(UserUpgrades::class, ['forum' => 'acme']);

    expect($content['ending_by_day'])->toBe([])->and($content['expired_by_day'])->toBe([])
        ->and(implode(' ', $content['notes']))->toContain('An empty list means no record ends or expired');
});

it('says so when every entry in the window is older than by_day reaches', function () {
    // captured: both moderator log entries are more than 366 days old
    fakeForum(['admin-api/logs/' => 'logs-moderator']);

    $content = structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'moderator', 'since' => 'all']);

    expect(implode(' ', $content['notes']))->toContain('Both entries in this window are older than that and are counted')
        ->and($content['by_day'])->toBe([]);
});

it('passes on a 1.0.0a9 overview row, which already names the whole log', function () {
    fakeForum(['admin-api/logs/' => 'logs-a9']);

    expect(structured(ForumLogs::class, ['forum' => 'acme'])['logs'][1])
        ->toMatchArray(['log' => 'moderator', 'total' => 0, 'log_total' => 2, 'log_oldest_date' => 1752127630])
        ->not->toHaveKey('oldest_date');
});

it('reads a 1.0.0a10 overview, which no longer counts a whole log', function () {
    fakeForum(['admin-api/logs/' => 'logs-a10']);

    $content = structured(ForumLogs::class, ['forum' => 'acme']);

    expect($content['headline'])->toBe('2 of 18 logs recorded something in this window: admin 1, sitemap 1.')
        ->and($content['logs'][1])->toMatchArray(['log' => 'moderator', 'total' => 0, 'log_oldest_date' => 1752127630])
        ->and($content['logs'][1])->not->toHaveKey('log_total')
        ->and(implode(' ', $content['notes']))
        ->toContain('moderator, user_change, server_error, ip, upgrade: none in this window, but each holds older entries');
});

it('takes the forum\'s word that by_day was cut, from 1.0.0a9', function () {
    fakeForum(['admin-api/logs/' => 'logs-upgrade-a9']);

    expect(implode(' ', structured(ForumLogs::class, ['forum' => 'acme', 'log' => 'upgrade', 'since' => 'all'])['notes']))
        ->toContain('`by_day` starts at 2025-10-24');
});

it('says when the error log\'s by_day stops short of the window', function () {
    // by_day_complete set by hand: the captured log is younger than 366 days
    $log = ['by_day_complete' => false] + captured('error-log-all');

    fakeForum(['admin-api/error-log/' => Http::response($log)]);

    expect(implode(' ', structured(ErrorLogSummary::class, ['forum' => 'acme', 'since' => 'all'])['notes']))
        ->toContain('the add-on reports at most 366 days');
});

it('does not call a complete by_day cut', function () {
    fakeForum(['admin-api/error-log/' => 'error-log-all']);

    expect(implode(' ', structured(ErrorLogSummary::class, ['forum' => 'acme', 'since' => 'all'])['notes']))
        ->not->toContain('at most 366 days');
});

/*
 * reports.json is the add-on README's example with `now` added: the forum it was captured from has
 * no reports, and reports-empty.json is that capture.
 */

it('says how many reports wait and how long since anyone touched one', function () {
    fakeForum(['admin-api/reports/' => 'reports']);

    expect(structured(Reports::class, ['forum' => 'acme'])['headline'])
        ->toBe('6 reports are waiting (4 open, 2 assigned); the oldest was first reported 6 days ago, and the longest untouched was last acted on 3 days ago.');
});

it('says when moderators are shown the wrong report count', function () {
    fakeForum(['admin-api/reports/' => Http::response(['cached_total' => 2] + captured('reports'))]);

    expect(structured(Reports::class, ['forum' => 'acme'])['headline'])->toEndWith('Moderators are shown 2, which is wrong.');
});

it('says when no report is waiting', function () {
    fakeForum(['admin-api/reports/' => 'reports-empty']);

    expect(structured(Reports::class, ['forum' => 'acme'])['headline'])->toBe('No report is waiting for a moderator.');
});

it('counts registrations and posting by the state each is in now', function () {
    // captured: a 30-day window
    fakeForum(['admin-api/activity/' => 'activity']);

    $content = structured(ForumActivity::class, ['forum' => 'acme']);

    expect($content['headline'])->toBe('187 registrations in this window, 6 of them awaiting email confirmation and 7 awaiting approval; 15 threads and 93 posts, none awaiting approval or deleted.')
        ->and(implode(' ', $content['notes']))->toContain('in the state it is in now')->toContain('includes the opening post')
        ->not->toContain('`other`');
});

it('counts the threads and posts held or deleted', function () {
    $activity = captured('activity');
    $activity['posts']['by_state']['moderated'] = 1;
    $activity['threads']['by_state']['deleted'] = 2;

    fakeForum(['admin-api/activity/' => Http::response($activity)]);

    expect(structured(ForumActivity::class, ['forum' => 'acme'])['headline'])->toEndWith('15 threads and 93 posts, of which 1 is awaiting approval and 2 deleted.');
});

it('says when an add-on has given something a state XenForo does not define', function () {
    $activity = captured('activity');
    $activity['posts']['by_state']['other'] = 3;

    fakeForum(['admin-api/activity/' => Http::response($activity)]);

    expect(implode(' ', structured(ForumActivity::class, ['forum' => 'acme'])['notes']))->toContain('3 are in a state XenForo does not define');
});

it('says a quiet window is quiet', function () {
    $activity = captured('activity');

    foreach (['registrations', 'threads', 'posts'] as $part) {
        $activity[$part]['total'] = 0;
        $activity[$part]['by_state'] = array_map(fn () => 0, $activity[$part]['by_state']);
    }

    fakeForum(['admin-api/activity/' => Http::response($activity)]);

    expect(structured(ForumActivity::class, ['forum' => 'acme'])['headline'])->toBe('Nobody registered and nothing was posted in this window.');
});

it('sends the window it was given to the activity endpoint', function () {
    fakeForum(['admin-api/activity/' => 'activity']);

    structured(ForumActivity::class, ['forum' => 'acme', 'since' => '1792000000', 'until' => '1792086400']);

    expect(sentRequests()[0]->url())->toContain('since=1792000000')->toContain('until=1792086400');
});
