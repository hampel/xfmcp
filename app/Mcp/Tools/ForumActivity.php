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
    - `by_day` and `by_hour` (UTC) list only the days and hours with something in them: a missing one had none.
      `by_hour` is given for a window of two days or less, and is null for a longer one.
    - `registrations.by_source` tells a sign-up from an account made for a member. `register_form` is a sign-up,
      through the form or a connected account; `other` is an account made in the admin control panel or through
      the API - and also a sign-up whose IP log row XenForo's clean-up has since deleted.
      `by_source_complete: false` means the window starts earlier than that log keeps, so some sign-ups are
      probably counted as `other`. Absent on a forum whose add-on is older than 1.0.0a15.
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

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->activity($query));

        foreach (['registrations', 'threads', 'posts'] as $part) {
            foreach (['by_day', 'by_hour'] as $series) {
                if (is_array($data[$part][$series] ?? null)) {
                    $data[$part][$series] = Present::busyDays($data[$part][$series]);
                }
            }
        }

        $data['headline'] = $this->headline($data);
        $data['notes'] = $this->notes($data);

        return Present::lead($data, ['headline', 'notes', 'window', 'registrations', 'threads', 'posts']);
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
            '%s; %d %s and %d %s, %s.%s',
            $this->registrations($registrations, $states),
            $threads,
            $threads === 1 ? 'thread' : 'threads',
            $posts,
            $posts === 1 ? 'post' : 'posts',
            $held + $deleted === 0
                ? 'none awaiting approval or deleted'
                : sprintf('of which %d %s awaiting approval and %d deleted', $held, $held === 1 ? 'is' : 'are', $deleted),
            $this->sources($registrations, $data['registrations'] ?? []),
        );
    }

    /**
     * How many registrations did not come through the register form: on a monitored forum,
     * accounts made for monitoring through the API, which counted as sign-ups until the
     * add-on's 1.0.0a15 and sent two investigations after a registration trigger (2026-11-09).
     */
    private function sources(int $registrations, mixed $part): string
    {
        $other = is_array($part) && is_array($part['by_source'] ?? null) ? (int) ($part['by_source']['other'] ?? 0) : 0;

        if ($registrations === 0 || $other === 0) {
            return '';
        }

        return $other === $registrations
            ? match ($registrations) {
                1 => ' The registration did not come through the register form.',
                2 => ' Neither registration came through the register form.',
                default => " None of the {$registrations} registrations came through the register form.",
            }
        : sprintf(' %d of the %d registrations did not come through the register form.', $other, $registrations);
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

        $registrations = $data['registrations'] ?? [];

        if (is_array($registrations) && (int) ($registrations['by_source']['other'] ?? 0) > 0) {
            $notes[] = 'A registration not through the register form (`by_source.other`) is an account made in the admin '
                .'control panel or through the API, or a sign-up whose IP log row has since been cleaned up.';

            if (($registrations['by_source_complete'] ?? true) === false) {
                $notes[] = 'This window starts earlier than this forum keeps its IP log, so some of those are probably sign-ups.';
            }
        }

        $series = false;

        foreach (['registrations', 'threads', 'posts'] as $part) {
            $series = $series || is_array($data[$part]['by_day'] ?? null);
        }

        if ($series) {
            $notes[] = '`by_day` and `by_hour` list only days and hours with something in them; a missing one had none.';
        }

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
