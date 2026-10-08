<?php

namespace App\Mcp\Tools;

use App\Forum\ForumCallException;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * A tool that asks one forum one question.
 *
 * Everything the tools share is here: resolving the forum, turning a failure into a
 * sentence instead of a protocol error, and the envelope every answer goes out in. A
 * subclass says what to ask and how to lay the answer out.
 */
abstract class ForumTool extends Tool
{
    /**
     * @return array<mixed>
     *
     * @throws ForumCallException
     */
    abstract protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array;

    public function handle(Request $request, ForumGateway $gateway): Response|ResponseFactory
    {
        try {
            $forum = $gateway->forum((string) $request->get('forum', ''));
            $answer = $this->answer($request, $gateway, $forum);
        } catch (ForumCallException $e) {
            // an error RESULT, not a protocol error: the agent should read why and carry on
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'forum' => ['id' => $forum->id, 'name' => $forum->name],
            'times' => Present::timeNote(),
            ...Present::timestamps($answer),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['forum' => $this->forumArgument($schema)];
    }

    /**
     * Only what every answer carries. The rest of each answer is the add-on's own response,
     * documented in the tool's description, and is not restated as a schema that would
     * have to be kept in step with it.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'forum' => $schema->object()->description('The forum that answered.')->required(),
            'times' => $schema->object()->description('How to read the times in this answer.')->required(),
        ];
    }

    protected function forumArgument(JsonSchema $schema): Type
    {
        return $schema->string()
            ->description('The id of the forum to ask, as returned by list-forums.')
            ->required();
    }

    /**
     * A point in time an agent typed, as Unix seconds.
     *
     * Accepts Unix seconds, ISO 8601, or anything else Carbon reads ("-7 days",
     * "yesterday"). A time with no zone is UTC, because everything the forum reports is.
     *
     * @throws ForumCallException
     */
    protected function instant(mixed $value, string $argument): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->getTimestamp();
        } catch (Throwable) {
            throw new ForumCallException(sprintf(
                '`%s` is not a time this tool can read: give ISO 8601 (2026-01-31T00:00:00Z), Unix seconds, '
                .'or a relative time such as "-7 days".',
                $argument,
            ));
        }
    }

    protected function integer(mixed $value, int $min, int $max): ?int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : null;
    }
}
