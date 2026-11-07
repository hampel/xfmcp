<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumCallException;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Hampel\XenForo\Api\Exception\ApiException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Count the members who registered and the threads and posts made in a window, by the state each is in now:
    registrations awaiting email confirmation or approval, content awaiting approval or deleted. Counts only -
    never a name, an email address, a title or a message.

    Read `headline` first. The default window is the last 24 hours, and the forum refuses one longer than 31 days.

    Easy to misread:
    - A state is the one each is in now, not the one it was made in: a member who registered yesterday and was
      approved this morning counts as `valid`.
    - What was deleted outright is not counted at all. `deleted` is a soft deletion, still restorable. A rejected
      registration counts as `rejected` while XenForo keeps its record.
    - Registration states: `email_confirm` is awaiting email confirmation, `email_confirm_edit` confirming a changed
      address, `moderated` awaiting approval, `email_bounce` a member whose mail bounced, `disabled` an account an
      administrator disabled. `other` is a state XenForo does not define, which only an add-on changing core's
      tables could make. `headline` names every state but `valid` that holds anyone.
    - A window longer than 31 days is refused. For a longer period, ask in consecutive windows, each `until` the
      next one's `since`, and add the counts: a window excludes its `until`, so nothing is counted twice.
    - Every thread's first post is a post as well, so `posts` includes the opening post of each thread.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class ForumActivity extends ForumTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'since' => $schema->string()->description(
                'Start of the window: ISO 8601, Unix seconds or a relative time such as "-7 days". A time without a '
                .'zone is UTC. Defaults to 24 hours before `until`; at most 31 days before it.'
            ),
            'until' => $schema->string()->description(
                'End of the window, in the same forms. Defaults to now. An entry at exactly `until` is not '
                .'counted, so it can be the next window\'s `since`.'
            ),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('The answer in one sentence.')->required(),
            'notes' => $schema->array()->description('Cautions that apply to this particular answer.'),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $query = array_filter([
            'since' => $this->instant($request->get('since'), 'since'),
            'until' => $this->instant($request->get('until'), 'until'),
        ], static fn ($value): bool => $value !== null);

        // measured now, not after the forum answers, so a second over is not taken for exactly 31 days
        $span = isset($query['since']) && ! isset($query['until']) ? CarbonImmutable::now()->getTimestamp() - $query['since'] : null;

        try {
            $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->activity($query));
        } catch (ForumCallException $e) {
            throw $this->onOlderAddOn($e, $span);
        }

        $data['headline'] = $this->headline($data);
        $data['notes'] = $this->notes($data);

        return Present::lead($data, ['headline', 'notes', 'window', 'registrations', 'threads', 'posts']);
    }

    /**
     * Hampel/AdminApi 1.0.0a11 measured the 31-day cap to its default `until` of now + 1, so it
     * refused exactly 31 days asked for with no `until`. Said only when the request could be
     * that case: a hint on every refusal told an agent over the limit on 1.0.0a12 to retry a
     * request that would be refused again. Delete once every forum is on 1.0.0a12.
     *
     * @param  int|null  $span  how far back `since` was asked for, when `until` was not
     */
    private function onOlderAddOn(ForumCallException $e, ?int $span): ForumCallException
    {
        $cause = $e->getPrevious();

        if (! $cause instanceof ApiException || ! $cause->hasCode(ForumGateway::CODE_WINDOW_TOO_LONG)
            || $span !== 31 * 86400) {
            return $e;
        }

        return new ForumCallException($e->getMessage().' If this forum runs Hampel/AdminApi 1.0.0a11, it refuses '
            .'exactly 31 days with no `until`, counting one second past now; give `until: "now"`.', 0, $cause);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $registrations = (int) ($data['registrations']['total'] ?? 0);
        $threads = (int) ($data['threads']['total'] ?? 0);
        $posts = (int) ($data['posts']['total'] ?? 0);

        if ($registrations + $threads + $posts === 0) {
            return 'Nobody registered and nothing was posted in this window.';
        }

        $states = (array) ($data['registrations']['by_state'] ?? []);
        $held = (int) ($data['threads']['by_state']['moderated'] ?? 0) + (int) ($data['posts']['by_state']['moderated'] ?? 0);
        $deleted = (int) ($data['threads']['by_state']['deleted'] ?? 0) + (int) ($data['posts']['by_state']['deleted'] ?? 0);

        return sprintf(
            '%s; %d %s and %d %s, %s.',
            $this->registrations($registrations, $states),
            $threads,
            $threads === 1 ? 'thread' : 'threads',
            $posts,
            $posts === 1 ? 'post' : 'posts',
            $held + $deleted === 0
                ? 'none awaiting approval or deleted'
                : sprintf('of which %d %s awaiting approval and %d deleted', $held, $held === 1 ? 'is' : 'are', $deleted),
        );
    }

    /**
     * Every state but `valid` that holds anyone, so that the headline alone does not leave a
     * reader inferring the rest are valid: a bounced or disabled member was read as one.
     *
     * @param  array<mixed>  $states
     */
    private function registrations(int $total, array $states): string
    {
        if ($total === 0) {
            return 'No registrations in this window';
        }

        $count = static fn (string ...$keys): int => array_sum(array_map(static fn (string $key): int => (int) ($states[$key] ?? 0), $keys));

        $parts = array_filter([
            'awaiting email confirmation' => $count('email_confirm', 'email_confirm_edit'),
            'awaiting approval' => $count('moderated'),
            'bounced' => $count('email_bounce'),
            'disabled' => $count('disabled'),
            'rejected' => $count('rejected'),
            'in a state XenForo does not define' => $count('other'),
        ]);

        $head = sprintf('%d %s in this window', $total, $total === 1 ? 'registration' : 'registrations');

        if ($parts === []) {
            return $head.($total === 1 ? ', valid' : ', all valid');
        }

        $listed = array_map(static fn (string $state, int $n): string => "{$n} {$state}", array_keys($parts), $parts);
        $last = array_pop($listed);

        return sprintf(
            '%s: %d valid, %s',
            $head,
            $count('valid'),
            $listed === [] ? $last : implode(', ', $listed).' and '.$last,
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function notes(array $data): array
    {
        $notes = ['Each is counted in the state it is in now. What was deleted outright is not counted at all.'];

        if ((int) ($data['threads']['total'] ?? 0) > 0) {
            $notes[] = '`posts` includes the opening post of each thread, so threads and posts are not to be added.';
        }

        $other = array_sum(array_map(
            static fn (string $part): int => (int) ($data[$part]['by_state']['other'] ?? 0),
            ['registrations', 'threads', 'posts'],
        ));

        if ($other > 0) {
            $notes[] = sprintf(
                '%d %s in a state XenForo does not define (`other`): an add-on on this forum changes core\'s tables.',
                $other,
                $other === 1 ? 'is' : 'are',
            );
        }

        return $notes;
    }
}
