<?php

use App\Commands\Validate;
use App\Kernel;
use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A forum whose two keys are exactly what they should be: each reaches its own endpoint
 * and is refused by the other's. Keyed on the API key sent, because that is the only thing
 * that differs between the two probes of one path.
 */
function fakeHealthyForum(array $override = []): void
{
    $answers = $override + [
        READ_KEY => [
            'index' => ['index-read-key', 200],
            'admin-api/add-ons' => ['add-ons', 200],
            'admin-api/error-entries' => ['refused-missing-scope', 403],
        ],
        DETAIL_KEY => [
            'index' => ['index-detail-key', 200],
            'admin-api/add-ons' => ['refused-detail-on-summary', 403],
            'admin-api/error-entries' => ['refused-error-id-required', 400],
        ],
        PLAIN_KEY => [
            'index' => ['index-read-key', 200],
            'admin-api/add-ons' => ['add-ons', 200],
            'admin-api/error-entries' => ['refused-missing-scope', 403],
        ],
    ];

    Http::fake(function ($request) use ($answers) {
        $key = $request->header('XF-Api-Key')[0] ?? '';
        $path = trim(explode('/api/', (string) parse_url($request->url(), PHP_URL_PATH))[1] ?? '', '/');
        $answer = $answers[$key][$path] ?? null;

        return match (true) {
            $answer === null => Http::response(captured('endpoint-not-found'), 404),
            is_string($answer[0]) && ! str_starts_with($answer[0], '<') => Http::response(captured($answer[0]), $answer[1]),
            default => Http::response($answer[0], $answer[1]),
        };
    });
}

it('passes a forum whose keys are each the right kind', function () {
    fakeHealthyForum();

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('[fail]')->not->toContain('[warn]')
        ->toContain('a user key holding 11 of the 11 summary scopes only')
        ->toContain('XenForo 2.3.12')
        ->toContain('refused by error entries (missing_scope)')
        ->toContain('a user key holding hampel_admin_api:error_detail only')
        ->toContain('reachable with the detail key')
        ->toContain('refused by a summary (missing_scope)')
        ->toContain('not configured; this forum offers summaries only');
});

it('only ever sends a GET, and names no entry', function () {
    fakeHealthyForum();

    runCommand('app:validate');

    foreach (sentRequests() as $request) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->not->toContain('error_id');
    }
});

