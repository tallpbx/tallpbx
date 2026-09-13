<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallCenters\Models\Queue;
use Modules\Conferences\Models\Conference;
use Modules\Extensions\Models\Extension;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Scopes FreeSWITCH ESL list data to a tenant.
 *
 * Channels are attributed by their dialplan context (tenant_{id}_internal
 * or tenant_{id}_public — the AGENTS.md primary discriminator). Lists whose
 * output carries no context (conferences, call-center queues/agents) use
 * inclusive number/name membership. Registrations are attributed through
 * SIP accounts, disambiguating ambiguous usernames by domain and failing
 * closed when a domain is shared. A null tenant id (admin) disables
 * filtering.
 */
class TenantEslScoping
{
    public function __construct(private readonly DialplanContext $dialplanContext) {}

    /**
     * Keep only channels whose context belongs to the tenant.
     *
     * @param  array<int, array<string, mixed>>  $channels
     * @return array<int, array<string, mixed>>
     */
    public function filterChannelsByContext(array $channels, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (array $channel): bool => $this->dialplanContext->parseTenantId((string) ($channel['context'] ?? '')) === (string) $tenantId
        ));
    }

    /**
     * Keep conferences whose name is the tenant's or that contain a member
     * extension of the tenant (inclusive on collisions). A null tenant id
     * (admin) returns everything unchanged.
     *
     * @param  array<int, array<string, mixed>>  $conferences
     * @return array<int, array<string, mixed>>
     */
    public function filterConferencesByTenant(array $conferences, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $conferences;
        }

        $names = $this->conferenceNames($tenantId);
        $extensions = $this->extensionNumbers($tenantId);

        return array_values(array_filter(
            $conferences,
            fn (array $conference): bool => in_array($conference['name'], $names, true)
                || array_any($conference['members_list'] ?? [], fn (array $member): bool => in_array($member['caller_id'], $extensions, true))
        ));
    }

    /**
     * Keep queues whose name belongs to the tenant (inclusive on collisions).
     * A null tenant id (admin) returns everything unchanged.
     *
     * @param  array<int, array<string, mixed>>  $queues
     * @return array<int, array<string, mixed>>
     */
    public function filterQueuesByTenant(array $queues, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $queues;
        }

        $names = $this->queueNames($tenantId);

        return array_values(array_filter(
            $queues,
            fn (array $queue): bool => in_array($queue['name'], $names, true)
        ));
    }

    /**
     * Keep agents whose extension (the part before @) belongs to the tenant.
     * A null tenant id (admin) returns everything unchanged.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    public function filterAgentsByTenant(array $agents, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $agents;
        }

        $extensions = $this->extensionNumbers($tenantId);

        return array_values(array_filter(
            $agents,
            fn (array $agent): bool => in_array(explode('@', (string) $agent['agent'], 2)[0], $extensions, true)
        ));
    }

    /**
     * Keep registrations whose user identity belongs to the tenant.
     *
     * A username matching exactly one tenant matches it. An ambiguous
     * username additionally requires the registration domain to resolve
     * to exactly one tenant and equal the current one; shared domains
     * fail closed (excluded everywhere). A null tenant id (admin) returns
     * everything unchanged.
     *
     * @param  array<int, array<string, mixed>>  $registrations
     * @return array<int, array<string, mixed>>
     */
    public function filterRegistrationsByTenant(array $registrations, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $registrations;
        }

        return array_values(array_filter(
            $registrations,
            fn (array $registration): bool => $this->registrationBelongsToTenant(
                (string) $registration['user'],
                $tenantId
            )
        ));
    }

    /**
     * Resolve whether a user@domain registration belongs to the tenant.
     */
    private function registrationBelongsToTenant(string $userAtDomain, int $tenantId): bool
    {
        [$user, $domain] = array_pad(explode('@', $userAtDomain, 2), 2, '');

        $tenantIds = SipAccount::withoutGlobalScope('tenant')
            ->where('auth_username', $user)
            ->where('enabled', true)
            ->pluck('tenant_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($tenantIds->count() === 1) {
            return $tenantIds->first() === $tenantId;
        }

        if ($tenantIds->count() === 0) {
            // Unknown identity — fail closed (never admitted by domain alone).
            return false;
        }

        if ($domain === '') {
            return false;
        }

        /** @var Collection<int, TenantDomain> $domains */
        $domains = TenantDomain::query()
            ->where('domain', $domain)
            ->where('enabled', true)
            ->get();

        return $domains->count() === 1 && (int) $domains->first()->tenant_id === $tenantId;
    }

    /**
     * The enabled extension numbers of a tenant.
     *
     * @return array<int, string>
     */
    private function extensionNumbers(int $tenantId): array
    {
        return Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('extension_number')
            ->all();
    }

    /**
     * The enabled conference room names of a tenant.
     *
     * @return array<int, string>
     */
    private function conferenceNames(int $tenantId): array
    {
        return Conference::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('name')
            ->all();
    }

    /**
     * The enabled call-center queue names of a tenant.
     *
     * @return array<int, string>
     */
    private function queueNames(int $tenantId): array
    {
        return Queue::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('name')
            ->all();
    }
}
