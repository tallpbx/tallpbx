<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service interface for resolving tenant identity from SIP contexts.
 *
 * Provides methods to resolve a tenant from a SIP domain name, a
 * username (with optional domain), or SIP authentication credentials.
 * Each method returns a TenantIdentity value object when a match
 * is found, or null when resolution fails.
 */
interface TenantIdentityResolverInterface
{
    /**
     * Resolve tenant from a SIP domain name.
     *
     * Looks up the domain in the tenant_domains table and, if found
     * and enabled, returns the associated tenant identity.
     */
    public function resolveFromDomain(string $domain): ?TenantIdentity;

    /**
     * Resolve tenant from a username, optionally scoped to a domain.
     *
     * When a domain is provided, looks up a domain_username or hybrid
     * SIP account matching the username within that domain. Without a
     * domain, looks for a unique global_username account.
     *
     * Returns null when the username is not found, disabled, or
     * ambiguous (multiple global matches across tenants).
     */
    public function resolveFromUsername(string $username, ?string $domain = null): ?TenantIdentity;

    /**
     * Resolve tenant from SIP authentication credentials.
     *
     * Similar to resolveFromUsername but designed for FreeSWITCH
     * authentication flows. Uses the auth username and optional
     * domain to resolve the tenant identity.
     *
     * Will be enhanced in future tasks to support global_auth_key
     * pattern matching for faster lookups.
     */
    public function resolveFromSipAuth(string $authUsername, ?string $domain = null): ?TenantIdentity;
}
