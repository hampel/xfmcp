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
    Count the reports waiting for a moderator: how many, of what kind, for how long, and how long since anyone
    touched them. Use it for "is anyone working the reports?". Counts only - never the report, its comments, the
    content, who reported it or whom it is about.

    Read `headline` first. Then:
    - A report is waiting while it is open or assigned: `total` is both, `open` those no moderator has taken,
      `assigned` those one has. Resolved and rejected reports are not counted.
    - `oldest_date` and `by_age` run from when the content was first reported. A resolved report that is reported
      again reopens with its first date, so it counts as old as that.
    - `oldest_activity_date` is the longest any waiting report has gone untouched - no new report, comment or
      change of state. That is the "is anyone working them?" figure; `oldest_date` is "how long has this gone on?".
    - `cached_total` is the number XenForo shows moderators beside Reports. When it differs from `total`,
      moderators are being shown the wrong number, and `headline` says so.
    - A null `content_type` is content nothing on the forum handles reports for any more.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class Reports extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('The waiting reports in one sentence.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->reports());

        $data['headline'] = $this->headline($data);

        return Present::lead($data, [
            'headline', 'total', 'open', 'assigned', 'cached_total', 'oldest_date', 'oldest_activity_date',
            'newest_date', 'by_age',
        ]);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $total = (int) ($data['total'] ?? 0);
        $cached = $data['cached_total'] ?? null;
        $now = (int) ($data['now'] ?? time());

        $mismatch = is_int($cached) && $cached !== $total
            ? sprintf(' Moderators are shown %d, which is wrong.', $cached)
            : '';

        if ($total === 0) {
            return 'No report is waiting for a moderator.'.$mismatch;
        }

        $assigned = (int) ($data['assigned'] ?? 0);

        return sprintf(
            '%d %s waiting (%d open, %d assigned); the oldest was first reported %s, and the longest untouched '
            .'was last acted on %s.%s',
            $total,
            $total === 1 ? 'report is' : 'reports are',
            (int) ($data['open'] ?? $total - $assigned),
            $assigned,
            Present::distance((int) ($data['oldest_date'] ?? 0), $now),
            Present::distance((int) ($data['oldest_activity_date'] ?? 0), $now),
            $mismatch,
        );
    }
}
