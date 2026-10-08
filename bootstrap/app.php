<?php

use App\Exceptions\Handler as ExceptionHandler;
use App\Kernel;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

/*
 * Rebind the console kernel over the one Application::configure() just bound, so an
 * unrecognised command name fails instead of being proxied to the summary and exiting 0.
 * App\Kernel says why.
 */
$app->singleton(Illuminate\Contracts\Console\Kernel::class, Kernel::class);

/*
 * Rebind the exception handler for the same reason hand-written output never goes to
 * stdout here: under `mcp:start` stdout is the JSON-RPC channel. App\Exceptions\Handler
 * says why.
 */
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, ExceptionHandler::class);

/*
 * Where the environment file lives. First of these that exists wins:
 *
 *   1. a .env beside the binary
 *   2. XFMCP_ENV, naming the file itself
 *   3. the project's own .env, when running from a source checkout
 *   4. /etc/xfmcp/.env
 *
 * It is loaded here rather than left to the framework because the storage path below is
 * resolved before the application boots.
 */
$phar = Phar::running(false);

$candidates = array_filter([
    $phar ? dirname($phar).DIRECTORY_SEPARATOR.'.env' : null,
    getenv('XFMCP_ENV') ?: null,
    $phar ? null : $app->basePath('.env'),
    DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'xfmcp'.DIRECTORY_SEPARATOR.'.env',
]);

$loaded = null;

foreach ($candidates as $envFile) {
    if (is_file($envFile)) {
        $app->useEnvironmentPath(dirname($envFile));
        $app->loadEnvironmentFrom(basename($envFile));

        try {
            Dotenv::createMutable(dirname($envFile), basename($envFile))->safeLoad();
        } catch (InvalidFileException $e) {
            // safeLoad() swallows a missing file, not a malformed one. This runs before the
            // kernel or the handler exist, so nothing else can keep the trace off stdout.
            fwrite(STDERR, 'xfmcp: cannot read environment file '.$envFile.' - '.$e->getMessage().PHP_EOL);

            exit(1);
        }

        $loaded = $envFile;

        break;
    }
}

/*
 * Recorded so app:config can report the file that was actually read, and say where it
 * looked when there was none. environmentFilePath() answers base_path().'/.env' whether or
 * not anything is there, which inside a phar names a file that has never been opened.
 */
$app->instance('xfmcp.env.loaded', $loaded);
$app->instance('xfmcp.env.candidates', array_values($candidates));

if ($phar) {
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

/*
 * Whether the storage path follows the working directory - true only for a compiled binary
 * with no LARAVEL_STORAGE_PATH. An MCP client starts this process from a directory of its
 * own choosing, so a defaulted inventory path is then a different file per client.
 */
$app->instance('xfmcp.storage.follows_cwd', $phar !== '' && env('LARAVEL_STORAGE_PATH') === null);

return $app;
