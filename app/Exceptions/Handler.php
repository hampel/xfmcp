<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * Keep unhandled exceptions off stdout, because under `mcp:start` stdout is JSON-RPC.
 *
 * Laravel's console handler renders a throwable to whatever OutputInterface it is handed,
 * and the kernel hands it the one the command writes to - so the framed error box lands on
 * stdout, where an MCP client expects one JSON message per line. A failure inside a tool
 * never gets here: the server catches it and answers with a JSON-RPC error. This is for
 * everything outside that, such as a server that fails to start.
 *
 * A BufferedOutput (what the tests use) has no error stream and is left alone.
 */
class Handler extends BaseHandler
{
    public function renderForConsole($output, Throwable $e)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        parent::renderForConsole($output, $e);
    }
}
