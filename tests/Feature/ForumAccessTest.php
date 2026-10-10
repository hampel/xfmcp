<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\ForumAccess;

/**
 * One forum, answering by path and by which key was sent.
 *
 * @param  array<string, array<string, array{0: mixed, 1: int}>>  $answers  key => path => [fixture or body, status]
 */
function fakeAccess(array $answers): void
{
    Http::fake(function ($request) use ($answers) {
        $key = $request->header('XF-Api-Key')[0] ?? '';
        $path = trim(explode('/api/', (string) parse_url($request->url(), PHP_URL_PATH))[1] ?? '', '/');
        [$body, $status] = $answers[$key][$path] ?? ['endpoint-not-found', 404];

        return Http::response(is_string($body) ? captured($body) : $body, $status);
    });
}

function accessOf(string $forum): array
{
    $content = [];

    XenForoServer::tool(ForumAccess::class, ['forum' => $forum])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(function ($json) use (&$content) {
            $content = $json->toArray();
            $json->etc();
        });

    return $content;
}

const READ_OK = [
    'admin-api/add-ons' => ['add-ons', 200],
    'admin-api/options' => ['options-values', 200],
    'admin-api/config' => ['config-values', 200],
];

it('says everything a well set up forum will answer', function () {
    fakeAccess([READ_KEY => READ_OK, DETAIL_KEY => ['admin-api/error-entries' => ['refused-error-id-required', 400]]]);

    $content = accessOf('acme');

    expect($content['headline'])->toBe('Summaries can be read. Values are shown for options and configuration. Individual error log entries can be read.')
        ->and($content['summaries'])->toBe(['available' => true, 'xenforo' => '2.3.12'])
        ->and($content['values'])->toBe(['options' => ['available' => true], 'configuration' => ['available' => true]])
        ->and($content['error_entries'])->toBe(['configured' => true, 'available' => true]);
});

it('says a configured detail key is refused, with the forum\'s reason, before anyone relies on it', function () {
    fakeAccess([READ_KEY => READ_OK, DETAIL_KEY => ['admin-api/error-entries' => [
        ['errors' => [['code' => 'do_not_have_permission', 'message' => 'You do not have permission.', 'params' => []]]], 403,
    ]]]);

    $content = accessOf('acme');

    expect($content['headline'])->toEndWith('Individual error log entries CANNOT be read: a detail key is configured but the forum refuses it.')
        ->and($content['error_entries'])->toMatchArray(['configured' => true, 'available' => false])
        ->and($content['error_entries']['problem'])->toContain('does not hold the admin permission');
});

it('never names an entry, and never sends anything but a GET', function () {
    fakeAccess([READ_KEY => READ_OK, DETAIL_KEY => ['admin-api/error-entries' => ['refused-error-id-required', 400]]]);

    accessOf('acme');

    foreach (sentRequests() as $request) {
        expect($request->method())->toBe('GET')->and($request->url())->not->toContain('error_id');
    }
});

it('says which of options and configuration come without values, and why', function () {
    $shape = ['mode' => 'shape', 'mode_reason' => "the key's user does not hold the option admin permission"] + captured('options-changed');

    fakeAccess([READ_KEY => ['admin-api/options' => [$shape, 200]] + READ_OK, DETAIL_KEY => ['admin-api/error-entries' => ['refused-error-id-required', 400]]]);

    $content = accessOf('acme');

    expect($content['headline'])->toContain('Values are shown for configuration only.')
        ->and($content['values']['options'])->toBe([
            'available' => false,
            'problem' => "The forum answers without values because the key's user does not hold the option admin permission.",
        ]);
});

it('says a forum with no detail key offers summaries only, without contacting it about entries', function () {
    Http::fake(function ($request) {
        $path = trim(explode('/api/', (string) parse_url($request->url(), PHP_URL_PATH))[1] ?? '', '/');

        return Http::response(captured(['admin-api/add-ons' => 'add-ons', 'admin-api/options' => 'options-changed', 'admin-api/config' => 'config-changed'][$path]));
    });

    $content = accessOf('plain');

    expect($content['headline'])->toBe('Summaries can be read. Values are shown for neither options nor configuration. Individual error log entries cannot be read: no detail key is configured.')
        ->and($content['error_entries']['configured'])->toBeFalse()
        ->and(collect(sentRequests())->filter(fn ($request) => str_contains($request->url(), 'error-entries')))->toBeEmpty();
});

it('says a forum that cannot be read at all cannot be read at all', function () {
    Http::fake(fn () => Http::response('<html>Bad Gateway</html>', 502));

    expect(accessOf('acme')['headline'])->toStartWith('This forum cannot be read at all: Forum "acme" answered HTTP 502');
});
