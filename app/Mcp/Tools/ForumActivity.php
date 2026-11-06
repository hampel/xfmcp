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
      address, `moderated` awaiting approval, `email_bounce` a member whose mail bounced. `other` is a state XenForo
      does not define, which only an add-on changing core's tables could make.
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
            'until' => $schema->string()->description('End of the window, in the same forms. Defaults to now.'),
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
        $waiting = (int) ($states['email_confirm'] ?? 0) + (int) ($states['email_confirm_edit'] ?? 0);
        $moderated = (int) ($states['moderated'] ?? 0);

        $held = (int) ($data['threads']['by_state']['moderated'] ?? 0) + (int) ($data['posts']['by_state']['moderated'] ?? 0);
        $deleted = (int) ($data['threads']['by_state']['deleted'] ?? 0) + (int) ($data['posts']['by_state']['deleted'] ?? 0);

        return sprintf(
            '%d %s in this window, %d of them awaiting email confirmation and %d awaiting approval; %d %s and %d %s, %s.',
            $registrations,
            $registrations === 1 ? 'registration' : 'registrations',
            $waiting,
            $moderated,
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
