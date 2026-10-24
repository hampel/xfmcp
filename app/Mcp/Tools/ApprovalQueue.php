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
    Count what is waiting in a forum's approval queue: how much, of what kind, and for how long. Use it for "is
    anyone working the queue?". Counts only - never the content or who posted it.

    Read `headline` first. Then:
    - `by_age` buckets items by how long ago they were posted: `under_hour`, `under_day`, `under_week`, `older`.
    - `oldest_date` is when the content was posted, not when it was queued; for most content that is the same.
    - `cached_total` is the number XenForo shows moderators beside the queue. When it differs from `total`,
      moderators are being shown the wrong number, and `headline` says so.
    - An item whose content has since been deleted is still counted; XenForo's own queue page skips it, and skips
      what a moderator cannot view, so a moderator may see fewer.
    - A null `content_type` is content from an add-on since removed.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ApprovalQueue extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('The queue in one sentence.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->approvalQueue());

        $data['headline'] = $this->headline($data);

        return Present::lead($data, ['headline', 'total', 'cached_total', 'oldest_date', 'newest_date', 'by_age']);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $total = (int) ($data['total'] ?? 0);
        $cached = $data['cached_total'] ?? null;

        $mismatch = is_int($cached) && $cached !== $total
            ? sprintf(' Moderators are shown %d, which is wrong.', $cached)
            : '';

        if ($total === 0) {
            return 'Nothing is awaiting approval.'.$mismatch;
        }

        $oldest = (int) ($data['oldest_date'] ?? 0);
        $older = (int) ($data['by_age']['older'] ?? 0);

        return sprintf(
            '%d %s awaiting approval; the oldest was posted %s. %s%s',
            $total,
            $total === 1 ? 'item is' : 'items are',
            Present::distance($oldest, time()),
            $older === 0 ? 'None is older than a week.' : sprintf('%d %s older than a week.', $older, $older === 1 ? 'is' : 'are'),
            $mismatch,
        );
    }
}
