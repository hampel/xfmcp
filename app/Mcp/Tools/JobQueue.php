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
    - `retrying`: jobs attempted at least once.

    `failed` needs care. XenForo records a failed job only when the forum is in debug mode, which a production
    forum is not: `failed.recorded: false` with `total: 0` means nothing was recorded, not that nothing failed.
    On such a forum a job failure shows up in error-log-summary with a `job` origin instead. `notes` says which
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

        $notes[] = ($data['failed']['recorded'] ?? false) === true
            ? 'This forum records failed jobs now; that does not say it was recording when earlier ones failed.'
            : 'This forum does not record failed jobs, so `failed` being empty says nothing. Look for `job` origin '
                .'in error-log-summary.';

        $data['notes'] = $notes;

        return Present::lead($data, ['notes', 'now', 'total', 'due', 'stalled', 'retrying', 'manual', 'oldest_due_date']);
    }
}
