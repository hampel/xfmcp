<?php

namespace App\Commands;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use DateTimeZone;
use Hampel\ConsoleReport\RendersChecks;
use Hampel\XenForo\Api\Exception\ApiException;
use Hampel\XenForo\Api\Exception\XenForoException;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Prove that this machine can do what the configuration says.
 *
 * The suite proves what holds in process; this proves what holds here: that the inventory
 * can be read by this user and by nobody else, that each forum answers, and - the one that
 * is silent otherwise - that each key is the kind it is meant to be. A read key that can
 * also read error messages works perfectly and defeats the reason there are two.
 *
 * Nothing here posts, logs or writes. Every forum probe is a GET, and the two aimed at the
 * error entries endpoint name no entry, so they can only be refused and return no content.
 *
 * A new dependency on the environment gets its check here.
 */
class Validate extends Command
{
    use RendersChecks;

    protected $signature = 'app:validate {--offline : Skip the checks that contact a forum}';

    protected $description = 'Check the configuration, the inventory and each forum\'s keys';

    public function handle(ForumInventory $inventory, ForumGateway $gateway): int
    {
        $this->setReportOutput($this->getOutput());

        $this->checkSection('Runtime');
        $this->checkRuntime();

        $this->checkSection('Forum inventory');
        $forums = $this->checkInventory($inventory);

        foreach ($forums as $forum) {
            $this->checkSection('Forum: '.$forum->id);

            if ($this->option('offline')) {
                $this->checkSkip('API', 'not contacted (--offline)');

                continue;
            }

            $this->checkForum($gateway, $forum);
        }

        return $this->checkExitCode();
    }

    protected function checkRuntime(): void
    {
        $this->checkOk('PHP version', PHP_VERSION);

        $loaded = $this->laravel->bound('xfmcp.env.loaded') ? $this->laravel->make('xfmcp.env.loaded') : null;

        // no environment file is a working configuration: every setting has a default
        $loaded !== null
            ? $this->checkOk('Environment file', $loaded)
            : $this->checkSkip('Environment file', 'none found; running on defaults');

        $timezone = (string) config('xfmcp.timezone');

        try {
            new DateTimeZone($timezone);
            $this->checkOk('Display timezone', $timezone);
        } catch (Throwable) {
            $this->checkFail('Display timezone', $timezone.' is not a timezone PHP knows; answers fall back to UTC');
        }
    }

    /**
     * @return array<string, Forum> the forums that are usable
     */
    protected function checkInventory(ForumInventory $inventory): array
    {
        $path = $inventory->path();

        if (! str_starts_with($path, '/')) {
            $this->checkFail('Path', $path.' is relative; it resolves against whatever directory the MCP client starts in');
        } elseif ($this->followsWorkingDirectory()) {
            $this->checkWarn('Path', $path.' follows the working directory; set XFMCP_FORUMS_PATH absolutely');
        } else {
            $this->checkOk('Path', $path);
        }

        try {
            $raw = $inventory->raw();
        } catch (ForumInventoryException $e) {
            $this->checkFail('Readable', $e->getMessage());

            return [];
        }

        $this->checkOk('Readable', 'parsed');

        // the file is a set of live keys; anyone who can read it can read every forum
        $mode = @fileperms($path);

        if ($mode === false) {
            $this->checkSkip('File mode', 'could not be read');
        } elseif (($mode & 0077) !== 0) {
            $this->checkWarn('File mode', sprintf('%04o - readable beyond its owner; it holds API keys, make it 0600', $mode & 0777));
        } else {
            $this->checkOk('File mode', sprintf('%04o', $mode & 0777));
        }

        if ($raw === []) {
            $this->checkFail('Forums', 'the file lists no forum');

            return [];
        }

        foreach ($raw as $id => $table) {
            $problem = $inventory->problem((string) $id, $table);

            if ($problem !== null) {
                $this->checkFail('Table ['.$id.']', $problem);
            }
        }

        $forums = $inventory->all();

        $forums === []
            ? $this->checkFail('Forums', 'no table is usable')
            : $this->checkOk('Forums', count($forums).' usable: '.implode(', ', array_keys($forums)));

        return $forums;
    }

