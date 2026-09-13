<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantDomain;
use App\Support\AclConfigurationCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Concrete implementation of TenantDomainServiceInterface.
 *
 * Manages tenant SIP identity realms and domain aliases.
 */
class TenantDomainService implements TenantDomainServiceInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): TenantDomain
    {
        $domain = TenantDomain::create($data);

        // Keep the served acl.conf domains list fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();

        return $domain;
    }

    /**
     * {@inheritdoc}
     */
    public function update(TenantDomain $domain, array $data): TenantDomain
    {
        $domain->update($data);

        $domain = $domain->fresh();

        // Keep the served acl.conf domains list fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();

        return $domain;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(TenantDomain $domain): void
    {
        $domain->delete();

        // Keep the served acl.conf domains list fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        return TenantDomain::query()
            ->with('tenant')
            ->orderBy('domain')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function getByTenant(int $tenantId): Collection
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('domain')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findByDomain(string $domain): ?TenantDomain
    {
        $matches = $this->findAllByDomain($domain);

        // Fail-closed: when multiple tenants share the same domain,
        // return null to force callers to resolve ambiguity.
        if ($matches->count() !== 1) {
            if ($matches->count() > 1) {
                Log::warning('TenantDomainService: ambiguous domain lookup — multiple tenants match.', [
                    'domain' => $domain,
                    'tenant_count' => $matches->count(),
                ]);
            }

            return null;
        }

        return $matches->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findAllByDomain(string $domain): Collection
    {
        return TenantDomain::query()
            ->where('domain', $domain)
            ->where('enabled', true)
            ->with('tenant')
            ->get();
    }
}
