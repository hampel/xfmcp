<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumCallException;
use App\Forum\ForumGateway;
use App\Support\Forum;
use Hampel\XenForo\Api\Exception\ApiException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Find out what one forum will actually answer, before relying on it: whether it is reachable, whether its
    summaries can be read, whether options and configuration come with values, and whether individual error log
    entries can be read. Use it when list-forums is not enough - it only says what is configured.

    It reads nothing personal. The check for error log entries asks for no entry, so the forum can only say
    whether the key would be accepted; no message or trace is returned. Never call error-log-entries to find
    out whether it works.

    Read `headline` first. Each part then has `available` and, where it is not, `problem`: a sentence saying
    whether the forum is unreachable, lacks the add-on, or refused the key and why. A part that is not
    available is nearly always a setting on the forum - a scope on the key, or an admin permission its
    administrator lacks - and not something this tool or you can change.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumAccess extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('What this forum will and will not answer, in one sentence.')->required(),
            'summaries' => $schema->object()->description('The error log summary, add-ons, cron, job queue and who is online.')->required(),
            'values' => $schema->object()->description('Whether options and configuration are answered with values.')->required(),
            'error_entries' => $schema->object()->description('Whether individual error log entries can be read.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $summaries = $this->attempt(static fn (): array => $gateway->read($forum, static fn (AdminApi $api): array => $api->addOns()));

        $values = [];

        foreach (['options' => 'options', 'configuration' => 'config'] as $name => $method) {
            $answer = $this->attempt(static fn (): array => $gateway->read($forum, static fn (AdminApi $api): array => $api->{$method}(changed: true)));

            $values[$name] = $answer['problem'] !== null
                ? ['available' => false, 'problem' => $answer['problem']]
                : (($answer['data']['mode'] ?? null) === 'values'
                    ? ['available' => true]
                    : ['available' => false, 'problem' => 'The forum answers without values because '.($answer['data']['mode_reason'] ?? 'of a reason it did not give').'.']);
        }

        $entries = $this->errorEntries($gateway, $forum);

        return [
            'headline' => $this->headline($summaries['problem'], $values, $entries),
            'summaries' => $summaries['problem'] === null
                ? ['available' => true, 'xenforo' => $summaries['data']['xf']['version_string'] ?? null]
                : ['available' => false, 'problem' => $summaries['problem']],
            'values' => $values,
            'error_entries' => $entries,
        ];
    }

    /**
     * Whether the detail key would be accepted, found out by asking for no entry at all.
     *
     * The add-on checks the key, its scope and its administrator's permissions before it
     * looks at the request, so a request naming no entry is refused for the missing
     * parameter only when everything else passed. Nothing is read from the log either way.
     *
     * @return array{configured: bool, available: bool, problem?: string}
     */
    private function errorEntries(ForumGateway $gateway, Forum $forum): array
    {
        if (! $forum->hasDetailKey()) {
            return [
                'configured' => false,
                'available' => false,
                'problem' => 'No detail key is configured for this forum, so it offers summaries only. That is a deliberate setting.',
            ];
        }

        try {
            $gateway->detail($forum, static fn (AdminApi $api): array => $api->errorEntriesProbe());
        } catch (ForumCallException $e) {
            return $e->getPrevious() instanceof ApiException
                && $e->getPrevious()->hasCode(ForumGateway::CODE_ERROR_ID_REQUIRED)
                    ? ['configured' => true, 'available' => true]
                    : ['configured' => true, 'available' => false, 'problem' => $e->getMessage()];
        }

        // the forum answered a request that named no entry: not what the add-on does
        return ['configured' => true, 'available' => false, 'problem' => 'The forum answered a request naming no entry, which the add-on should refuse; its version may not match this tool.'];
    }

    /**
     * @param  callable(): array<mixed>  $call
     * @return array{data: array<mixed>, problem: string|null}
     */
    private function attempt(callable $call): array
    {
        try {
            return ['data' => $call(), 'problem' => null];
        } catch (ForumCallException $e) {
            return ['data' => [], 'problem' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, array{available: bool}>  $values
     * @param  array{configured: bool, available: bool}  $entries
     */
    private function headline(?string $summariesProblem, array $values, array $entries): string
    {
        if ($summariesProblem !== null) {
            return 'This forum cannot be read at all: '.$summariesProblem;
        }

        $withValues = array_keys(array_filter($values, static fn (array $part): bool => $part['available']));

        return sprintf(
            'Summaries can be read. Values are shown for %s. Individual error log entries %s.',
            match (count($withValues)) {
                2 => 'options and configuration',
                1 => $withValues[0].' only',
                default => 'neither options nor configuration',
            },
            match (true) {
                $entries['available'] => 'can be read',
                $entries['configured'] => 'CANNOT be read: a detail key is configured but the forum refuses it',
                default => 'cannot be read: no detail key is configured',
            },
        );
    }
}
