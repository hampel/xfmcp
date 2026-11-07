<?php

namespace App\Forum;

use App\Support\Forum;
use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Closure;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Exception\ApiException;
use Hampel\XenForo\Api\Exception\EndpointNotFoundException;
use Hampel\XenForo\Api\Exception\MalformedResponseException;
use Hampel\XenForo\Api\Exception\NotAuthenticatedException;
use Hampel\XenForo\Api\Exception\NotFoundException;
use Hampel\XenForo\Api\Exception\NotPermittedException;
use Hampel\XenForo\Api\Exception\RequestException;
use Hampel\XenForo\Api\Exception\ServerException;
use Hampel\XenForo\Api\Exception\XenForoException;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Throwable;

/**
 * Asks one forum one thing, and turns every way that can fail into a sentence.
 *
 * THERE IS NO AUTHORISATION HERE, and none should be added. The add-on refuses a super user
 * key, then asks for the scope, then for an admin permission held by the key's own user, so
 * what a key may read is decided on the forum. The only choice made on this side is which
 * of a forum's two keys a call is sent with.
 *
 * The explanations matter more than they look. Every refusal the add-on makes is a 403, a
 * forum without the add-on is a 404, and a WAF in front of the forum can answer either -
 * so the status says almost nothing and XenForo's own error code says nearly everything.
 */
class ForumGateway
{
    /** every summary, on an add-on before 1.0.0a11; gone from 1.0.0a11 */
    public const SCOPE_READ = 'hampel_admin_api:read';

    /**
     * From the add-on's 1.0.0a11, each summary endpoint has a scope of its own and the read
     * key holds the ones it is to answer. Endpoint to scope, as the add-on's README has it.
     */
    public const SUMMARY_SCOPES = [
        'admin-api/error-log/' => 'hampel_admin_api:error_log',
        'admin-api/logs/' => 'hampel_admin_api:logs',
        'admin-api/add-ons/' => 'hampel_admin_api:add_ons',
        'admin-api/cron/' => 'hampel_admin_api:jobs',
        'admin-api/jobs/' => 'hampel_admin_api:jobs',
        'admin-api/options/' => 'hampel_admin_api:options',
        'admin-api/config/' => 'hampel_admin_api:config',
        'admin-api/online/' => 'hampel_admin_api:online',
        'admin-api/user-upgrades/' => 'hampel_admin_api:user_upgrades',
        'admin-api/approval-queue/' => 'hampel_admin_api:approval_queue',
        'admin-api/reports/' => 'hampel_admin_api:reports',
        'admin-api/activity/' => 'hampel_admin_api:activity',
    ];

    public const SCOPE_DETAIL = 'hampel_admin_api:error_detail';

    /** adds values to the options and configuration answers; never held alone */
    public const SCOPE_VALUES = 'hampel_admin_api:values';

    public const CODE_KEY_TYPE = 'hampel_adminapi_user_api_key_required';

    /** from 1.0.0a11: a key allowed all scopes reaches none of the add-on's */
    public const CODE_SCOPE_MUST_BE_SELECTED = 'hampel_adminapi_scope_must_be_selected';

    public const CODE_WINDOW_TOO_LONG = 'hampel_adminapi_window_too_long';

    public const CODE_ERROR_ID_REQUIRED = 'hampel_adminapi_error_id_required';

    public function __construct(
        private readonly ForumInventory $inventory,
        private readonly XenForoManager $xenforo,
    ) {}

    /**
     * @throws ForumCallException
     */
    public function forum(string $id): Forum
    {
        try {
            return $this->inventory->get($id);
        } catch (ForumInventoryException $e) {
            throw new ForumCallException('The forum inventory cannot answer: '.$e->getMessage().'.', 0, $e);
        }
    }

    /**
     * Call the add-on with the forum's read key.
     *
     * @param  Closure(AdminApi): array<mixed>  $call
     * @return array<mixed>
     *
     * @throws ForumCallException
     */
    public function read(Forum $forum, Closure $call): array
    {
        return $this->send($forum, $forum->key, 'key', $call);
    }

    /**
     * Call the add-on with the forum's detail key, which only the error entries tool does.
     *
     * @param  Closure(AdminApi): array<mixed>  $call
     * @return array<mixed>
     *
     * @throws ForumCallException
     */
    public function detail(Forum $forum, Closure $call): array
    {
        if ($forum->detailKey === null) {
            throw new ForumCallException(sprintf(
                'Forum "%s" has no detail key configured, so individual error entries cannot be read from it. '
                .'That is a deliberate setting, not a fault: the summary tools are everything this forum offers.',
                $forum->id,
            ));
        }

        return $this->send($forum, $forum->detailKey, 'detail_key', $call);
    }

