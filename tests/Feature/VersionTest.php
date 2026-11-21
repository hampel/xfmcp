<?php

use App\Support\Version;
use Symfony\Component\Process\Process;

function versionRepo(): string
{
    $dir = sys_get_temp_dir().'/xfmcp-version-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function git(string $dir, string ...$args): void
{
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$args], $dir))->mustRun();
}

function removeRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->mustRun();
}

it('is exactly the tag on a tagged commit, which the release job compares against', function () {
    $dir = versionRepo();
    git($dir, 'init', '-q');
    file_put_contents("{$dir}/a", '1');
    git($dir, 'add', 'a');
    git($dir, 'commit', '-q', '-m', 'one');
    git($dir, 'tag', '-a', '1.2.3', '-m', '1.2.3');

    expect(Version::describe($dir))->toBe('1.2.3');

    removeRepo($dir);
});

it('says how far past the tag a checkout is, and whether it is modified', function () {
    $dir = versionRepo();
    git($dir, 'init', '-q');
    file_put_contents("{$dir}/a", '1');
    git($dir, 'add', 'a');
    git($dir, 'commit', '-q', '-m', 'one');
    git($dir, 'tag', '-a', '1.2.3', '-m', '1.2.3');
    file_put_contents("{$dir}/a", '2');
    git($dir, 'commit', '-q', '-am', 'two');

    expect(Version::describe($dir))->toMatch('/^1\.2\.3-1-g[0-9a-f]+$/');

    file_put_contents("{$dir}/a", '3');

    expect(Version::describe($dir))->toMatch('/^1\.2\.3-1-g[0-9a-f]+-dirty$/');

    removeRepo($dir);
});

it('is unreleased where there is no tag or no repository', function () {
    $dir = versionRepo();

    expect(Version::describe($dir))->toBe('unreleased');

    git($dir, 'init', '-q');
    file_put_contents("{$dir}/a", '1');
    git($dir, 'add', 'a');
    git($dir, 'commit', '-q', '-m', 'one');

    expect(Version::describe($dir))->toBe('unreleased');

    removeRepo($dir);
});
