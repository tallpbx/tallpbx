<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service for managing tenant SIP identity realms and domains.
 */
interface TenantDomainServiceInterface
{
    /**
     * Create a new tenant domain.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TenantDomain;

    /**
     * Update an existing tenant domain.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(TenantDomain $domain, array $data): TenantDomain;

    /**
     * Delete a tenant domain.
     */
    public function delete(TenantDomain $domain): void;

    /**
     * Get all tenant domains.
     *
     * @return Collection<int, TenantDomain>
     */
    public function all(): Collection;

    /**
     * Get all domains for a specific tenant.
     *
     * @return Collection<int, TenantDomain>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Find a single tenant domain by its domain name.
     *
     * When exactly one enabled tenant domain matches, returns it.
     * When multiple tenants share the same domain (ambiguous),
     * returns null — callers must use findAllByDomain() and
     * resolve the ambiguity with additional identity data.
     *
     * @deprecated Prefer findAllByDomain() for shared-domain safety.
     */
    public function findByDomain(string $domain): ?TenantDomain;

    /**
     * Find all enabled tenant domains matching a domain name.
     *
     * Returns a collection that may contain zero, one, or multiple
     * results (e.g., when multiple tenants share the same SIP domain).
     * Callers must handle the multi-match case deliberately.
     *
     * @return Collection<int, TenantDomain>
     */
    public function findAllByDomain(string $domain): Collection;
}
