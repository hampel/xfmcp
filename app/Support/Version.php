<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * The version this checkout is, as `git describe` puts it.
 *
 * Laravel Zero's own `git.version` asks for the nearest tag alone, so a checkout two commits
 * past a release called itself that release. Here a tagged commit is still exactly its tag,
 * which the release job compares against, and anything else says how far past it is and
 * whether the tree is modified. `app:build` compiles the answer into the binary.
 */
final class Version
{
    public static function describe(string $path): string
    {
        $process = new Process(['git', 'describe', '--tags', '--dirty'], $path);

        try {
            $process->run();
        } catch (\Throwable) {
            return 'unreleased';
        }

        return $process->isSuccessful() && trim($process->getOutput()) !== ''
            ? trim($process->getOutput())
            : 'unreleased';
    }
}