it('warns about a read key that can read error messages, and still exits 0', function () {
    fakeHealthyForum([READ_KEY => [
        'index' => ['index-read-key', 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-error-id-required', 400],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('[warn]')->toContain('`key` can read error messages');
});

it('warns about a read key that holds the detail scope, whichever code refuses its user', function (string $code) {
    fakeHealthyForum([READ_KEY => [
        'index' => ['index-read-key', 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => [['errors' => [['code' => $code, 'message' => 'Refused.', 'params' => []]]], 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('`key` holds the detail scope');
})->with(['do_not_have_permission', 'hampel_adminapi_admin_permission_required']);

it('does not read the detail scope into a refusal of the key\'s user', function () {
    $refused = [['errors' => [['code' => 'do_not_have_permission', 'message' => 'You do not have permission.', 'params' => []]]], 403];

    fakeHealthyForum([READ_KEY => [
        'index' => ['index-read-key', 200],
        'admin-api/add-ons' => $refused,
        'admin-api/error-entries' => $refused,
    ]]);

    [, $output] = runCommand('app:validate');

    expect($output)->toContain('whether `key` holds the detail scope cannot be told')
        ->not->toContain('`key` holds the detail scope;');
});

it('exits 2 for warnings only under --strict, and 1 for a failure either way', function (array $override, bool $strict, int $expected) {
    fakeHealthyForum($override);

    [$exit] = runCommand('app:validate', $strict ? ['--strict' => true] : []);

    expect($exit)->toBe($expected);
})->with([
    'warnings only, no flag' => [[READ_KEY => ['index' => ['index-read-key', 200], 'admin-api/add-ons' => ['add-ons', 200], 'admin-api/error-entries' => ['refused-error-id-required', 400]]], false, 0],
    'warnings only, --strict' => [[READ_KEY => ['index' => ['index-read-key', 200], 'admin-api/add-ons' => ['add-ons', 200], 'admin-api/error-entries' => ['refused-error-id-required', 400]]], true, 2],
    'a failure, --strict' => [[READ_KEY => ['index' => ['bad-key', 401]]], true, 1],
    'all fine, --strict' => [[], true, 0],
]);

it('warns about a key holding more scopes than it needs', function () {
    $index = captured('index-read-key');
    $index['key']['scopes']['user:read'] = true;

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('holding 1 scope(s) it does not need: user:read');
});

it('treats the values scope on a read key as a choice, not an excess', function () {
    fakeHealthyForum([READ_KEY => [
        'index' => ['index-read-values-key', 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/options' => ['options-values', 200],
        'admin-api/config' => ['config-values', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('[warn]')
        ->toContain('a user key holding 11 of the 11 summary scopes and hampel_admin_api:values');
});

it('proves a key with the values scope is given values, and warns with the forum\'s reason when it is not', function () {
    $refused = ['mode' => 'shape', 'mode_reason' => "the key's user does not hold the hampelAdminApiValues admin permission"] + captured('config-changed');

    fakeHealthyForum([READ_KEY => [
        'index' => ['index-read-values-key', 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/options' => ['options-values', 200],
        'admin-api/config' => [$refused, 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)
        ->and($output)->toContain('shown, with secrets removed')
        ->toContain('not shown, although the key holds the scope: the key\'s user does not hold the hampelAdminApiValues admin permission');
});

it('says a read key without the values scope will show no values', function () {
    fakeHealthyForum();

    expect(runCommand('app:validate')[1])->toContain('options and configuration show no values');
});

it('warns about a detail key that also reads summaries', function () {
    fakeHealthyForum([DETAIL_KEY => [
        'index' => ['index-detail-key', 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-error-id-required', 400],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('`detail_key` also reads the summaries');
});

it('fails a key the add-on will refuse, by saying what kind it is', function (string $type, string $expected) {
    $index = captured('index-read-key');
    $index['key']['type'] = $type;

    fakeHealthyForum([READ_KEY => ['index' => [$index, 200]]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)->and($output)->toContain($expected);
})->with([
    ['super', 'a super key; the add-on only accepts a User key'],
    ['guest', 'a guest key; the add-on only accepts a User key'],
]);

it('fails a key without its scope, a forum without the add-on, and a forum that is down', function (array $read, string $expected) {
    fakeHealthyForum([READ_KEY => $read]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(1)->and($output)->toContain('[fail]')->toContain($expected);
})->with([
    'no scope' => [['index' => [['key' => ['type' => 'user', 'scopes' => []]], 200]], 'without a summary scope'],
    'no add-on' => [['index' => ['index-read-key', 200]], 'add-on is not installed'],
    'down' => [['index' => ['<html>Bad Gateway</html>', 502]], 'answered HTTP 502'],
    'unknown key' => [['index' => ['bad-key', 401]], 'rejected the key (api_key_not_found)'],
]);

it('reads scopes sent as a list as well as a map', function () {
    $index = captured('index-read-key');
    $index['key']['scopes'] = array_keys($index['key']['scopes']);

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    expect(runCommand('app:validate')[1])->toContain('a user key holding 11 of the 11 summary scopes only');
});

it('passes a read key holding some of the summary scopes, and names the rest', function () {
    $index = captured('index-read-key');
    $index['key']['scopes'] = ['hampel_admin_api:error_log' => true, 'hampel_admin_api:add_ons' => true, 'hampel_admin_api:jobs' => true];

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->not->toContain('[warn]')
        ->toContain('a user key holding 3 of the 11 summary scopes only; options and configuration show no values. Not selected, so refused: hampel_admin_api:logs');
});

it('does not fail a read key for leaving add-ons unselected', function () {
    // core's missing_scope for add-ons: refused-missing-scope.json is the same refusal for
    // error_detail, captured again from a forum on the add-on's 1.0.0a11 and identical
    $index = captured('index-read-key');
    $index['key']['scopes'] = ['hampel_admin_api:error_log' => true];

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => [['errors' => [['code' => 'missing_scope', 'message' => 'This request requires access to the following scope: hampel_admin_api:add_ons', 'params' => ['scope' => 'hampel_admin_api:add_ons']]]], 403],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('add-ons is not among the read key\'s scopes');
});

it('fails a key allowed all scopes, which reaches nothing', function () {
    $index = captured('index-read-key');
    $index['key']['allow_all_scopes'] = true;
    $index['key']['scopes'] = [];

    fakeHealthyForum([READ_KEY => ['index' => [$index, 200]]]);

    expect(runCommand('app:validate')[1])->toContain('[fail]')->toContain('which reaches none of the add-on\'s endpoints');
});

it('contacts nothing when offline, and says each forum was skipped rather than passed', function () {
    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('not contacted (--offline)')->toContain('2 usable: acme, plain')
        ->and(sentRequests())->toBe([]);
});

it('fails on an inventory it cannot read or a table it cannot use', function (mixed $forums, string $expected) {
    useForums($forums);

    [$exit, $output] = runCommand('app:validate', ['--offline' => true]);

    expect($exit)->toBe(1)->and($output)->toContain($expected);
})->with([
    'missing' => [new ForumInventoryException('file not found'), 'file not found'],
    'empty' => [[], 'the file lists no forum'],
    'a table with a user' => [['acme' => ['url' => 'https://f.example.test', 'key' => 'k', 'user' => 1]], 'super user key'],
]);

it('warns when the inventory is readable beyond its owner', function (int $mode, string $expected) {
    $path = tempnam(sys_get_temp_dir(), 'xfmcp-test-');
    file_put_contents($path, "[acme]\nurl = \"https://forum.example.test\"\nkey = \"k\"\n");
    chmod($path, $mode);

    config(['xfmcp.forums_path' => $path]);
    app()->instance(ForumInventory::class, new ForumInventory);

    expect(runCommand('app:validate', ['--offline' => true])[1])->toContain($expected);
})->with([
    [0644, '0644 - readable beyond its owner'],
    [0600, '[ ok ] File mode'],
]);

it('tells an absolute inventory path from a relative one, Windows forms included', function (string $path, bool $absolute) {
    expect(Validate::isAbsolute($path))->toBe($absolute);
})->with([
    ['/etc/xfmcp/forums.toml', true],
    ['C:\\Users\\acme\\xfmcp\\forums.toml', true],
    ['C:/Users/acme/xfmcp/forums.toml', true],
    ['\\\\server\\share\\forums.toml', true],
    ['storage/forums.toml', false],
    ['forums.toml', false],
    ['C:forums.toml', false],
]);

it('warns when a compiled binary is left to find its inventory by working directory', function () {
    config(['xfmcp.forums_path_set' => false]);
    app()->instance('xfmcp.storage.follows_cwd', true);

    expect(runCommand('app:validate', ['--offline' => true])[1])->toContain('follows the working directory');
});

it('shows the configuration without a key in it', function () {
    [$exit, $output] = runCommand('app:config');

    expect($exit)->toBe(0)
        ->and($output)->toContain('/nonexistent/forums.toml')
        ->toContain('2 forum(s)')
        ->toContain('acme, plain')
        ->toContain('mcp:start xenforo')
        ->not->toContain(READ_KEY)->not->toContain(DETAIL_KEY)->not->toContain(PLAIN_KEY);
});

it('never prints a key from app:validate either', function () {
    fakeHealthyForum();

    expect(runCommand('app:validate')[1])
        ->not->toContain(READ_KEY)->not->toContain(DETAIL_KEY)->not->toContain(PLAIN_KEY);
});

it('reports an unreadable inventory in app:config and still prints the rest', function () {
    useForums(new ForumInventoryException('file not found'));

    [$exit, $output] = runCommand('app:config');

    expect($exit)->toBe(0)->and($output)->toContain('file not found')->toContain('Forum Timeout');
});

it('exits non-zero for a command that does not exist', function () {
    // through Kernel::handle(), because artisan() never passes through the proxying
    $exit = app(Kernel::class)->handle(new ArrayInput(['command' => 'app:validte']), new BufferedOutput);

    expect($exit)->not->toBe(0);
});

it('removes the scheduler and the scaffolding commands that have no use here', function () {
    expect(array_keys(Artisan::all()))
        ->not->toContain('schedule:run')
        ->toContain('mcp:start');
});
