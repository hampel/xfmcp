<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOns;
use App\Mcp\Tools\ApprovalQueue;
use App\Mcp\Tools\ErrorLogEntries;
use App\Mcp\Tools\ErrorLogSummary;
use App\Mcp\Tools\ForumActivity;
use App\Mcp\Tools\ForumLogs;
use App\Mcp\Tools\Reports;
use App\Mcp\Tools\UserUpgrades;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;

/*
 * Every refusal the add-on makes is a 403, a forum without it is a 404, and something in
 * front of the forum can answer either. Each of these has a different owner, so each must
 * reach the agent as a different sentence - and as a tool error, never as an empty result.
 */

it('explains a failure as a tool error naming whose problem it is', function (mixed $answer, string $expected) {
    fakeForum(['admin-api/add-ons/' => $answer]);

    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])->assertHasErrors([$expected]);
})->with([
    'the add-on is not installed' => [['endpoint-not-found', 404], 'the Hampel/AdminApi add-on is not installed'],
    'the key lacks the scope' => [['refused-detail-on-summary', 403], 'refused the key (missing_scope): `key` does not hold the API scope'],
    'the key is not a known key' => [['bad-key', 401], 'rejected the key (api_key_not_found)'],
    'a WAF answered, not XenForo' => [fn () => Http::response('<html>Attention Required</html>', 403), 'did not come from the XenForo API'],
    'the forum is failing' => [fn () => Http::response('<html>Bad Gateway</html>', 502), 'answered HTTP 502'],
    'a maintenance page on a 200' => [fn () => Http::response('<html>Back soon</html>', 200), 'is not JSON'],
    'the forum cannot be reached' => [fn () => throw new ConnectException('Could not resolve host', new Psr7Request('GET', 'https://forum.example.test')), 'could not be reached'],
]);

it('answers in a sentence even when this server is what failed', function () {
    fakeForum(['admin-api/add-ons/' => fn () => throw new LogicException('holds '.READ_KEY)]);

    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])
        ->assertHasErrors(['a fault in the MCP server', 'LogicException'])
        ->assertDontSee(READ_KEY);
});

it('names the two refusals the add-on phrases itself', function (string $code, string $expected) {
    fakeForum(['admin-api/add-ons/' => Http::response(
        ['errors' => [['code' => $code, 'message' => 'Refused.', 'params' => []]]],
        403,
    )]);

    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])->assertHasErrors([$expected]);
})->with([
    ['hampel_adminapi_user_api_key_required', 'only accepts a User key'],
    ['do_not_have_permission', 'does not hold the admin permission'],
]);

it('names the admin permission on every summary refused for it', function (string $tool, string $path) {
    // captured: a forum's refusal of the reports endpoint, whose permission the key's administrator lacked
    fakeForum([$path => ['refused-no-permission', 403]]);

    XenForoServer::tool($tool, ['forum' => 'acme'])->assertHasErrors(['"Use the Admin API"', 'the key is fine']);
})->with([
    [AddOns::class, 'admin-api/add-ons/'],
    [ForumLogs::class, 'admin-api/logs/'],
    [ApprovalQueue::class, 'admin-api/approval-queue/'],
    [UserUpgrades::class, 'admin-api/user-upgrades/'],
    [Reports::class, 'admin-api/reports/'],
    [ForumActivity::class, 'admin-api/activity/'],
]);

it('passes on the forum\'s refusal of a window longer than it allows', function () {
    fakeForum(['admin-api/activity/' => ['refused-window-too-long', 400]]);

    XenForoServer::tool(ForumActivity::class, ['forum' => 'acme', 'since' => '-60 days'])
        ->assertHasErrors(['The window may be at most 31 days long.', 'ask in consecutive windows', 'each `until` the next one\'s `since`', 'a window excludes its `until`'])
        ->assertDontSee('1.0.0a11');
});

it('says a key allowed all scopes must have them selected', function () {
    // not captured: code, params and message as the add-on session quoted them on 2026-11-06
    fakeForum(['admin-api/add-ons/' => Http::response(
        ['errors' => [['code' => 'hampel_adminapi_scope_must_be_selected', 'message' => 'This key allows all scopes, which does not include the Admin API\'s. Choose "Selected scopes only" and select hampel_admin_api:add_ons.', 'params' => ['scope' => 'hampel_admin_api:add_ons']]]],
        403,
    )]);

    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])
        ->assertHasErrors(['is allowed all scopes', 'select hampel_admin_api:add_ons', 'the key itself is fine']);
});

it('names the one admin permission', function () {
    fakeForum(['admin-api/reports/' => ['refused-no-permission', 403]]);

    XenForoServer::tool(Reports::class, ['forum' => 'acme'])
        ->assertHasErrors(['does not hold the admin permission it needs, "Use the Admin API". It is granted'])
        ->assertDontSee('View logs');
});

it('says which scope the forum asked for', function () {
    fakeForum(['admin-api/error-entries/' => ['refused-missing-scope', 403]]);

    XenForoServer::tool(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 1])
        ->assertHasErrors(['hampel_admin_api:error_detail', '`detail_key` does not hold']);
});

it('says an entry that is gone is gone, not that the forum is broken', function () {
    fakeForum(['admin-api/error-entries/' => ['entry-not-found', 404]]);

    XenForoServer::tool(ErrorLogEntries::class, ['forum' => 'acme', 'error_id' => 999999999])
        ->assertHasErrors(['has nothing at that id (requested_log_entry_not_found)']);
});

it('names the configured forums when asked for one that is not', function () {
    XenForoServer::tool(ErrorLogSummary::class, ['forum' => 'nowhere'])
        ->assertHasErrors(['no forum "nowhere" is configured. Configured forums: acme, plain']);

    expect(sentRequests())->toBe([]);
});

it('never puts a response body or a key into an explanation', function () {
    fakeForum(['admin-api/add-ons/' => Http::response('<html>secret-looking page body</html>', 403)]);

    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])
        ->assertDontSee(['secret-looking', READ_KEY, DETAIL_KEY]);
});
