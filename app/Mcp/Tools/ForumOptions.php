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
    - A value can be withheld because it merely looks like a credential - a long random id, a public key. That
      is the forum being cautious, not evidence the value is secret.
    - `redactions` lists each removed part: its `path` within the value (empty for the whole value), whether
      the secret was `set`, and the `reason`, which `redaction_reasons` explains once for the answer. A `url` redaction keeps the scheme and host. "Its add-on has not declared its secrets" means the text of that add-on's settings is hidden
      as a precaution, not that the setting is a secret or is empty.
    - `set` means the option holds something, not that it is switched on: a boolean `false` is `set: true`.
    - `is_default: true` means the option still holds what XenForo or the add-on shipped.
    - Numbers are decimal: a file mode of `436` is octal 0664.
    - `volatile` lists the parts of a value XenForo rewrites itself, as a side effect of something else, with
      `""` for the whole value: `jsLastUpdate` on every add-on install or upgrade and cache rebuild,
      `iconSpriteLastUpdate` when the icon sprite is rebuilt, `collectServerStats.last_sent` on each stats
      send, and `xfesConfig.maxClauseCountChecked` when Enhanced Search rechecks its limit, at most six-hourly.
      A change there is not an administrator's. Leave those parts out when comparing options over time.

    The mail transport is the `emailTransport` option: `value.emailTransport`, with the host and port beside
    it, in values mode; `members.emailTransport` in shape mode. `changed` also counts a few values XenForo keeps for
    itself (`currentVersionId`, `jsLastUpdate`), which differ from their defaults on every forum.

    To ask about particular options, pass `option_ids`: you get exactly those, changed or not. Ids the forum has
    no option by come back under `not_found`, which is not the same as an option at its default - option sets
    differ between XenForo versions and by installed add-on. Never infer a value from an option being absent
    from the changed list; ask for it by id.

    By default only changed options are listed, which is the short and useful list; `total`, `changed` and
    `by_add_on` always count every option.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumOptions extends ForumTool
{
    /**
     * Parts of an option XenForo writes itself, by path within the value, '' for the whole.
     * Each checked in XenForo 2.3's source on 2026-11-09, where it is updated; a tracker on
     * production had to discover them and asked for them to be marked.
     */
    public const VOLATILE = [
        'jsLastUpdate' => [''],                         // XF\AddOn\AddOn, XF\Job\CoreCacheRebuild
        'iconSpriteLastUpdate' => [''],                 // XF\Service\Icon\SpriteGeneratorService
        'collectServerStats' => ['last_sent'],          // XF\Job\CollectStats
        'xfesConfig' => ['maxClauseCountChecked'],      // XFES\Search\Source\Elasticsearch, six-hourly
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'add_on_id' => $schema->string()->description('Only options belonging to this add-on, e.g. "XF" or "Vendor/AddOn".'),
            'option_ids' => $schema->array()->items($schema->string())->description(
                'Exactly these options, whether changed or not - e.g. ["useFriendlyUrls"]. The way to ask about a '
                .'particular setting: it overrides the other two arguments. Up to 100.'
            ),
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
        $ids = $this->names($request->get('option_ids'));

        if ($ids !== []) {
            return $this->named($gateway, $forum, $ids);
        }

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->options($addOnId, $changedOnly));

        $data['options'] = self::markVolatile(Present::disclosures((array) ($data['options'] ?? [])));
        $data = Present::withReasons($data, $data['options']);

        return [
            'headline' => Present::modeHeadline($data, $data['options'], 'options'),
            'listed' => $changedOnly ? 'changed options only' : 'every option',
        ] + $data;
    }

    /**
     * The options asked for by id, whether changed or not.
     *
     * An add-on that predates lookup by id ignores the parameter and returns its list. So
     * the request goes out without `changed`, which makes that list every option, and the
     * ones asked for are picked out here. The answer is then the same either way, and
     * `not_found` means the same thing: this forum has no option by that id.
     *
     * @param  list<string>  $ids
     * @return array<mixed>
     */
    private function named(ForumGateway $gateway, Forum $forum, array $ids): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->options(optionIds: $ids));

        $byForum = array_key_exists('not_found', $data);

        if (! $byForum) {
            $data['options'] = array_values(array_filter(
                (array) ($data['options'] ?? []),
                static fn (mixed $option): bool => is_array($option) && in_array($option['option_id'] ?? null, $ids, true),
            ));

            $data['not_found'] = array_values(array_diff($ids, array_column($data['options'], 'option_id')));

            // these described the whole forum, and would read as describing what was asked for
            unset($data['total'], $data['changed'], $data['by_add_on']);
        }

        $data['options'] = self::markVolatile(Present::disclosures((array) ($data['options'] ?? [])));
        $data = Present::withReasons($data, $data['options']);

        return [
            'headline' => Present::modeHeadline($data, $data['options'], 'options').$this->notFound($data['not_found'], 'option'),
            'listed' => 'the options asked for by id, changed or not',
        ] + $data;
    }

    /**
     * @param  list<array<mixed>>  $options
     * @return list<array<mixed>>
     */
    public static function markVolatile(array $options): array
    {
        return array_map(static function (array $option): array {
            $paths = self::VOLATILE[(string) ($option['option_id'] ?? '')] ?? null;

            return $paths === null ? $option : $option + ['volatile' => $paths];
        }, $options);
    }
}
