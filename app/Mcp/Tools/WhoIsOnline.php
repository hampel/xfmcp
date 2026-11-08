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
    - `robot_networks` is the same six figures for the robots the forum recognised. A recognised robot
      concentrated in one network is usually one crawler's address range, which is ordinary; it matters when
      a robot the forum trusts by name is arriving from far more networks than that operator has.
    - `shared_networks` says whether one source is behind guests and robots both, without saying which network.
      `distinct_24` is how many /24 networks hold guest and robot sessions at once, and `guests_24` and
      `robots_24` how many of each sit in them; the same for /16. One shared /24 holding most of the busiest
      network's guests is a swarm that identifies itself on some requests and not others.
    - `by_robot` names the recognised robots, most numerous first; a null `robot_key` is the rest.
    - `by_action` is what sessions were last doing - controller and action, never which thread or page. It
      lists the busiest; a last entry with a null `controller` and `action` is everything else added together,
      not an unknown action. Without that entry the list may be complete, or may be an older forum leaving
      the rest out: compare its counts with `total`.
    - `errors` is how many sessions' last recorded page was an error reply or had a 4xx or 5xx status: not found,
      no permission, login required, a server exception. A redirect or a background (XHR) request is not
      recorded, so it is not a session's last page. It is part of `total`, never added to it.
      `errors_by_kind` splits it into members, guests and robots, and each `by_action` entry has its own
      `errors`. An error is listed under the action that answered it - a thread not found under the thread's;
      only `ErrorController` / `DispatchError` is a request XenForo could not route.
    - `errors_by_kind`, `shared_networks` and each entry's `errors` come from Hampel/AdminApi 1.0.0a14. From an
      older forum they are absent, which means unknown, not zero.
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
            'headline', 'now', 'window_minutes', 'total', 'members', 'guests', 'robots', 'errors', 'errors_by_kind',
            'newest_date', 'guest_networks', 'robot_networks', 'shared_networks',
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

        $errors = (int) ($data['errors'] ?? 0);
        $kinds = is_array($data['errors_by_kind'] ?? null) ? $data['errors_by_kind'] : null;

        if ($errors > 0) {
            $headline .= sprintf(
                ' %d of these %s last recorded page was an error%s.',
                $errors,
                $errors === 1 ? 'session\'s' : 'sessions\'',
                $kinds === null
                    ? ', of whatever kind of visitor'
                    : sprintf(
                        ': %d from members, %d from guests, %d from robots',
                        (int) ($kinds['members'] ?? 0),
                        (int) ($kinds['guests'] ?? 0),
                        (int) ($kinds['robots'] ?? 0),
                    ),
            );
        }

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

            $shared = is_array($data['shared_networks'] ?? null) ? $data['shared_networks'] : null;

            if ($shared !== null && $robots > 0) {
                $networks = (int) ($shared['distinct_24'] ?? 0);

                $headline .= $networks === 0
                    ? ' No /24 network holds both guests and recognised robots.'
                    : sprintf(
                        ' %d /24 %s both guests and recognised robots: %d guests and %d robots.',
                        $networks,
                        $networks === 1 ? 'network holds' : 'networks hold',
                        (int) ($shared['guests_24'] ?? 0),
                        (int) ($shared['robots_24'] ?? 0),
                    );
            }
        }

        return $headline;
    }
}
