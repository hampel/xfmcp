<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('addon-validate')]
#[Description(<<<'TEXT'
    Run an add-on's own checks on a forum - the checks its `<addon>:validate` command runs on the server - and
    say how each came out. Use it after an add-on is installed or upgraded, or when an add-on seems wrong: it
    answers from inside the add-on (its tables, its settings, its pending work), which the other tools cannot.

    Without `addon_id`, it lists the add-ons on this forum that declare checks, with `checks` (how many are
    declared) and `api_checks` (how many run from here). An empty list means no add-on declares any: nothing
    to run, not a failure. Most add-ons declare none.

    With `addon_id`, read `headline` first. Then each of `checks` has an `id`, a fixed `label` and an `outcome`:
    - `ok`, `warn` or `fail`: the check ran, and this is its worst line. `rows` counts its lines by outcome.
    - `skipped`: it did not run here. An add-on lets a check run from the API only when it is quick, stays
      within the forum and writes nothing; others are skipped. Skipped is never a pass.
    - A check that passed can still count `rows.skipped`: lines with nothing to check, or skipped. That is not
      a fault.
    - Only outcomes come back, never what a check said, so this tool cannot say WHY a check warned or failed.
      That needs the add-on's validate command run on the server by someone with a shell.
    - `exit_code` and `exit_code_strict` are what that command would exit with, without and with `--strict`,
      over the checks that ran: 0 fine, 1 a failure, 2 (strict only) a warning.
    - `invalid_checks` above zero means declared checks could not be reported, and fails the run.

    Refusals say whether the add-on is not installed, installed but not active, or declares no checks.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class AddOnValidate extends ForumTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'addon_id' => $schema->string()->description(
                'The add-on whose checks to run, e.g. "Vendor/AddOn", as the add-ons tool lists it. Leave it out to '
                .'list the add-ons that declare checks.'
            ),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('How the checks came out, or which add-ons declare checks.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $addOnId = $request->get('addon_id');
        $addOnId = is_string($addOnId) ? trim($addOnId) : '';

        if ($addOnId === '') {
            $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->validators());

            return ['headline' => $this->listHeadline((array) ($data['validators'] ?? []))] + $data;
        }

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->validate($addOnId));
        $checks = array_values(array_filter((array) ($data['checks'] ?? []), is_array(...)));

        return [
            'headline' => $this->headline($data, $checks),
            'notes' => $this->notes($checks),
        ] + $data;
    }

    /**
     * @param  array<mixed>  $validators
     */
    private function listHeadline(array $validators): string
    {
        if ($validators === []) {
            return 'No add-on on this forum declares checks to the Admin API, so there is nothing to run. That is '
                .'not a failure: most add-ons declare none.';
        }

        $names = array_map(static fn (mixed $v): string => is_array($v)
            ? sprintf('%s (%d of %d checks run from here)', $v['addon_id'] ?? '?', (int) ($v['api_checks'] ?? 0), (int) ($v['checks'] ?? 0))
            : '?', $validators);

        return sprintf(
            '%s declare%s checks: %s. Pass `addon_id` to run one\'s.',
            count($names) === 1 ? 'One add-on' : count($names).' add-ons',
            count($names) === 1 ? 's' : '',
            implode(', ', $names),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<array<mixed>>  $checks
     */
    private function headline(array $data, array $checks): string
    {
        $addOn = (array) ($data['add_on'] ?? []);
        $by = ['ok' => [], 'warn' => [], 'fail' => [], 'skipped' => []];

        foreach ($checks as $check) {
            $by[(string) ($check['outcome'] ?? 'skipped')][] = (string) ($check['id'] ?? '?');
        }

        $ran = count($checks) - count($by['skipped']);
        $invalid = (int) ($data['invalid_checks'] ?? 0);

        $text = sprintf('%s %s: ', $addOn['addon_id'] ?? 'The add-on', $addOn['version_string'] ?? '');

        if ($ran === 0) {
            $text .= sprintf(
                'none of its %d checks ran here; all were skipped, which says nothing about whether they pass.',
                count($checks),
            );
        } else {
            $parts = [];

            if ($by['fail'] !== []) {
                $parts[] = sprintf('%d FAILED (%s)', count($by['fail']), implode(', ', $by['fail']));
            }

            if ($by['warn'] !== []) {
                $parts[] = sprintf('%d warned (%s)', count($by['warn']), implode(', ', $by['warn']));
            }

            $parts[] = $by['fail'] === [] && $by['warn'] === []
                ? 'all passed'
                : sprintf('%d passed', count($by['ok']));

            $text .= sprintf('of %d checks, %d ran: %s.', count($checks), $ran, implode(', ', $parts));

            if ($by['skipped'] !== []) {
                $text .= sprintf(
                    ' %d %s skipped (%s), which is not a pass.',
                    count($by['skipped']),
                    count($by['skipped']) === 1 ? 'was' : 'were',
                    implode(', ', $by['skipped']),
                );
            }
        }

        if ($invalid > 0) {
            $text .= sprintf(' %d declared check(s) could not be reported, which fails the run.', $invalid);
        }

        if ($ran > 0) {
            $text .= sprintf(
                ' Its validate command would exit %d, and %d with --strict.',
                (int) ($data['exit_code'] ?? 0),
                (int) ($data['exit_code_strict'] ?? 0),
            );
        }

        return $text;
    }

    /**
     * @param  list<array<mixed>>  $checks
     * @return list<string>
     */
    private function notes(array $checks): array
    {
        $notes = [];

        foreach ($checks as $check) {
            $skippedRows = (int) ($check['rows']['skipped'] ?? 0);

            if (in_array($check['outcome'] ?? null, ['ok', 'warn', 'fail'], true) && $skippedRows > 0) {
                $notes[] = sprintf(
                    '`%s` came out %s; %d of its rows had nothing to check or were skipped.',
                    $check['id'] ?? '?',
                    $check['outcome'],
                    $skippedRows,
                );
            }
        }

        $notes[] = 'Only outcomes are returned, never what a check said. Why a check warned or failed is printed by '
            .'the add-on\'s validate command, run on the server.';

        return $notes;
    }
}
