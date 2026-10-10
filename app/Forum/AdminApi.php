<?php

namespace App\Forum;

use Hampel\XenForo\Api\Endpoint\Endpoint;

/**
 * The read-only endpoints the Hampel/AdminApi add-on adds to a forum.
 *
 * One class for all eight, because they are one add-on's surface and share nothing but a
 * prefix. Each method returns the decoded body untouched: shaping an answer for a reader is
 * App\Support\Present's job, and keeping it out of here keeps this a description of the
 * add-on's contract and nothing else.
 *
 * A forum without the add-on raises EndpointNotFoundException from any of them.
 */
final class AdminApi extends Endpoint
{
    /**
     * @param  array<string, scalar|null>  $query  since, until, limit
     * @return array<mixed>
     */
    public function errorLog(array $query = []): array
    {
        return $this->apiGet('admin-api/error-log/', $query)->data;
    }

    /**
     * The one endpoint that returns content. Needs the detail scope, not the read scope.
     *
     * @return array<mixed>
     */
    public function errorEntries(int $errorId, ?int $limit = null, bool $request = false): array
    {
        return $this->apiGet('admin-api/error-entries/', array_filter([
            'error_id' => $errorId,
            'limit' => $limit,
            'request' => $request ? 1 : null,
        ], static fn ($value): bool => $value !== null))->data;
    }

    /**
     * The same endpoint with no entry named, which can only ever be refused. app:validate
     * and the forum-access tool use the refusal to tell what a key may do without reading
     * an entry.
     *
     * @return array<mixed>
     */
    public function errorEntriesProbe(): array
    {
        return $this->apiGet('admin-api/error-entries/')->data;
    }

    /** @return array<mixed> */
    public function addOns(): array
    {
        return $this->apiGet('admin-api/add-ons/')->data;
    }

    /**
     * Counts of who is online. Never a member and never an address.
     *
     * @return array<mixed>
     */
    public function online(): array
    {
        return $this->apiGet('admin-api/online/')->data;
    }

    /** @return array<mixed> */
    public function cron(): array
    {
        return $this->apiGet('admin-api/cron/')->data;
    }

    /** @return array<mixed> */
    public function jobs(): array
    {
        return $this->apiGet('admin-api/jobs/')->data;
    }

    /**
     * `$optionIds` asks for exactly those options, changed or not, and overrides the other
     * two. An add-on older than 1.0.0a6 ignores it and returns the list: the reply then has
     * no `not_found`, which is how to tell.
     *
     * @param  list<string>  $optionIds
     * @return array<mixed>
     */
    public function options(?string $addOnId = null, bool $changed = false, array $optionIds = []): array
    {
        return $this->apiGet('admin-api/options/', array_filter([
            'add_on_id' => $addOnId,
            'changed' => $changed ? 1 : null,
            'option_id' => $optionIds === [] ? null : implode(',', $optionIds),
        ], static fn ($value): bool => $value !== null))->data;
    }

    /**
     * `$keys` asks for those settings and everything beneath each, and overrides `$changed`.
     * Ignored by an add-on older than 1.0.0a6, as for options().
     *
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    public function config(bool $changed = false, array $keys = []): array
    {
        return $this->apiGet('admin-api/config/', array_filter([
            'changed' => $changed ? 1 : null,
            'key' => $keys === [] ? null : implode(',', $keys),
        ], static fn ($value): bool => $value !== null))->data;
    }
}
