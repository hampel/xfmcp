<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOns;
use App\Mcp\Tools\CronEntries;
use App\Mcp\Tools\ErrorLogEntries;
use App\Mcp\Tools\ErrorLogSummary;
use App\Mcp\Tools\ForumConfiguration;
use App\Mcp\Tools\ForumOptions;
use App\Mcp\Tools\JobQueue;
use App\Mcp\Tools\ListForums;
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
            'id' => 'acme', 'name' => 'Acme Forum', 'url' => 'https://forum.example.test', 'error_detail' => true,
        ])
        ->and($content['forums'][1]['error_detail'])->toBeFalse()
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
