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
    URL, a token, a password parameter - and puts `[withheld]` in its place; a URL keeps its scheme and host,
    or its scheme alone when it has no host. A request field named like a credential is withheld whole, and so
    is a message or trace too long to scrub. That marker is not logged text; `********` is XenForo's own mask
    on a password field and is not counted. `scrubbed` counts the removals in an entry, and `notes` says so when
    there were any. It is best effort on free text: anything that is not a recognisable secret is still there.

    `origin_basis` says how an entry's originating add-on was worked out: `trace`, or `message` when only the
    error's message names it - the weaker of the two.

    Takes an `error_id`: a signature's `last_error_id` from error-log-summary. It returns that entry with the most
    recent others at the same exception type, file and line. There is no way to page through the log.

    Only the entry asked for carries its stack trace, unless `all_traces` is set. The others share its exception
    type, file and line, so their traces mostly repeat it, and a trace runs to a few thousand characters: twenty
    in full can be more than a client shows. Their messages are usually what differs.

    `include_request` adds the recorded request, the user id and the IP address to each entry. Leave it off unless
    the question is specifically who was affected or what was submitted: why an error happened is nearly always in
    the message and the trace, and the summary's `members` and `by_origin` already say whether anyone was affected.
    The forum records each call with `include_request` in its admin log as a
    member lookup, found or not, and a forum may forward its admin log elsewhere. Without it nothing is recorded.

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
            'all_traces' => $schema->boolean()->description(
                'Keep the stack trace of every entry, not only the one asked for. Off by default: each runs to a few '
                .'thousand characters, so ask for a small `limit` with it.'
            ),
            'include_request' => $schema->boolean()->description(
                'Add the recorded request (URL, referrer, submitted data or command line), the user id and the IP '
                .'address. Personal data, and recorded in the forum\'s admin log; off by default.'
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

        $data = $this->traces($data, $errorId, $request->get('all_traces') === true);

        $scrubbed = array_sum(array_map(
            static fn (mixed $entry): int => is_array($entry) ? (int) ($entry['scrubbed'] ?? 0) : 0,
            (array) ($data['entries'] ?? []),
        ));

        if ($scrubbed > 0) {
            $data['notes'] = [sprintf(
                'The forum removed %d %s from the text of these entries. Each `[withheld]` marks one; it is not what was logged.',
                $scrubbed,
                $scrubbed === 1 ? 'secret' : 'secrets',
            ), ...($data['notes'] ?? [])];
        }

        if (isset($data['notes'])) {
            $data = ['notes' => $data['notes']] + $data;
        }

        return $data;
    }

    /**
     * Leave the trace on the entry asked for and take it off the rest, unless all are wanted.
     * Seventeen traces of one signature ran to 51,000 characters, which a client refused to
     * show, and each repeated the same frames.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function traces(array $data, int $errorId, bool $all): array
    {
        if ($all || ! is_array($data['entries'] ?? null)) {
            return $data;
        }

        $removed = 0;

        foreach ($data['entries'] as $i => $entry) {
            if (is_array($entry) && array_key_exists('trace', $entry) && (int) ($entry['error_id'] ?? 0) !== $errorId) {
                unset($data['entries'][$i]['trace']);
                $removed++;
            }
        }

        if ($removed > 0) {
            $data['notes'] = [sprintf(
                'Only entry %d carries its stack trace; %d %s left out, as they share its exception type, file '
                .'and line. Ask with `all_traces` for them.',
                $errorId,
                $removed,
                $removed === 1 ? 'other was' : 'others were',
            )];
        }

        return $data;
    }
}
