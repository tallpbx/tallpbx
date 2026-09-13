<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Value object representing the resolved identity of a tenant from a
 * SIP domain, username, or authentication context.
 *
 * This is a readonly object — once created, its properties cannot change.
 * Used by TenantIdentityResolver to return structured resolution results.
 */
readonly class TenantIdentity
{
    /**
     * @param  string  $tenantId  The resolved tenant UUID
     * @param  string|null  $sipAccountId  The matched SIP account UUID, if applicable
     * @param  string|null  $extensionId  The associated extension UUID, if applicable
     * @param  string|null  $extensionNumber  The extension number, if applicable
     * @param  string|null  $userContext  The FreeSWITCH user context for directory XML
     * @param  string|null  $identityMode  The identity mode (global_username, domain_username, hybrid)
     * @param  string|null  $tenantDomainId  The tenant domain UUID, if resolved via domain
     */
    public function __construct(
        public string $tenantId,
        public ?string $sipAccountId = null,
        public ?string $extensionId = null,
        public ?string $extensionNumber = null,
        public ?string $userContext = null,
        public ?string $identityMode = null,
        public ?string $tenantDomainId = null,
    ) {}
}
