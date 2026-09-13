<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Helper for generating and parsing tenant-specific FreeSWITCH
 * dialplan context names.
 *
 * FreeSWITCH uses context strings to group dialplan extensions.
 * Each tenant gets two contexts:
 *   - tenant_{uuid}_internal — for calls between extensions
 *   - tenant_{uuid}_public   — for inbound calls from PSTN
 *
 * Usage:
 *   $context = new DialplanContext;
 *   $internal = $context->internal($tenant->id);  // tenant_uuid_internal
 *   $tenantId = $context->parseTenantId($context); // uuid
 */
class DialplanContext
{
    /**
     * Regex pattern for matching tenant context names.
     * Matches: tenant_{uuid}_internal or tenant_{uuid}_public
     */
    private const TENANT_PATTERN = '/^tenant_([\w-]+)_(internal|public)$/';

    /**
     * Generate an internal context name for a tenant.
     *
     * Used for calls originating from within the tenant (extension-to-extension).
     */
    public function internal(string $tenantId): string
    {
        return "tenant_{$tenantId}_internal";
    }

    /**
     * Generate a public context name for a tenant.
     *
     * Used for inbound calls from external/PSTN sources.
     */
    public function public(string $tenantId): string
    {
        return "tenant_{$tenantId}_public";
    }

    /**
     * Extract the tenant UUID from a context name.
     *
     * Returns the UUID string if the context matches the tenant pattern,
     * or null if the context is not a tenant context.
     */
    public function parseTenantId(string $context): ?string
    {
        if (preg_match(self::TENANT_PATTERN, $context, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check whether a context name is a tenant-scoped context.
     */
    public function isTenantContext(string $context): bool
    {
        return $this->parseTenantId($context) !== null;
    }
}
