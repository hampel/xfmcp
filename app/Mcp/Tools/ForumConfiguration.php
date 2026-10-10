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

    Read `headline` first: it says which of two modes the forum answered in. `mode` is `values` or `shape`.
    - Values mode: each setting carries its `value`, with secrets removed. The database password and username
      and the global salt are always withheld; the database host and name are shown.
    - Shape mode: a boolean, a null and a small number are shown, and a string never is - it says only that it
      is `set`. A forum answers this way when its key lacks the values scope or the key's administrator lacks a
      permission; `mode_reason` says which. It is a setting on the forum, not a fault.

    Reading an entry, in either mode:
    - `disclosure` is `shown`, `partly_withheld` or `withheld`. A withheld setting has no `value` key at all; it
      is not empty unless `set` is false. Inside a value, a removed part reads `[withheld]`, or
      `[withheld: not set]` when the secret held nothing - a secret is hidden either way. A null you see is a null.
    - `[withheld]` and `[withheld: not set]` are this tool's markers, never the stored value. Do not report
      one as what the setting holds.
    - `redactions` lists each removed part: its `path` (empty for the whole value), whether the secret was
      `set`, the `reason` and `because`. `inherited_from` names a key above this one that the rule was for. "Its add-on has not declared its
      secrets" means a key XenForo does not define, whose text is hidden as a precaution.
    - `has_default: false` is a key XenForo does not define, which config.php or an add-on introduced. Every such
      key counts as changed, including the `db.*` settings every forum has, so `changed` is never zero.
    - A list is one entry with a `count`; a nested setting is followed to five levels.

    Useful flags: `debug`, `development.enabled`, `enableListeners`, `enableMail`. `debug: true` is also what makes
    a forum record failed jobs.

    To ask about particular settings, pass `keys`. A key matches itself and everything beneath it. Keys that
    match nothing come back under `not_found`: this forum's configuration has no such setting.

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
            'keys' => $schema->array()->items($schema->string())->description(
                'Exactly these settings and everything beneath each - e.g. ["debug"], or ["db"] for every database '
                .'setting. Overrides include_unchanged. Up to 100.'
            ),
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
        $keys = $this->names($request->get('keys'));

        if ($keys !== []) {
            return $this->named($gateway, $forum, $keys);
        }

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->config($changedOnly));

        $data['settings'] = Present::disclosures((array) ($data['settings'] ?? []));

        return [
            'headline' => Present::modeHeadline($data, $data['settings'], 'settings'),
            'listed' => $changedOnly ? 'changed settings only' : 'every setting',
        ] + $data;
    }

    /**
     * The settings asked for by key, with everything beneath each.
     *
     * As for options: an add-on that predates lookup by key returns every setting, and the
     * ones asked for are picked out here by the add-on's own rule - a key matches itself
     * and whatever sits under it, so `db` is not `dbExtra`.
     *
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private function named(ForumGateway $gateway, Forum $forum, array $keys): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->config(keys: $keys));

        if (! array_key_exists('not_found', $data)) {
            // the add-on's rule to the letter, trailing dot included: `db.` asks for what is under `db`
            $matches = static fn (string $key, string $asked): bool => $key === $asked || str_starts_with($key, rtrim($asked, '.').'.');

            $all = array_filter((array) ($data['settings'] ?? []), 'is_array');

            $data['settings'] = array_values(array_filter($all, static function (array $setting) use ($keys, $matches): bool {
                foreach ($keys as $asked) {
                    if ($matches((string) ($setting['key'] ?? ''), $asked)) {
                        return true;
                    }
                }

                return false;
            }));

            $data['not_found'] = array_values(array_filter($keys, static function (string $asked) use ($data, $matches): bool {
                foreach ($data['settings'] as $setting) {
                    if ($matches((string) ($setting['key'] ?? ''), $asked)) {
                        return false;
                    }
                }

                return true;
            }));

            unset($data['total'], $data['changed']);
        }

        $data['settings'] = Present::disclosures((array) ($data['settings'] ?? []));

        return [
            'headline' => Present::modeHeadline($data, $data['settings'], 'settings').$this->notFound($data['not_found'], 'setting'),
            'listed' => 'the settings asked for by key, with everything beneath each',
        ] + $data;
    }
}
