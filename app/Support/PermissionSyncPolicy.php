<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure policy deciding whether permission synchronization to the database should run.
 */
class PermissionSyncPolicy
{
    /**
     * Determine whether permissions should be synchronized to the database.
     *
     * Console callers are decided by registry hash comparison only, never by request path.
     * HTTP requests on the mod_xml_curl hot path (api/v1/xml-handler*) are never synced.
     * All other callers sync when the cached hash is missing or stale.
     */
    public function shouldSync(?string $requestPath, bool $runningInConsole, ?string $cachedHash, string $currentHash): bool
    {
        if ($runningInConsole) {
            return $cachedHash !== $currentHash;
        }

        if ($requestPath !== null) {
            $normalized = ltrim($requestPath, '/');
            if (str_starts_with($normalized, 'api/v1/xml-handler')) {
                return false;
            }
        }

        return $cachedHash !== $currentHash;
    }
}
