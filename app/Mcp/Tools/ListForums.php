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

    Reads a local file and contacts no forum, so it says which forums are configured, not which are up or
    whether their keys work. `error_detail` is true where the forum is set up to return individual error log
    entries; where it is false that forum offers summaries only.
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
            'forums' => $schema->array()->description('One entry per forum: id, name, url, error_detail.')->required(),
        ];
    }
}
