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
    Show the settings in force from a forum's src/config.php, merged over XenForo's defaults, one entry per
    dotted key. Where the forum's key allows it, with their values.

    Read `headline` first: it says which of two modes the forum answered in.
    - Values mode: each setting carries its `value`, with secrets removed. The database password and username
      and the global salt are always withheld; the database host and name are shown.
    - Shape mode (the key lacks the values scope): a boolean, a null and a small number are shown, and a string
      never is - it says only that it is `set`.

    Reading an entry, in either mode:
    - `disclosure` is `shown`, `partly_withheld` or `withheld`. A withheld setting has no `value` key at all; it
      is not empty - read `set`. Inside a value, a removed part reads `[withheld]`. So a null you see is a null.
    - `redactions` lists each removed part with its `path` and `because`. "Its add-on has not declared its
      secrets" means a key XenForo does not define, whose text is hidden as a precaution.
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

    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('Whether values were given, and how many entries have something withheld.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $changedOnly = $request->get('include_unchanged') !== true;

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->config($changedOnly));

        $data['settings'] = Present::disclosures((array) ($data['settings'] ?? []));

        return [
            'headline' => Present::modeHeadline($data, $data['settings'], 'settings'),
            'listed' => $changedOnly ? 'changed settings only' : 'every setting',
        ] + $data;
    }
}
