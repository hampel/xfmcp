<?php

namespace App\Forum;

use Hampel\XenForo\Api\Endpoint\Endpoint;

/**
 * The read-only endpoints the Hampel/AdminApi add-on adds to a forum.
 *
 * One class for all seven, because they are one add-on's surface and share nothing but a
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
     * uses the refusal to tell what kind of key it holds without reading an entry.
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
     * @return array<mixed>
     */
    public function options(?string $addOnId = null, bool $changed = false): array
    {
        return $this->apiGet('admin-api/options/', array_filter([
            'add_on_id' => $addOnId,
            'changed' => $changed ? 1 : null,
        ], static fn ($value): bool => $value !== null))->data;
    }

    /** @return array<mixed> */
    public function config(bool $changed = false): array
    {
        return $this->apiGet('admin-api/config/', $changed ? ['changed' => 1] : [])->data;
    }
}
