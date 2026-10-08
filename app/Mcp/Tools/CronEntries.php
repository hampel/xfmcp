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
    Show a forum's cron entries and whether cron is running at all.

    XenForo records when an entry is next due and never when it last ran, so this cannot say "the clean-up ran at
    03:00". It can say whether cron is being triggered: `runner.last_run_date` is when the job that drives every
    entry last ran, `runner.queued: false` means nothing will run any entry, and `overdue` counts enabled entries
    more than an hour past due. Read `headline` first.

    `run_rules` is in UTC, like every time here: `"hours": [3]` is 03:00 UTC whatever the forum's own time zone.
    `-1` means every one; `day_type` says whether the day comes from `dom` (day of month) or `dow` (day of week,
    0 is Sunday). `entries` is ordered by `next_run`, so anything overdue is first. A disabled entry, or one whose
    add-on is disabled, is never overdue.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class CronEntries extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('Whether cron is running, in one sentence.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->cron());

        $data['headline'] = $this->headline($data);

        return Present::lead($data, ['headline', 'now', 'runner', 'total', 'active', 'overdue', 'oldest_overdue_date']);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $now = (int) ($data['now'] ?? time());
        $runner = is_array($data['runner'] ?? null) ? $data['runner'] : [];
        $overdue = (int) ($data['overdue'] ?? 0);
        $lastRun = $runner['last_run_date'] ?? null;

        $ran = is_int($lastRun) && $lastRun > 0
            ? 'The cron runner last ran '.Present::distance($lastRun, $now)
            : 'The cron runner has no recorded run';

        if (($runner['queued'] ?? false) !== true) {
            return $ran.' and is NOT queued: nothing will run any cron entry.';
        }

        return $overdue === 0
            ? $ran.'; no entry is overdue.'
            : sprintf('%s; %d %s overdue.', $ran, $overdue, $overdue === 1 ? 'entry is' : 'entries are');
    }
}
