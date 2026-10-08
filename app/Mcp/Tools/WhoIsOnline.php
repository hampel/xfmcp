<?php

namespace App\Mcp\Tools;

use App\Forum\AdminApi;
use App\Forum\ForumGateway;
use App\Support\Forum;
use App\Support\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Count who a forum considers online right now: members, guests, and robots it recognised. Use it for "is
    something hammering this forum?". Counts only - no member is named and no address is returned.

    Read `headline` first. Then:
    - `guests` is where robots that do not identify themselves end up, so a guest count far above normal is as
      likely a crawler as a crowd.
    - `guest_networks` tells a crowd from a swarm. XenForo keeps one session per guest address. `largest_24` is
      how many of them sit in the single busiest /24 network and `distinct_24` how many /24s there are; the
      same for /16. Forty guests across forty networks are visitors; six hundred in one /24 is one operator.
      These figures are IPv4 only: `sessions` less `ipv4` is the IPv6 sessions, counted and not grouped.
    - `by_robot` names the recognised robots, most numerous first; a null `robot_key` is the rest.
    - `by_action` is what sessions were last doing - controller and action, never which thread or page.
    - `errors` is how many sessions' last request ended in an error.
    - `window_minutes` is the forum's own online timeout: a session counts if it was active in that long.

    This is a snapshot, and each call gives a different one. It reports the figures; whether they are too many
    depends on what is normal for that forum, which this tool does not know.
    TEXT)]
#[IsReadOnly]
class WhoIsOnline extends ForumTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return parent::outputSchema($schema) + [
            'headline' => $schema->string()->description('Who is online, in one sentence.')->required(),
        ];
    }

    protected function answer(Request $request, ForumGateway $gateway, Forum $forum): array
    {
        $data = $gateway->read($forum, static fn (AdminApi $api): array => $api->online());

        $data['headline'] = $this->headline($data);

        return Present::lead($data, [
            'headline', 'now', 'window_minutes', 'total', 'members', 'guests', 'robots', 'errors', 'newest_date',
            'guest_networks', 'robot_networks',
        ]);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function headline(array $data): string
    {
        $total = (int) ($data['total'] ?? 0);
        $window = (int) ($data['window_minutes'] ?? 0);

        if ($total === 0) {
            return sprintf('Nobody is online: no session in the last %d minutes.', $window);
        }

        $headline = sprintf(
            '%d online in the last %d minutes: %d %s, %d %s, %d recognised %s.',
            $total,
            $window,
            $members = (int) ($data['members'] ?? 0),
            $members === 1 ? 'member' : 'members',
            $guests = (int) ($data['guests'] ?? 0),
            $guests === 1 ? 'guest' : 'guests',
            $robots = (int) ($data['robots'] ?? 0),
            $robots === 1 ? 'robot' : 'robots',
        );

        $ipv4 = (int) ($data['guest_networks']['ipv4'] ?? 0);
        $largest = (int) ($data['guest_networks']['largest_24'] ?? 0);

        // one guest is always 100% of its own network; a share means nothing until there are a few
        if ($ipv4 >= 10) {
            $headline .= sprintf(
                ' The busiest /24 network holds %d of the %d IPv4 guests (%d%%), across %d networks.',
                $largest,
                $ipv4,
                (int) round($largest / $ipv4 * 100),
                (int) ($data['guest_networks']['distinct_24'] ?? 0),
            );
        }

        return $headline;
    }
}
