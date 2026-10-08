<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('forum-configuration')]
#[Description(<<<'TEXT'
    Show which settings are in force from a forum's src/config.php, merged over XenForo's defaults, one entry per
    dotted key. A boolean, a null and a small number are returned; a string never is, so `db.password` is reported
    as a string that is set and nothing more.

    Reading an entry:
    - `withheld: true` means the setting has a value this tool will not show. It is not empty: read `set`.
    - `has_default: false` is a key XenForo does not define, which config.php or an add-on introduced. Every such
      key counts as changed, including the `db.*` settings every forum has, so `changed` is never zero.
    - A list is one entry with a `count`; a nested setting is followed to five levels.

    Useful flags: `debug`, `development.enabled`, `enableListeners`, `enableMail`. `debug: true` is also what makes
    a forum record failed jobs.

    By default only settings that differ from XenForo's defaults are listed.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumConfiguration extends ForumTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'include_unchanged' => $schema->boolean()->description('List every setting, not only those that differ from XenForo\'s default.'),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $changedOnly = $request->get('include_unchanged') !== true;

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->config($changedOnly));

        return ['listed' => $changedOnly ? 'changed settings only' : 'every setting'] + Present::withheld($data);
    }
}
