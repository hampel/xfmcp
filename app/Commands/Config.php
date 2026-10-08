<?php

namespace App\Commands;

use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Hampel\ConsoleReport\FormatsValues;
use Hampel\ConsoleReport\ReportsSettings;
use LaravelZero\Framework\Commands\Command;

/**
 * What this installation is configured to do.
 *
 * Rendered through hampel/console-report rather than $this->components->twoColumnDetail(),
 * which strips base_path() out of every value it is given. For a settings dump, which file
 * is being read is the question.
 */
class Config extends Command
{
    use FormatsValues;
    use ReportsSettings;

    protected $signature = 'app:config {--only= : The section to display}';

    protected $description = 'Show application configuration';

    public function handle(): int
    {
        $this->setReportOutput($this->getOutput());

        $this->reportSettings($this->settings(), $this->option('only'));

        return self::SUCCESS;
    }

    /**
     * The environment file that was read, or where one was looked for. bootstrap/app.php
     * records the outcome of its own search; environmentFilePath() would name a file
     * whether or not it exists.
     */
    protected function environmentFile(): string
    {
        $loaded = $this->laravel->bound('xfmcp.env.loaded') ? $this->laravel->make('xfmcp.env.loaded') : null;

        if ($loaded !== null) {
            return $this->path($loaded);
        }

        $candidates = $this->laravel->bound('xfmcp.env.candidates') ? $this->laravel->make('xfmcp.env.candidates') : [];

        return empty($candidates)
            ? $this->notSet()
            : '<fg=yellow>none found</> - looked in '.implode(', ', $candidates);
    }

    /**
     * @return array<string, array<string, string>> settings to report, by section
     */
    protected function settings(): array
    {
        // Ids only, never a table: every table carries a live API key, and this output is
        // what gets pasted into a ticket. A failure to read is reported in place so the
        // rest of the settings still print.
        $inventory = app(ForumInventory::class);
        $forums = [];
        $error = null;

        try {
            $forums = $inventory->all();
        } catch (ForumInventoryException $e) {
            $error = $e->getMessage();
        }

        $withDetail = array_keys(array_filter($forums, static fn ($forum): bool => $forum->hasDetailKey()));

        return [
            'Application' => [
                'Name' => config('app.name'),
                'Version' => $this->app->version(),
                'Laravel Version' => $this->app::VERSION,
                'PHP Version' => phpversion(),
                'Environment' => $this->laravel->environment(),
                'Environment File' => $this->environmentFile(),
                // an MCP client starts this process from a directory of its choosing, and
                // a compiled binary resolves its storage path from it
                'Working Directory' => $this->path(getcwd() ?: ''),
            ],

            'MCP' => [
                'Server' => 'xenforo (stdio only; nothing listens on a port)',
                'Start Command' => config('app.name').' mcp:start xenforo',
                'Display Timezone' => (string) config('xfmcp.timezone'),
            ],

            'Forums' => [
                'Inventory Path' => $this->path($inventory->path()),
                'Forums Loaded' => $error !== null
                    ? '<fg=red>'.$error.'</>'
                    : count($forums).' forum(s)',
                'Forums' => $this->optional(implode(', ', array_keys($forums))),
                'With Error Detail' => $this->optional(implode(', ', $withDetail)),
                'Forum Timeout' => config('xenforo.timeout').'s',
                'Forum Connect Timeout' => config('xenforo.connect_timeout').'s',
            ],
        ];
    }
}