    /**
     * A client for one of a forum's keys. `key` alone, never `key` with `user`: the pair
     * builds a super user key, which the add-on refuses.
     */
    public function client(Forum $forum, #[\SensitiveParameter] string $key): Client
    {
        return $this->xenforo->build(['url' => $forum->url, 'key' => $key]);
    }

    /**
     * @param  Closure(AdminApi): array<mixed>  $call
     * @return array<mixed>
     */
    private function send(Forum $forum, #[\SensitiveParameter] string $key, string $which, Closure $call): array
    {
        $api = null;

        try {
            $api = $this->client($forum, $key)->endpoint(AdminApi::class);

            return $call($api);
        } catch (XenForoException $e) {
            throw new ForumCallException($this->explain($forum, $which, $e, $api?->lastPath()), 0, $e);
        } catch (Throwable $e) {
            // Anything else is a fault on this side of the wire. Named by class only: the
            // message of an arbitrary throwable is not known to be free of a URL or a key.
            report($e);

            throw new ForumCallException(sprintf(
                'Forum "%s" could not be asked: this server failed before getting an answer (%s). '
                .'That is a fault in the MCP server, not a statement about the forum.',
                $forum->id,
                $e::class,
            ), 0, $e);
        }
    }

    /**
     * Why a call failed, for someone who cannot see the response.
     *
     * @param  string  $which  the inventory setting the key came from: `key` or `detail_key`
     * @param  string|null  $path  the endpoint asked, when known, to name the permission it needs
     */
    public function explain(Forum $forum, string $which, XenForoException $e, ?string $path = null): string
    {
        $name = sprintf('Forum "%s"', $forum->id);

        if ($e instanceof RequestException) {
            return "{$name} could not be reached: the request never got an answer (DNS, TLS, connection or timeout). "
                .'This says nothing about the forum\'s state beyond that it did not answer from here.';
        }

        if ($e instanceof MalformedResponseException) {
            return "{$name} answered with something that is not JSON - a maintenance page, a WAF challenge or a "
                .'proxy error, not the XenForo API. The forum may be down or closed; this is not an empty result.';
        }

        if (! $e instanceof ApiException) {
            return "{$name} could not be asked: ".$e->getMessage();
        }

        if ($e instanceof ServerException) {
            return "{$name} answered HTTP {$e->statusCode}: the forum or its web server is failing. "
                .'The error itself will be in the forum\'s own server error log or the host\'s logs.';
        }

        // XenForo's API puts errors[] on every refusal it makes. A 4xx without one was
        // produced by something in front of the forum, and is not evidence about the key.
        if ($e->errors() === []) {
            return "{$name} answered HTTP {$e->statusCode} with no XenForo error code, so the answer did not come "
                .'from the XenForo API - most likely a CDN, WAF or proxy in front of it. This is not evidence that '
                .'the API key is wrong.';
        }

        $codes = implode(', ', $e->codes());

        if ($e instanceof EndpointNotFoundException) {
            return "{$name} has no such endpoint (endpoint_not_found): the Hampel/AdminApi add-on is not installed "
                .'or not active there, or is a version older than this endpoint, which the add-ons tool would show. '
                .'It needs XenForo 2.2 or later. This is a fact about how that forum is set '
                .'up, not an empty result.';
        }

        if ($e->hasCode(self::CODE_KEY_TYPE)) {
            return "{$name} refused the key ({$codes}): `{$which}` is a super user key or a guest key, and the "
                .'add-on only accepts a User key belonging to one administrator. The key needs replacing in the '
                .'forum\'s admin control panel and in the inventory.';
        }

        if ($e->hasCode(self::CODE_SCOPE_MUST_BE_SELECTED)) {
            return "{$name} refused the key ({$codes}): `{$which}` is allowed all scopes, and from Hampel/AdminApi "
                .'1.0.0a11 that reaches none of the add-on\'s. '.$this->messages($e).' The key\'s scopes are set in '
                .'the forum\'s admin control panel; the key itself is fine.';
        }

        if ($e->hasCode('missing_scope')) {
            return "{$name} refused the key (missing_scope): `{$which}` does not hold the API scope this endpoint "
                .'needs. '.$this->messages($e).' The scope is set on the key in the forum\'s admin control panel.';
        }

        if ($e->hasCode('do_not_have_permission')) {
            $legacy = AdminApi::PERMISSIONS[$path ?? ''] ?? null;

            return "{$name} refused the key (do_not_have_permission): the administrator `{$which}` belongs to does "
                .'not hold the admin permission it needs. From Hampel/AdminApi 1.0.0a11 that is "'.AdminApi::PERMISSION.'"'
                .($legacy === null ? '' : "; on an older add-on, {$legacy}")
                .(in_array($path, [null, 'admin-api/error-entries/'], true) ? '. Reading error entries also needs XenForo\'s own "View logs"' : '')
                .'. It is granted on the administrator in the forum\'s admin control panel; the key is fine.';
        }

        if ($e instanceof NotAuthenticatedException || $e instanceof NotPermittedException) {
            return "{$name} rejected the key ({$codes}): ".$this->messages($e)
                .' The key in the inventory is not usable on this forum.';
        }

        if ($e instanceof NotFoundException) {
            return "{$name} has nothing at that id ({$codes}). An error log entry disappears when XenForo prunes "
                .'the log or an administrator clears it; take a current last_error_id from the error log summary.';
        }

        if ($e->hasCode(self::CODE_WINDOW_TOO_LONG)) {
            return "{$name} rejected the request ({$codes}): ".$this->messages($e)
                .' For a longer period, ask in consecutive windows and add the counts. From Hampel/AdminApi '
                .'1.0.0a11 a window excludes `until`, so each `until` is the next `since`; before it both ends are '
                .'counted, so start each window one second after the previous `until`.';
        }

        return "{$name} rejected the request (HTTP {$e->statusCode}; {$codes}): ".$this->messages($e);
    }

    /**
     * XenForo's own wording for each error. Phrased by the forum from fixed text and the
     * names of scopes and parameters, so safe to pass on; the raw body never is.
     */
    private function messages(ApiException $e): string
    {
        $messages = array_filter(array_map(static fn ($error): string => trim($error->message), $e->errors()));

        return $messages === [] ? '' : rtrim(implode(' ', $messages), '.').'.';
    }
}
