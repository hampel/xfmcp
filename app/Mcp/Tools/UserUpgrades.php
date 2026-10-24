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
    Count a forum's user upgrades (paid memberships): how many members hold each, what ends soon, what expired,
    and what was paid. Counts only - never which member.

    Read `headline` first. `days` (default 30, maximum 366) is both how far ahead `ending` looks and how far back
    `expired` and `payments` look.

    Easy to misread:
    - `active` counts every current record, `permanent` those that never end, `ending` those ending in the next
      `days`. `expired` counts records that ended in the last `days`, early or not.
    - `cancelled` is a subscription cancelled at the payment provider that still runs to its end date. XenForo 2.2
      does not record cancellation, so there it is null: not recorded, never zero.
    - A payment is one the provider reported - one for a purchase and one per renewal. Its `amount` is the
      upgrade's own price, so a refund, a fee or a currency conversion is not reflected. It is not revenue.
    - A null `provider` is one the forum no longer has.
    - `ending_by_day` runs from today to `days` ahead and `expired_by_day` from `days` back to today, in UTC days;
      both include today. Only days with a record are listed: a day not listed had none.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class UserUpgrades extends ForumTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'forum' => $this->forumArgument($schema),
            'days' => $schema->integer()->description('How far ahead to count what ends, and back to count what expired and was paid. Default 30, maximum 366.'),
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
        $days = $this->integer($request->get('days'), 1, 366);

        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->userUpgrades($days));

        $data['headline'] = $this->headline($data);
        $data['notes'] = $this->notes($data);

        foreach (['ending_by_day', 'expired_by_day'] as $breakdown) {
            if (is_array($data[$breakdown] ?? null)) {
                $data[$breakdown] = Present::busyDays($data[$breakdown]);
            }
        }

        return Present::lead($data, ['headline', 'notes', 'days', 'active', 'ending', 'expired', 'payments', 'upgrades']);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $upgrades = count((array) ($data['upgrades'] ?? []));
        $days = (int) ($data['days'] ?? 30);

        if ($upgrades === 0) {
            return 'This forum has no user upgrades.';
        }

        $payments = (int) ($data['payments']['total'] ?? 0);

        return sprintf(
            '%d active %s across %d %s; %d %s in the next %d days and %d expired in the last %d. %d %s reported in the last %d days.',
            $active = (int) ($data['active'] ?? 0),
            $active === 1 ? 'record' : 'records',
            $upgrades,
            $upgrades === 1 ? 'upgrade' : 'upgrades',
            $ending = (int) ($data['ending'] ?? 0),
            $ending === 1 ? 'ends' : 'end',
            $days,
            (int) ($data['expired'] ?? 0),
            $days,
            $payments,
            $payments === 1 ? 'payment' : 'payments',
            $days,
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function notes(array $data): array
    {
        $notes = [];

        foreach ((array) ($data['upgrades'] ?? []) as $upgrade) {
            if (is_array($upgrade) && array_key_exists('cancelled', $upgrade) && $upgrade['cancelled'] === null) {
                $notes[] = '`cancelled` is null: this forum does not record a cancelled subscription (XenForo 2.2). '
                    .'It is not zero.';

                break;
            }
        }

        // said here as well as in the description, which a client may have cached from an older build
        $notes[] = '`ending_by_day` and `expired_by_day` list only days with a record, and both ranges include today. '
            .'An empty list means no record ends or expired in the range.';

        if ((int) ($data['payments']['total'] ?? 0) > 0) {
            $notes[] = 'Payment amounts are each upgrade\'s list price, not what the provider took: refunds, fees and '
                .'conversions are not reflected.';
        }

        return $notes;
    }
}
