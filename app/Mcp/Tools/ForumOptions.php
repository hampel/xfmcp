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

#[Name('forum-options')]
#[Description(<<<'TEXT'
    Show which of a forum's options are set and which have been changed from their defaults. Mostly not what they
    hold: an option can be an API key or an email address, so only a boolean, a null, a number up to 999999, and a
    string that is one of the option's own declared choices are returned.

    Reading an entry:
    - `withheld: true` means the option has a value this tool will not show. It is not empty: read `set`.
    - `set` means the option holds something, not that it is switched on - a boolean `false` is `set: true`.
      Read `value` for whether a flag is on.
    - `is_default: true` means the option still holds what XenForo or the add-on shipped.
    - `members` opens an array option one level, for the members its definition names. The mail transport is
      `emailTransport.members.emailTransport`.
    - Numbers are decimal: a file mode of `436` is octal 0664.

    Options are reported as stored. A forum whose config.php overrides an option in code runs with a value this
    does not show. `changed` also counts a few values XenForo keeps for itself (`currentVersionId`,
    `jsLastUpdate`), which differ from their defaults on every forum.

    By default only changed options are listed, which is the short and useful list; `total`, `changed` and
    `by_add_on` always count every option.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumOptions extends ForumTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'add_on_id' => $schema->string()->description('Only options belonging to this add-on, e.g. "XF" or "Vendor/AddOn".'),
            'include_unchanged' => $schema->boolean()->description(
                'List every option, not only those changed from their default. Several hundred entries on a real forum.'
            ),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $addOnId = $request->get('add_on_id');
        $addOnId = is_string($addOnId) && trim($addOnId) !== '' ? trim($addOnId) : null;
        $changedOnly = $request->get('include_unchanged') !== true;

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->options($addOnId, $changedOnly));

        return ['listed' => $changedOnly ? 'changed options only' : 'every option'] + Present::withheld($data);
    }
}
