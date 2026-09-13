<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Services;

use App\Services\TenantManager;
use App\Support\RoutingCacheVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\NumberTranslations\Models\NumberTranslation;

/**
 * Service implementation for number translation management.
 *
 * Wraps all write operations in database transactions.
 * All queries bypass the global tenant scope because this
 * service is used by admin panels that need cross-tenant access.
 */
class NumberTranslationService implements NumberTranslationServiceInterface
{
    /**
     * Find a number translation by ID, bypassing tenant scope.
     */
    public function find(string $id): NumberTranslation
    {
        return NumberTranslation::withoutGlobalScope('tenant')->findOrFail($id);
    }

    /**
     * Get all number translations across all tenants, ordered by name.
     */
    public function all(): Collection
    {
        return NumberTranslation::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): NumberTranslation
    {
        $translation = DB::transaction(function () use ($data): NumberTranslation {
            return NumberTranslation::withoutGlobalScope('tenant')->create($data);
        });

        // New rules must invalidate the cached dialplan immediately.
        RoutingCacheVersion::bump((int) $data['tenant_id']);

        return $translation;
    }

    public function update(NumberTranslation $translation, array $data): NumberTranslation
    {
        $translation = DB::transaction(function () use ($translation, $data): NumberTranslation {
            $translation->withoutGlobalScope('tenant')->update($data);

            return $translation->fresh();
        });

        // Changed rules must invalidate the cached dialplan immediately.
        RoutingCacheVersion::bump((int) $translation->tenant_id);

        return $translation;
    }

    public function delete(NumberTranslation $translation): void
    {
        DB::transaction(function () use ($translation): void {
            $translation->withoutGlobalScope('tenant')->delete();
        });

        // Removed rules must invalidate the cached dialplan immediately.
        RoutingCacheVersion::bump((int) $translation->tenant_id);
    }

    /**
     * Apply the tenant's enabled translation rules to a destination number.
     *
     * Rules are applied in order (order then name) as regex replaces.
     * Rules with invalid regex are logged and skipped, so a malformed
     * pattern can never break a dialplan request.
     */
    public function translate(string $destination, string $direction): string
    {
        $tenantId = app(TenantManager::class)->getTenantId();

        if ($tenantId === null || $destination === '') {
            return $destination;
        }

        $rules = NumberTranslation::withoutGlobalScope('tenant')
            ->where('tenant_id', (int) $tenantId)
            ->where('enabled', true)
            ->whereIn('direction', [$direction, 'both'])
            ->orderBy('order')
            ->orderBy('name')
            ->get(['match_pattern', 'replace_pattern']);

        foreach ($rules as $rule) {
            $pattern = '/'.$rule->match_pattern.'/';

            // A malformed pattern must never break the dialplan request.
            if (@preg_match($pattern, '') === false) {
                Log::warning('Number translation has an invalid regex, skipping.', [
                    'match_pattern' => $rule->match_pattern,
                ]);

                continue;
            }

            $destination = (string) preg_replace($pattern, (string) $rule->replace_pattern, $destination);
        }

        return $destination;
    }
}
