<?php

namespace App;

use LaravelZero\Framework\Kernel as BaseKernel;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A mistyped command must fail, not print the command list and exit 0.
 *
 * Laravel Zero proxies anything it cannot resolve to the default command, so that a
 * single-command application can be invoked with options alone. The cost is that
 * `xfmcp app:validte` finds no such command, silently becomes the summary and exits 0 -
 * indistinguishable, to anything reading the exit code, from a successful run.
 *
 * So the proxying is kept for a bare invocation, or one carrying only options, and a first
 * argument naming nothing is left to Symfony, which raises CommandNotFoundException and
 * exits 1.
 *
 * Rebound in bootstrap/app.php over the binding Application::configure() makes, or the
 * class sits here doing nothing.
 */
class Kernel extends BaseKernel
{
    /**
     * @param  InputInterface  $input
     */
    protected function ensureDefaultCommand($input): void
    {
        if ($input->getFirstArgument() === null) {
            parent::ensureDefaultCommand($input);
        }
    }
}
