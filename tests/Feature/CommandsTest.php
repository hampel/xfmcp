<?php

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
        ->toContain('a user key holding hampel_admin_api:read only')
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

it('warns about a key holding more scopes than it needs', function () {
    $index = captured('index-read-key');
    $index['key']['scopes']['user:read'] = true;

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    [$exit, $output] = runCommand('app:validate');

    expect($exit)->toBe(0)->and($output)->toContain('holding 1 scope(s) beyond hampel_admin_api:read: user:read');
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
    'no scope' => [['index' => [['key' => ['type' => 'user', 'scopes' => []]], 200]], 'without the hampel_admin_api:read scope'],
    'no add-on' => [['index' => ['index-read-key', 200]], 'add-on is not installed'],
    'down' => [['index' => ['<html>Bad Gateway</html>', 502]], 'answered HTTP 502'],
    'unknown key' => [['index' => ['bad-key', 401]], 'rejected the key (api_key_not_found)'],
]);

it('reads scopes sent as a list as well as a map', function () {
    $index = captured('index-read-key');
    $index['key']['scopes'] = ['hampel_admin_api:read'];

    fakeHealthyForum([READ_KEY => [
        'index' => [$index, 200],
        'admin-api/add-ons' => ['add-ons', 200],
        'admin-api/error-entries' => ['refused-missing-scope', 403],
    ]]);

    expect(runCommand('app:validate')[1])->toContain('a user key holding hampel_admin_api:read only');
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
