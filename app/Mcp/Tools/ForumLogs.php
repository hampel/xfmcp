<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Count what a forum's logs recorded: the admin and moderator logs, spam, bounced mail, failed logins, the
    proxies, payment callbacks and the rest of the 18 logs XenForo keeps. Counts only - never an entry, a name,
    an address, a reason or a URL.

    Without `log`, one line per log: `total` is how many entries fall in the window, and `log_oldest_date` and
    `log_newest_date` describe the whole log, whatever the window. A row with `total: 0` and a `log_newest_date`
    is a log that holds entries, none of them in the window; both dates null is an empty log. The overview does
    not count a whole log. With `log`, that one log in full: `total`, `oldest_date` and `newest_date` describe
    the window and `log_total`, `log_oldest_date` and `log_newest_date` the whole log, then `members` (how many
    distinct members acted, never who), its breakdowns, and `by_day`. Read `headline` first.

    `log` is one of: admin, moderator, user_change, username_change, cookie_consent, spam_trigger, spam_cleaner,
    rejected_user, email_bounce, image_proxy, link_proxy, oembed, payment_provider, sitemap, server_error, ip,
    login_attempt, upgrade. `upgrade` is XenForo's own install and upgrade log, not members' user upgrades - ask
    user-upgrades for those. For the server error log, error-log-summary says far more.

    Easy to misread:
    - The default window is the last 24 hours, so a quiet day reads as zero. `since: "all"` covers the whole log.
    - A long window on a large log is slow: the forum reads every row in it, and on the `ip` log that can take many
      seconds. To learn how big a log is, do not widen the window - ask with `log`, whose `log_total` is the whole
      log's size whatever the window.
    - `log_total`, `log_oldest_date` and `log_newest_date` describe the whole log, whatever the window. Most logs
      are pruned by an option, so `window.precedes_log: true` means the earliest days may read as zero because
      they were pruned, not because nothing happened.
    - `by_day` lists only days with entries, in UTC, and reaches back at most 366 days: on a longer window, `notes`
      says from when, and how many entries fall before it. `by_day_complete: false` is the forum saying the same.
    - In any breakdown, an entry whose name is null is everything else counted together: a value the forum no
      longer defines, and whatever falls past the busiest 500. It is not an unknown item.
    - An admin log `section` is the first part of a control panel route: `index` is the front page, `login` a
      control panel login, `api` this add-on's member lookups.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumLogs extends ForumTool
{
    private const ORDER = [
        'headline', 'notes', 'log', 'window', 'total', 'oldest_date', 'newest_date', 'log_total', 'log_oldest_date',
        'log_newest_date', 'members', 'guest_entries', 'logs',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'log' => $schema->string()->description('One log to describe in full, named as in the description. Omit for one line per log.'),
            'since' => $schema->string()->description(
                'Start of the window: ISO 8601, Unix seconds, a relative time such as "-7 days", or "all" for the '
                .'whole log. A time without a zone is UTC. Defaults to 24 hours before `until`.'
            ),
            'until' => $schema->string()->description(
                'End of the window, in the same forms. Defaults to now. An entry at exactly `until` is not '
                .'counted, so it can be the next window\'s `since`.'
            ),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('The answer in one sentence.')->required(),
            'notes' => $schema->array()->description('Cautions that apply to this particular answer.'),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $since = $request->get('since');
        $log = $request->get('log');

        $query = array_filter([
            'log' => is_string($log) && trim($log) !== '' ? strtolower(trim($log)) : null,
            'since' => is_string($since) && strtolower(trim($since)) === 'all' ? 0 : $this->instant($since, 'since'),
            'until' => $this->instant($request->get('until'), 'until'),
        ], static fn ($value): bool => $value !== null);

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->logs($query));

        if (isset($data['logs'])) {
            $data['headline'] = $this->overview($data);
        } else {
            $data['headline'] = $this->single($data);
        }

        $data['notes'] = $this->notes($data);

        if (is_array($data['by_day'] ?? null)) {
            $data['by_day'] = Present::busyDays($data['by_day']);
        }

        return Present::lead($data, self::ORDER);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function overview(array $data): string
    {
        $busy = [];

        foreach ((array) $data['logs'] as $row) {
            if (is_array($row) && (int) ($row['total'] ?? 0) > 0) {
                $busy[(string) ($row['log'] ?? '?')] = (int) $row['total'];
            }
        }

        if ($busy === []) {
            return sprintf('No log recorded anything in this window, across %d logs.', count((array) $data['logs']));
        }

        arsort($busy);

        return sprintf(
            '%d of %d logs recorded something in this window: %s.',
            count($busy),
            count((array) $data['logs']),
            implode(', ', array_map(static fn (string $log, int $total): string => "{$log} {$total}", array_keys($busy), $busy)),
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private function single(array $data): string
    {
        $log = (string) ($data['log'] ?? 'this');
        $total = (int) ($data['total'] ?? 0);
        $logTotal = (int) ($data['log_total'] ?? 0);
        $now = (int) ($data['now'] ?? time());

        if ($logTotal === 0) {
            return sprintf('The %s log is empty.', $log);
        }

        if ($total === 0) {
            $newest = (int) ($data['log_newest_date'] ?? 0);

            return sprintf(
                'Nothing in the %s log in this window, but it holds %d %s; the newest is from %s (%s).',
                $log,
                $logTotal,
                $logTotal === 1 ? 'entry' : 'entries',
                Present::iso($newest),
                Present::distance($newest, $now),
            );
        }

        $newest = (int) ($data['newest_date'] ?? 0);
        $headline = sprintf(
            '%d %s in the %s log in this window; the newest is from %s (%s).',
            $total,
            $total === 1 ? 'entry' : 'entries',
            $log,
            Present::iso($newest),
            Present::distance($newest, $now),
        );

        if (isset($data['members'])) {
            $members = (int) $data['members'];
            $headline .= sprintf(' %d distinct %s acted.', $members, $members === 1 ? 'member' : 'members');
        }

        return $headline;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function notes(array $data): array
    {
        $notes = [];

        // a window from 0 always precedes the log, and says nothing about pruning
        if (($data['window']['precedes_log'] ?? false) === true && (int) ($data['window']['since'] ?? 0) > 0) {
            $notes[] = 'The window starts before the oldest entry the log still holds. Most logs are pruned, so its '
                .'earliest days may read as zero because entries were removed, not because nothing happened then.';
        }

        $days = is_array($data['by_day'] ?? null) ? $data['by_day'] : [];

        if (($cut = Present::byDayCut($data)) !== null) {
            $notes[] = $cut;
        }

        if ($days !== []) {
            $notes[] = '`by_day` lists only days with entries; a day not listed had none.';
        }

        if (isset($data['logs'])) {
            $held = array_filter((array) $data['logs'], static fn (mixed $row): bool => is_array($row)
                && (int) ($row['total'] ?? 0) === 0 && ($row['log_newest_date'] ?? null) !== null);

            if ($held !== []) {
                $notes[] = sprintf(
                    '%s: none in this window, but each holds older entries. Ask with `log` for how many.',
                    implode(', ', array_column($held, 'log')),
                );
            }
        }

        if (($data['log'] ?? null) === 'server_error') {
            $notes[] = 'error-log-summary describes the server error log in far more detail: by origin, add-on and signature.';
        }

        $notes[] = 'These are what the forum logged. A log that is switched off or pruned records nothing, and reads as zero.';

        return $notes;
    }
}
