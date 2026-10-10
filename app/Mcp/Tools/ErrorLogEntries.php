<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumCallException;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Read individual entries from a forum's server error log: the message and stack trace, as XenForo logged them.
    This is the one tool that returns content rather than counts. A message can quote whatever was being handled
    when the error happened - a member's input, an email address, SQL - so use it when error-log-summary has said
    where a problem is and you need to know why, not to browse.

    The forum removes what it recognises as a secret from the message, the trace and the request - a webhook
    URL, a token, a password parameter - and puts `[withheld]` in its place; a URL keeps its scheme and host.
    That marker is not logged text. `scrubbed` counts the removals in an entry, and `notes` says so when there
    were any. It is best effort on free text: anything that is not a recognisable secret is still there.

    `origin_basis` says how an entry's originating add-on was worked out: `trace`, or `message` when only the
    error's message names it - the weaker of the two.

    Takes an `error_id`: a signature's `last_error_id` from error-log-summary. It returns that entry with the most
    recent others at the same exception type, file and line. There is no way to page through the log.

    `include_request` adds the recorded request, the user id and the IP address to each entry. Leave it off unless
    the question is specifically who was affected or what was submitted: why an error happened is nearly always in
    the message and the trace, and the summary's `members` and `by_origin` already say whether anyone was affected.

    A forum can only answer if it has a detail key configured and accepts it. forum-access says whether it
    does, without reading an entry; do not call this tool to find out.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ErrorLogEntries extends ForumTool
{
    /**
     * Not listed at all unless some forum has a detail key. A server with none configured
     * should not advertise a tool that reads error messages and then refuse every call.
     */
    public function shouldRegister(ForumInventory $inventory): bool
    {
        try {
            foreach ($inventory->all() as $forum) {
                if ($forum->hasDetailKey()) {
                    return true;
                }
            }
        } catch (ForumInventoryException) {
            // list-forums reports an unreadable inventory; this tool just stays hidden
        }

        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'error_id' => $schema->integer()->description('The entry to read: a `last_error_id` from error-log-summary.')->required(),
            'limit' => $schema->integer()->description('How many entries to return, counting the one asked for. Default 5, maximum 20.'),
            'include_request' => $schema->boolean()->description(
                'Add the recorded request (URL, referrer, submitted data or command line), the user id and the IP '
                .'address. Personal data; off by default.'
            ),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $errorId = $this->integer($request->get('error_id'), 1, PHP_INT_MAX);

        if ($errorId === null) {
            throw new ForumCallException('`error_id` is required: pass a `last_error_id` from error-log-summary.');
        }

        $limit = $this->integer($request->get('limit'), 1, 20);
        $withRequest = $request->get('include_request') === true;

        $data = $gateway->detail(
            $forum,
            static fn (AdminApi $api): array => $api->errorEntries($errorId, $limit, $withRequest),
        );

        $scrubbed = array_sum(array_map(
            static fn (mixed $entry): int => is_array($entry) ? (int) ($entry['scrubbed'] ?? 0) : 0,
            (array) ($data['entries'] ?? []),
        ));

        if ($scrubbed > 0) {
            $data = ['notes' => [sprintf(
                'The forum removed %d %s from the text of these entries. Each `[withheld]` marks one; it is not what was logged.',
                $scrubbed,
                $scrubbed === 1 ? 'secret' : 'secrets',
            )]] + $data;
        }

        return $data;
    }
}
