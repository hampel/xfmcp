<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOnValidate;

/*
 * Captured from the add-on's 1.0.0a16 working tree on 2026-11-10: the list, Hampel/AdminApi's
 * own checks (seven ran, `probe` skipped), and the refusals. Outcomes other than ok are set
 * by hand below, since that forum's checks all passed.
 */

/** @return array<mixed> */
function validateAnswer(array $arguments): array
{
    $content = [];

    XenForoServer::tool(AddOnValidate::class, ['forum' => 'acme'] + $arguments)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(function ($json) use (&$content) {
            $content = $json->toArray();
            $json->etc();
        });

    return $content;
}

it('lists the add-ons that declare checks, and how many run from here', function () {
    fakeForum(['admin-api/validate/' => 'validate-list']);

    $content = validateAnswer([]);

    expect($content['headline'])->toBe('One add-on declares checks: Hampel/AdminApi (7 of 8 checks run from here). Pass `addon_id` to run one\'s.')
        ->and(array_key_first($content))->toBe('forum')
        ->and($content['validators'][0]['addon_id'])->toBe('Hampel/AdminApi')
        ->and(sentRequests()[0]->url())->not->toContain('addon_id');
});

it('says a forum where no add-on declares checks has nothing to run, not a failure', function () {
    fakeForum(['admin-api/validate/' => Http::response(['now' => 1794287454, 'validators' => []])]);

    expect(validateAnswer([])['headline'])->toBe('No add-on on this forum declares checks to the Admin API, so there is nothing to run. That is not a failure: most add-ons declare none.');
});

it('runs one add-on\'s checks and says which ran, which passed and which were skipped', function () {
    fakeForum(['admin-api/validate/' => 'validate-adminapi']);

    $content = validateAnswer(['addon_id' => ' Hampel/AdminApi ']);

    expect($content['headline'])->toBe('Hampel/AdminApi 1.0.0a16: of 8 checks, 7 ran: all passed. 1 was skipped (probe), which is not a pass. Its validate command would exit 0, and 0 with --strict.')
        ->and($content['notes'][0])->toBe('`access` came out ok; 4 of its rows had nothing to check or were skipped.')
        ->and($content['notes'][1])->toContain('never what a check said')
        ->and($content['checks'])->toHaveCount(8)
        ->and(sentRequests()[0]->url())->toContain('addon_id=Hampel%2FAdminApi');
});

it('names the checks that failed and warned, before those that passed', function () {
    $answer = captured('validate-adminapi');
    $answer['checks'][1]['outcome'] = 'fail';
    $answer['checks'][1]['rows'] = ['ok' => 17, 'warn' => 0, 'fail' => 1, 'skipped' => 0];
    $answer['checks'][3]['outcome'] = 'warn';
    $answer['exit_code'] = 1;
    $answer['exit_code_strict'] = 1;

    fakeForum(['admin-api/validate/' => Http::response($answer)]);

    expect(validateAnswer(['addon_id' => 'Hampel/AdminApi'])['headline'])
        ->toBe('Hampel/AdminApi 1.0.0a16: of 8 checks, 7 ran: 1 FAILED (scopes), 1 warned (routes), 5 passed. 1 was skipped (probe), which is not a pass. Its validate command would exit 1, and 1 with --strict.');
});

it('says nothing ran, rather than that everything passed, when every check was skipped', function () {
    $answer = captured('validate-adminapi');
    $answer['checks'] = array_map(fn (array $check): array => ['outcome' => 'skipped'] + $check, $answer['checks']);

    fakeForum(['admin-api/validate/' => Http::response($answer)]);

    $headline = validateAnswer(['addon_id' => 'Hampel/AdminApi'])['headline'];

    expect($headline)->toBe('Hampel/AdminApi 1.0.0a16: none of its 8 checks ran here; all were skipped, which says nothing about whether they pass.')
        ->not->toContain('exit');
});

it('says when declared checks could not be reported', function () {
    $answer = captured('validate-adminapi');
    $answer['invalid_checks'] = 1;
    $answer['exit_code'] = 1;

    fakeForum(['admin-api/validate/' => Http::response($answer)]);

    expect(validateAnswer(['addon_id' => 'Hampel/AdminApi'])['headline'])
        ->toContain('1 declared check(s) could not be reported, which fails the run. Its validate command would exit 1');
});

it('explains each refusal of an add-on asked for', function (mixed $answer, array $expected) {
    fakeForum(['admin-api/validate/' => $answer]);

    XenForoServer::tool(AddOnValidate::class, ['forum' => 'acme', 'addon_id' => 'Acme/AddOnA'])->assertHasErrors($expected);
})->with([
    'not installed' => [['refused-addon-not-found', 404], ['has no such add-on installed (hampel_adminapi_addon_not_found)', 'No add-on with the id Nobody/Here is installed.', '(`XF`) is not one']],
    'declares no checks' => [['refused-validator-not-declared', 404], ['(hampel_adminapi_validator_not_declared)', 'The add-on Acme/AddOnA does not declare its checks', 'not a failure']],
    // not captured: no add-on on the dev forum is inactive, and none was switched off to get one.
    // Code, status and message as the add-on session quoted them on 2026-11-10.
    'not active' => [fn () => Http::response(['errors' => [[
        'code' => 'hampel_adminapi_addon_inactive',
        'message' => 'The add-on Acme/AddOnA is installed but not active, so it cannot declare its checks.',
        'params' => ['addon_id' => 'Acme/AddOnA'],
    ]]], 409), ['(hampel_adminapi_addon_inactive)', 'is installed but not active', 'No check ran']],
    'the key lacks the scope' => [fn () => Http::response(['errors' => [[
        'code' => 'missing_scope',
        'message' => 'This request requires access to the following scope: hampel_admin_api:validate',
        'params' => ['scope' => 'hampel_admin_api:validate'],
    ]]], 403), ['refused the key (missing_scope)', 'hampel_admin_api:validate']],
    'a forum on an older add-on' => [['endpoint-not-found', 404], ['the Hampel/AdminApi add-on is not installed', 'a version older than this endpoint']],
]);
