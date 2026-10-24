<?php

use App\Support\ForumInventory;
use App\Support\ForumInventoryException;

function inventoryFile(string $toml): ForumInventory
{
    $path = tempnam(sys_get_temp_dir(), 'xfmcp-test-');
    file_put_contents($path, $toml);

    config(['xfmcp.forums_path' => $path]);

    return new ForumInventory;
}

it('reads a forum with one key or two', function () {
    $forums = inventoryFile(<<<'TOML'
        [one]
        url = "https://one.example.test/"
        key = "aaa"

        [two]
        name = "Forum Two"
        url = "https://two.example.test"
        key = "bbb"
        detail_key = "ccc"
        TOML)->all();

    expect(array_keys($forums))->toBe(['one', 'two'])
        ->and($forums['one']->name)->toBe('one')
        ->and($forums['one']->url)->toBe('https://one.example.test')
        ->and($forums['one']->hasDetailKey())->toBeFalse()
        ->and($forums['two']->name)->toBe('Forum Two')
        ->and($forums['two']->detailKey)->toBe('ccc');
});

it('says what is wrong with a table', function (mixed $table, ?string $expected, string $id = 'acme') {
    $problem = (new ForumInventory)->problem($id, $table);

    $expected === null
        ? expect($problem)->toBeNull()
        : expect($problem)->toContain($expected);
})->with([
    'usable' => [['url' => 'https://f.example.test', 'key' => 'k'], null],
    'usable with a detail key' => [['url' => 'https://f.example.test', 'key' => 'k', 'detail_key' => 'd'], null],
    'no url' => [['key' => 'k'], '`url` is missing'],
    'no key' => [['url' => 'https://f.example.test'], '`key` is missing'],
    'an empty key' => [['url' => 'https://f.example.test', 'key' => ' '], '`key` is missing'],
    'a user, which makes a super user key' => [['url' => 'https://f.example.test', 'key' => 'k', 'user' => 1], 'super user key'],
    'an empty detail key' => [['url' => 'https://f.example.test', 'key' => 'k', 'detail_key' => ''], '`detail_key` is empty'],
    'not a table' => ['https://f.example.test', 'not a table'],
    'a capitalised name' => [['url' => 'https://f.example.test', 'key' => 'k'], 'lowercase', 'Acme'],
]);

it('leaves an unusable table out and keeps the rest', function () {
    useForums([
        'good' => ['url' => 'https://good.example.test', 'key' => 'k'],
        'bad' => ['url' => 'https://bad.example.test'],
    ]);

    expect(array_keys(app(ForumInventory::class)->all()))->toBe(['good']);
});

it('finds a forum whatever case it was asked for in', function () {
    expect(app(ForumInventory::class)->get(' ACME ')->id)->toBe('acme');
});

it('fails by name, never silently', function (Closure $make, string $expected) {
    expect(fn () => $make()->all())->toThrow(ForumInventoryException::class, $expected);
})->with([
    'a missing file' => [function () {
        config(['xfmcp.forums_path' => '/nonexistent/forums.toml']);

        return new ForumInventory;
    }, 'file not found'],
    'a file that is not TOML' => [fn () => inventoryFile('[acme'), 'parse error'],
]);

it('never quotes the file in a parse error, since any line of it may be a key', function () {
    // an unquoted value is the case the parser names in its own message
    $inventory = inventoryFile("[acme]\nurl = \"https://forum.example.test\"\nkey = SECRETKEYVALUE\n");

    try {
        $inventory->all();
        $this->fail('the file parsed');
    } catch (ForumInventoryException $e) {
        expect($e->getMessage())->toStartWith('parse error')
            ->not->toContain('SECRETKEYVALUE')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('sees a forum added to the file without being restarted', function () {
    $inventory = inventoryFile("[one]\nurl = \"https://one.example.test\"\nkey = \"aaa\"\n");

    expect(array_keys($inventory->all()))->toBe(['one']);

    file_put_contents($inventory->path(), "\n[two]\nurl = \"https://two.example.test\"\nkey = \"bbb\"\n", FILE_APPEND);

    expect(array_keys($inventory->all()))->toBe(['one', 'two']);
});

it('reports a file that has since been removed, instead of answering from memory', function () {
    $inventory = inventoryFile("[one]\nurl = \"https://one.example.test\"\nkey = \"aaa\"\n");
    $inventory->all();

    unlink($inventory->path());

    expect(fn () => $inventory->all())->toThrow(ForumInventoryException::class, 'file not found');
});

it('reads nothing until something asks', function () {
    config(['xfmcp.forums_path' => '/nonexistent/forums.toml']);
    app()->forgetInstance(ForumInventory::class);
    app()->singleton(ForumInventory::class);

    // a command that needs no forum must not fail on a missing inventory
    [$exit] = runCommand('list');

    expect($exit)->toBe(0);
});
