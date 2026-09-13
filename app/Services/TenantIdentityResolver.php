<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Resolves tenant identity from SIP domain, username, or auth context.
 *
 * This service centralizes tenant resolution logic that was previously
 * inlined in XmlHandlerController. It handles three identity modes
 * (global_username, domain_username, hybrid) and returns a structured
 * TenantIdentity value object for each successful resolution.
 *
 * Resolution order:
 *   1. Domain + username match → domain_username or hybrid account
 *   2. Domain only → any tenant with a matching domain
 *   3. Username only → unique global_username account
 *   4. Reject ambiguous, disabled, or missing matches
 */
class TenantIdentityResolver implements TenantIdentityResolverInterface
{
    /**
     * Create a new resolver instance.
     */
    public function __construct(
        private readonly TenantDomainServiceInterface $domainService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolveFromDomain(string $domain): ?TenantIdentity
    {
        if ($domain === '') {
            return null;
        }

        $cached = $this->rememberIdentity(
            $this->cacheKey('domain', [$domain]),
            fn (): ?TenantIdentity => $this->resolveFromDomainUncached($domain),
        );

        return $cached;
    }

    /**
     * Resolve a tenant from a domain without using the identity cache.
     */
    private function resolveFromDomainUncached(string $domain): ?TenantIdentity
    {
        $tenantDomain = $this->domainService->findByDomain($domain);

        if ($tenantDomain === null) {
            return null;
        }

        // Ensure the tenant relationship is loaded for the tenant ID
        if (! $tenantDomain->relationLoaded('tenant')) {
            $tenantDomain->load('tenant');
        }

        if ($tenantDomain->tenant === null) {
            return null;
        }

        return new TenantIdentity(
            tenantId: (string) $tenantDomain->tenant->id,
            tenantDomainId: $tenantDomain->id,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function resolveFromUsername(string $username, ?string $domain = null): ?TenantIdentity
    {
        if ($username === '') {
            return null;
        }

        return $this->rememberIdentity(
            $this->cacheKey('username', [$username, $domain ?? '']),
            fn (): ?TenantIdentity => $this->resolveFromUsernameUncached($username, $domain),
        );
    }

    /**
     * Resolve a tenant from a username without using the identity cache.
     */
    private function resolveFromUsernameUncached(string $username, ?string $domain = null): ?TenantIdentity
    {
        if ($domain !== null && $domain !== '') {
            return $this->resolveDomainUsername($username, $domain);
        }

        return $this->resolveGlobalUsername($username);
    }

    /**
     * {@inheritdoc}
     */
    public function resolveFromSipAuth(string $authUsername, ?string $domain = null): ?TenantIdentity
    {
        // For the initial implementation, delegates to resolveFromUsername.
        // Future tasks will enhance this with global_auth_key pattern matching
        // and faster lookup strategies for FreeSWITCH authentication flows.
        return $this->resolveFromUsername($authUsername, $domain);
    }

    /**
     * Resolve a username scoped to a specific domain.
     *
     * Looks up all tenant domains matching the domain string
     * (supports shared domains across tenants), then finds the
     * matching SIP account by username within each domain.
     * Supports domain_username and hybrid modes.
     *
     * Returns null when no domain match, no SIP account match,
     * or when the same username matches multiple tenants
     * (ambiguous across shared domains).
     */
    private function resolveDomainUsername(string $username, string $domain): ?TenantIdentity
    {
        $tenantDomains = $this->domainService->findAllByDomain($domain);

        if ($tenantDomains->isEmpty()) {
            return null;
        }

        // Collect the tenant_domain_ids for all matching enabled domains
        $domainIds = $tenantDomains->pluck('id')->toArray();

        /** @var Collection<int, SipAccount> $accounts */
        $accounts = SipAccount::withoutGlobalScope('tenant')
            ->where('auth_username', $username)
            ->whereIn('tenant_domain_id', $domainIds)
            ->where('enabled', true)
            ->whereIn('identity_mode', ['domain_username', 'hybrid'])
            ->with('tenant')
            ->get();

        // Fail-closed: ambiguous when same username matches multiple tenants
        if ($accounts->count() !== 1) {
            if ($accounts->count() > 1) {
                Log::warning('TenantIdentityResolver: ambiguous domain+username — multiple tenants match.', [
                    'username' => $username,
                    'domain' => $domain,
                    'tenant_count' => $accounts->count(),
                ]);
            }

            return null;
        }

        $account = $accounts->first();

        if ($account === null || $account->tenant === null) {
            return null;
        }

        // Find the matching tenant domain for this account
        $matchedDomain = $tenantDomains->first(fn (TenantDomain $td) => $td->id === $account->tenant_domain_id);

        return $this->buildIdentity($account, $matchedDomain);
    }

    /**
     * Resolve a global username across all tenants.
     *
     * Only matches accounts in global_username mode. Returns null
     * when multiple tenants have the same username (ambiguous).
     */
    private function resolveGlobalUsername(string $username): ?TenantIdentity
    {
        /** @var Collection<int, SipAccount> $accounts */
        $accounts = SipAccount::withoutGlobalScope('tenant')
            ->where('auth_username', $username)
            ->where('identity_mode', 'global_username')
            ->where('enabled', true)
            ->with('tenant')
            ->get();

        // Must match exactly one account — ambiguous if multiple tenants
        if ($accounts->count() !== 1) {
            return null;
        }

        $account = $accounts->first();

        if ($account === null || $account->tenant === null) {
            return null;
        }

        return $this->buildIdentity($account);
    }

    /**
     * Build a TenantIdentity from a SIP account and optional tenant domain.
     */
    private function buildIdentity(SipAccount $account, ?TenantDomain $tenantDomain = null): TenantIdentity
    {
        return new TenantIdentity(
            tenantId: (string) $account->tenant_id,
            sipAccountId: $account->id,
            extensionId: $account->extension_id,
            userContext: $account->user_context,
            identityMode: $account->identity_mode,
            tenantDomainId: $tenantDomain?->id ?? $account->tenant_domain_id,
        );
    }

    /**
     * Resolve and cache a small scalar identity payload for repeated SIP lookups.
     */
    private function rememberIdentity(string $key, callable $resolver): ?TenantIdentity
    {
        $ttl = (int) config('freeswitch.xml_handler.directory_cache_ttl', 0);

        if ($ttl <= 0) {
            return $resolver();
        }

        $cacheStore = config('freeswitch.xml_handler.directory_cache_store');
        $cache = is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();

        /** @var array{found: bool, identity: array<string, string|null>|null} $payload */
        $payload = $cache->remember($key, $ttl, function () use ($resolver): array {
            $identity = $resolver();

            return [
                'found' => $identity !== null,
                'identity' => $identity !== null ? $this->identityToArray($identity) : null,
            ];
        });

        if (! ($payload['found'] ?? false) || ! is_array($payload['identity'] ?? null)) {
            return null;
        }

        return $this->identityFromArray($payload['identity']);
    }

    /**
     * Build a stable cache key without exposing raw SIP usernames in Redis.
     *
     * @param  array<int, string>  $parts
     */
    private function cacheKey(string $type, array $parts): string
    {
        return 'freeswitch:tenant-identity:'.$type.':'.sha1(implode('|', $parts));
    }

    /**
     * Convert the identity value object to a cache-safe scalar array.
     *
     * @return array<string, string|null>
     */
    private function identityToArray(TenantIdentity $identity): array
    {
        return [
            'tenant_id' => $identity->tenantId,
            'sip_account_id' => $identity->sipAccountId,
            'extension_id' => $identity->extensionId,
            'extension_number' => $identity->extensionNumber,
            'user_context' => $identity->userContext,
            'identity_mode' => $identity->identityMode,
            'tenant_domain_id' => $identity->tenantDomainId,
        ];
    }

    /**
     * Rebuild the identity value object from the scalar cache payload.
     *
     * @param  array<string, string|null>  $payload
     */
    private function identityFromArray(array $payload): TenantIdentity
    {
        return new TenantIdentity(
            tenantId: (string) $payload['tenant_id'],
            sipAccountId: $payload['sip_account_id'] ?? null,
            extensionId: $payload['extension_id'] ?? null,
            extensionNumber: $payload['extension_number'] ?? null,
            userContext: $payload['user_context'] ?? null,
            identityMode: $payload['identity_mode'] ?? null,
            tenantDomainId: $payload['tenant_domain_id'] ?? null,
        );
    }
}
