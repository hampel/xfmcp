<?php

namespace App\Mcp\Tools;

use App\Support\Forum;
use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the XenForo forums this server can ask. Call this first: every other tool takes a forum `id` from here.

    This reads the server's local list of forums, afresh on every call, and contacts no forum. So it says what
    is CONFIGURED, not what works: a forum listed here may be down, and its keys may be refused.

    `detail_key_configured` is true where a second key for reading individual error log entries has been set
    up for that forum. It does not show the forum accepts that key. To find out what a forum will actually
    answer - without reading anything from it - call forum-access.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ListForums extends Tool
{
    public function handle(Request $request, ForumInventory $inventory): Response|ResponseFactory
    {
        try {
            $forums = $inventory->all();
        } catch (ForumInventoryException $e) {
            return Response::error(
                'The forum inventory cannot be read: '.$e->getMessage().'. Run `xfmcp app:validate` on the machine '
                .'this server runs on.'
            );
        }

        return Response::structured([
            'count' => count($forums),
            'forums' => array_values(array_map(static fn (Forum $forum): array => $forum->describe(), $forums)),
        ]);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()->required(),
            'forums' => $schema->array()->description('One entry per forum: id, name, url, detail_key_configured.')->required(),
        ];
    }
}
