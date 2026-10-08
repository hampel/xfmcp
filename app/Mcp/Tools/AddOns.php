<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List every add-on a forum knows about, with XenForo's own version and the PHP version it runs on.

    - `version_*` is what is installed; `files_version_*` is what is on disk. They differ when new files were
      uploaded and the upgrade was not run, which `can_upgrade` reports.
    - `pending_action` (`install`, `upgrade`, `uninstall`) with `is_processing: false` is an action that started
      and never finished. That is the state to worry about, and matching versions do not rule it out.
    - `has_pending_changes` means the add-on's addon.json on disk no longer matches what was installed.
    - `is_installed: false` is an add-on present on disk only.

    `attention` names the add-ons in any of the first three states; an empty list means none are.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class AddOns extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'attention' => $schema->object()->description('Add-on ids by the state that needs a look.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->addOns());

        $attention = ['unfinished_action' => [], 'upgrade_not_run' => [], 'files_changed' => []];

        foreach ($data['add_ons'] ?? [] as $addOn) {
            if (! is_array($addOn)) {
                continue;
            }

            $id = (string) ($addOn['addon_id'] ?? '');

            if (($addOn['pending_action'] ?? null) !== null && ($addOn['is_processing'] ?? false) === false) {
                $attention['unfinished_action'][] = $id;
            }

            if (($addOn['can_upgrade'] ?? false) === true) {
                $attention['upgrade_not_run'][] = $id;
            } elseif (($addOn['has_pending_changes'] ?? false) === true) {
                $attention['files_changed'][] = $id;
            }
        }

        $data['attention'] = $attention;

        return Present::lead($data, ['xf', 'php_version', 'attention']);
    }
}
