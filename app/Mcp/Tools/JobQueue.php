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
    Show a forum's job queue by job class, and where failed jobs failed. Counts only - never a job's data.

    - `due`: automatic jobs whose trigger date has passed. A few is normal; they run on the next request.
    - `stalled`: automatic jobs more than an hour past due. The queue is not being run.
    - `manual`: jobs that only run when triggered from the admin control panel.
    - `retrying`: jobs attempted at least once. A null here, and a null `max_attempts`, means this forum's
      XenForo (2.2) keeps no count of attempts - not that none were retried.

    `failed` needs care. XenForo records a failed job only when the forum is in debug mode, which a production
    forum is not: `failed.recorded: false` with `total: 0` means nothing was recorded, not that nothing failed.
    XenForo 2.2 has no record of failed jobs at all, with the same result. On such a forum a job failure
    shows up in error-log-summary with a `job` origin instead. `notes` says which
    case this is. `failed.signatures` gives the exception class, file and line a failed job stopped at.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class JobQueue extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'notes' => $schema->array()->description('Cautions that apply to this particular answer.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->jobs());

        $notes = [];

        if ((int) ($data['stalled'] ?? 0) > 0) {
            $notes[] = 'Jobs are stalled: the queue has not been run for over an hour.';
        }

        // null, not zero: XenForo 2.2 has nowhere to count a job's attempts
        if (array_key_exists('retrying', $data) && $data['retrying'] === null) {
            $notes[] = 'This forum\'s XenForo keeps no count of job attempts, so `retrying` and `max_attempts` are '
                .'null: not recorded, which is not the same as none.';
        }

        $notes[] = ($data['failed']['recorded'] ?? false) === true
            ? 'This forum records failed jobs now; that does not say it was recording when earlier ones failed.'
            : 'This forum does not record failed jobs, so `failed` being empty says nothing. Look for `job` origin '
                .'in error-log-summary.';

        $data['notes'] = $notes;

        return Present::lead($data, ['notes', 'now', 'total', 'due', 'stalled', 'retrying', 'manual', 'oldest_due_date']);
    }
}
