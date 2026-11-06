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
    Summarise a forum's server error log: how many errors, of what kind, from where, and how many members met one.
    Counts only - never a message, a stack trace, a request, or who it happened to.

    Read `headline` first. The default window is the last 24 hours, and a quiet day is not an empty log: `log`
    describes the whole log whatever the window, and `since: "all"` covers all of it.

    Whose error is it? Read in this order:
    - `by_origin_add_on` / a signature's `origin_add_ons`: the add-on the error was raised in or, for one raised
      in the core, the nearest add-on in the calls that led there. The best answer, and still a lead, not a verdict.
      `from_message` on a row is how many of its `count` were attributed only because the error's message names
      the add-on, its trace naming none - a missing class or file that fails inside the core. A weaker lead still.
    - `location`: `add_on_vendor` or `add_on_tests` means a library bundled with an add-on, or its test suite -
      not that add-on's own code.
    - `by_add_on` (last in the answer) is only where the error surfaced. An add-on's template fault surfaces in `XF`.

    Other things that are easy to misread:
    - A signature is one exception type at one file and line. `last_error_id` is what error-log-entries takes.
    - `by_origin`: `web` is a request, `job` includes every cron entry, `cli` is someone at a terminal.
    - `members` counts different logged-in members; guests, the command line and jobs count as nobody.
    - `ErrorException` covers PHP warnings, fatals and messages an add-on logged; `severity` tells them apart.
    - `by_day` reaches back at most 366 days; on a longer window `notes` says from when.
    - XenForo prunes the log. `window.precedes_log: true` means part of the window is older than anything kept,
      and those days read as zero whether or not anything happened.

    An empty result means nothing was logged. It does not mean nothing is broken: a fault that logs no error is
    invisible here.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ErrorLogSummary extends ForumTool
{
    /** what a reader needs before the breakdowns, with where-it-surfaced deliberately last */
    private const ORDER = [
        'headline', 'notes', 'window', 'total', 'log', 'newest_date', 'oldest_date', 'members', 'member_errors',
        'signature_count', 'examined', 'by_origin', 'by_origin_add_on', 'by_location', 'by_severity', 'by_type',
        'by_template', 'signatures', 'by_day',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'since' => $schema->string()->description(
                'Start of the window: ISO 8601, Unix seconds, a relative time such as "-7 days", or "all" for the '
                .'whole log. A time without a zone is UTC. Defaults to 24 hours before `until`.'
            ),
            'until' => $schema->string()->description(
                'End of the window, in the same forms. Defaults to now. Both ends are included: an entry at '
                .'exactly `since` or `until` is counted.'
            ),
            'limit' => $schema->integer()->description('How many signatures to return, most frequent first. Default 20, maximum 100.'),
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

        $query = array_filter([
            'since' => is_string($since) && strtolower(trim($since)) === 'all' ? 0 : $this->instant($since, 'since'),
            'until' => $this->instant($request->get('until'), 'until'),
            'limit' => $this->integer($request->get('limit'), 1, 100),
        ], static fn ($value): bool => $value !== null);

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->errorLog($query));

        $data['headline'] = $this->headline($data);
        $data['notes'] = $this->notes($data);

        return Present::lead($data, self::ORDER);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $total = (int) ($data['total'] ?? 0);
        $log = is_array($data['log'] ?? null) ? $data['log'] : [];
        $logTotal = (int) ($log['total'] ?? 0);
        $now = (int) ($data['window']['until'] ?? time());

        if ($logTotal === 0) {
            return 'The server error log is empty.';
        }

        if ($total === 0) {
            $newest = (int) ($log['newest_date'] ?? 0);

            return sprintf(
                'Nothing in this window, but the log holds %d %s; the newest is from %s (%s). Ask with since "all" to see them.',
                $logTotal,
                $logTotal === 1 ? 'entry' : 'entries',
                Present::iso($newest),
                Present::distance($newest, $now),
            );
        }

        $newest = (int) ($data['newest_date'] ?? 0);
        $members = (int) ($data['members'] ?? 0);
        $signatures = (int) ($data['signature_count'] ?? 0);

        return sprintf(
            '%d %s in this window across %d %s; the newest is from %s (%s). %s',
            $total,
            $total === 1 ? 'error' : 'errors',
            $signatures,
            $signatures === 1 ? 'signature' : 'signatures',
            Present::iso($newest),
            Present::distance($newest, $now),
            (int) ($data['by_origin']['web'] ?? 0) === 0
                ? 'None came from a web request.'
                : ($members === 0
                    ? 'No logged-in member met one.'
                    : sprintf('%d logged-in %s met one.', $members, $members === 1 ? 'member' : 'members')),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function notes(array $data): array
    {
        $notes = [];

        if (($data['window']['precedes_log'] ?? false) === true) {
            $notes[] = 'The window starts before the oldest entry the log still holds, so its earliest days read as '
                .'zero whether or not anything happened then.';
        }

        if (($cut = Present::byDayCut($data)) !== null) {
            $notes[] = $cut;
        }

        if (($data['examined']['complete'] ?? true) === false) {
            $notes[] = 'This is an error storm: severity, origin, originating add-on and template describe only the '
                .'most recent rows examined. Every other figure is exact.';
        }

        $fromMessage = array_sum(array_map(
            static fn (mixed $row): int => is_array($row) ? (int) ($row['from_message'] ?? 0) : 0,
            (array) ($data['by_origin_add_on'] ?? []),
        ));

        if ($fromMessage > 0) {
            $notes[] = sprintf(
                '%d of these errors %s attributed to an add-on because the message names it, not the trace: the '
                .'error was raised inside the core before any of that add-on\'s code ran. `from_message` says which rows.',
                $fromMessage,
                $fromMessage === 1 ? 'is' : 'are',
            );
        }

        if ((int) ($data['total'] ?? 0) > 0 && (int) ($data['signature_count'] ?? 0) > count($data['signatures'] ?? [])) {
            $notes[] = 'There are more signatures than are listed; raise `limit` to see them. The totals and every '
                .'`by_` group already cover the whole window.';
        }

        $notes[] = 'No logged error is not the same as nothing being wrong.';

        return $notes;
    }
}
