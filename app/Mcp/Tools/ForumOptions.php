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
    Show a forum's options: which are set, which were changed from their defaults and, where the forum's key
    allows it, what they hold. Use it for "how is this forum configured?" - where mail goes, what is switched on.

    Read `headline` first: it says which of two modes the forum answered in. `mode` is `values` or `shape`.
    - Values mode: each option carries its real `value`, with secrets removed. The value is the one in force;
      `overridden: true` means config.php replaces what is stored.
    - Shape mode: only a boolean, a null, a small number or a declared choice is shown. Everything else says
      whether it is `set`, and `members` opens an array option one level (there is no `members` in values mode).
      A forum answers this way when its key lacks the values scope or the key's administrator lacks a
      permission; `mode_reason` says which. It is a setting on the forum, not a fault.

    Reading an entry, in either mode:
    - `disclosure` is `shown`, `partly_withheld` or `withheld`. A withheld option has no `value` key at all; it
      is not empty unless `set` is false. Inside a value, a removed part reads `[withheld]`, or
      `[withheld: not set]` when the secret held nothing - a secret is hidden either way. A null you see is a null.
    - `[withheld]` and `[withheld: not set]` are this tool's markers, never the stored value. Do not report
      one as what the option holds.
    - `redactions` lists each removed part: its `path` within the value (empty for the whole value), whether
      the secret was `set`, the `reason` and `because`. A `url` redaction keeps the scheme and host. "Its add-on has not declared its secrets" means the text of that add-on's settings is hidden
      as a precaution, not that the setting is a secret or is empty.
    - `set` means the option holds something, not that it is switched on: a boolean `false` is `set: true`.
    - `is_default: true` means the option still holds what XenForo or the add-on shipped.
    - Numbers are decimal: a file mode of `436` is octal 0664.

    The mail transport is the `emailTransport` option: `value.emailTransport`, with the host and port beside
    it, in values mode; `members.emailTransport` in shape mode. `changed` also counts a few values XenForo keeps for
    itself (`currentVersionId`, `jsLastUpdate`), which differ from their defaults on every forum.

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

    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('Whether values were given, and how many entries have something withheld.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $addOnId = $request->get('add_on_id');
        $addOnId = is_string($addOnId) && trim($addOnId) !== '' ? trim($addOnId) : null;
        $changedOnly = $request->get('include_unchanged') !== true;

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->options($addOnId, $changedOnly));

        $data['options'] = Present::disclosures((array) ($data['options'] ?? []));

        return [
            'headline' => Present::modeHeadline($data, $data['options'], 'options'),
            'listed' => $changedOnly ? 'changed options only' : 'every option',
        ] + $data;
    }
}