    protected function checkForum(ForumGateway $gateway, Forum $forum): void
    {
        if (! $this->checkKeyKind($gateway, $forum, $forum->key, 'key', ForumGateway::SCOPE_READ)) {
            // nothing below can say anything useful about a key the forum did not accept
            return;
        }

        $api = $gateway->client($forum, $forum->key)->endpoint(AdminApi::class);

        try {
            $addOns = $api->addOns();
            $this->checkOk('Summaries', $this->describeForum($addOns));
        } catch (XenForoException $e) {
            $this->checkFail('Summaries', $gateway->explain($forum, 'key', $e));
        }

        // The read key must NOT reach the content endpoint. No entry is named, so the only
        // possible outcomes are the three refusals below; nothing is ever returned.
        try {
            $api->errorEntriesProbe();
            $this->checkWarn('Read key reach', 'the error entries endpoint answered a request naming no entry; expected a refusal');
        } catch (ApiException $e) {
            match (true) {
                $e->hasCode('missing_scope') => $this->checkOk('Read key reach', 'refused by error entries (missing_scope)'),
                $e->hasCode(ForumGateway::CODE_ERROR_ID_REQUIRED) => $this->checkWarn(
                    'Read key reach',
                    '`key` can read error messages: it holds the detail scope and its user the permission. Use a second key for that'
                ),
                $e->hasCode('do_not_have_permission') => $this->checkWarn(
                    'Read key reach',
                    '`key` holds the detail scope; only its user\'s admin permissions stop it reading error messages'
                ),
                default => $this->checkFail('Read key reach', $gateway->explain($forum, 'key', $e)),
            };
        } catch (XenForoException $e) {
            $this->checkFail('Read key reach', $gateway->explain($forum, 'key', $e));
        }

        if ($forum->detailKey === null) {
            $this->checkSkip('Detail key', 'not configured; this forum offers summaries only');

            return;
        }

        if (! $this->checkKeyKind($gateway, $forum, $forum->detailKey, 'detail_key', ForumGateway::SCOPE_DETAIL)) {
            return;
        }

        $api = $gateway->client($forum, $forum->detailKey)->endpoint(AdminApi::class);

        try {
            $api->errorEntriesProbe();
            $this->checkWarn('Error entries', 'answered a request naming no entry; expected a 400');
        } catch (ApiException $e) {
            $e->hasCode(ForumGateway::CODE_ERROR_ID_REQUIRED)
                ? $this->checkOk('Error entries', 'reachable with the detail key')
                : $this->checkFail('Error entries', $gateway->explain($forum, 'detail_key', $e));
        } catch (XenForoException $e) {
            $this->checkFail('Error entries', $gateway->explain($forum, 'detail_key', $e));
        }

        // and the reverse: the detail key should be good for nothing else
        try {
            $api->addOns();
            $this->checkWarn('Detail key reach', '`detail_key` also reads the summaries: it holds both scopes');
        } catch (ApiException $e) {
            $e->hasCode('missing_scope')
                ? $this->checkOk('Detail key reach', 'refused by a summary (missing_scope)')
                : $this->checkFail('Detail key reach', $gateway->explain($forum, 'detail_key', $e));
        } catch (XenForoException $e) {
            $this->checkFail('Detail key reach', $gateway->explain($forum, 'detail_key', $e));
        }
    }

    /**
     * What the forum itself says about a key: its type, and the scopes it holds.
     *
     * A super user key passes XenForo's own checks and is refused by every endpoint of the
     * add-on, so it is caught here by name instead of as seven identical 403s.
     *
     * @return bool whether the key is one the add-on will accept
     */
    protected function checkKeyKind(ForumGateway $gateway, Forum $forum, #[\SensitiveParameter] string $key, string $which, string $scope): bool
    {
        $label = $which === 'key' ? 'Read key' : 'Detail key';

        try {
            $info = $gateway->client($forum, $key)->index()->get();
        } catch (XenForoException $e) {
            $this->checkFail($label, $gateway->explain($forum, $which, $e));

            return false;
        }

        if ($info->keyType !== 'user') {
            $this->checkFail($label, sprintf(
                'a %s key; the add-on only accepts a User key belonging to one administrator',
                $info->keyType ?? 'unknown',
            ));

            return false;
        }

        $scopes = $this->scopes($info->raw);

        if (! $info->allowAllScopes && ! in_array($scope, $scopes, true)) {
            $this->checkFail($label, 'a user key without the '.$scope.' scope');

            return false;
        }

        $extra = array_values(array_diff($scopes, [$scope]));

        if ($info->allowAllScopes) {
            $this->checkWarn($label, 'a user key allowed ALL scopes; give it '.$scope.' and nothing else');
        } elseif ($extra !== []) {
            $this->checkWarn($label, sprintf('a user key holding %d scope(s) beyond %s: %s', count($extra), $scope, implode(', ', $extra)));
        } else {
            $this->checkOk($label, 'a user key holding '.$scope.' only');
        }

        return true;
    }

    /**
     * The scopes a key holds, read from the index response itself.
     *
     * XenForo 2.3 sends them as a map of scope to true, where SiteInfo expects a list of
     * names and so reports none; measured against XenForo 2.3.12. Both shapes are read
     * here so that neither a fixed package nor another XenForo version changes the answer.
     *
     * @param  array<mixed>  $index
     * @return list<string>
     */
    protected function scopes(array $index): array
    {
        $scopes = [];

        foreach ((array) ($index['key']['scopes'] ?? []) as $name => $value) {
            if (is_string($name) && $value) {
                $scopes[] = $name;
            } elseif (is_string($value)) {
                $scopes[] = $value;
            }
        }

        return $scopes;
    }

    /**
     * @param  array<mixed>  $addOns  the add-on inventory
     */
    protected function describeForum(array $addOns): string
    {
        $version = 'unknown version';

        foreach ($addOns['add_ons'] ?? [] as $addOn) {
            if (is_array($addOn) && ($addOn['addon_id'] ?? null) === 'Hampel/AdminApi') {
                $version = (string) ($addOn['version_string'] ?? $version);
            }
        }

        return sprintf(
            'XenForo %s, PHP %s, Hampel/AdminApi %s',
            $addOns['xf']['version_string'] ?? 'unknown',
            $addOns['php_version'] ?? 'unknown',
            $version,
        );
    }

    /**
     * Asked of the container, never inferred from the path: run from `/`, every absolute
     * path starts with the storage path.
     */
    protected function followsWorkingDirectory(): bool
    {
        return config('xfmcp.forums_path_set') !== true
            && $this->laravel->bound('xfmcp.storage.follows_cwd')
            && $this->laravel->make('xfmcp.storage.follows_cwd') === true;
    }
}
