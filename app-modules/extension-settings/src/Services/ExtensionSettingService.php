<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ExtensionSettings\Models\ExtensionSetting;

/**
 * Service implementation for extension setting management.
 *
 * Wraps all write operations in database transactions.
 * All queries bypass the global tenant scope because this
 * service is used by admin panels that need cross-tenant access.
 */
class ExtensionSettingService implements ExtensionSettingServiceInterface
{
    /**
     * Find an extension setting by ID, bypassing tenant scope.
     */
    public function find(string $id): ExtensionSetting
    {
        return ExtensionSetting::withoutGlobalScope('tenant')->findOrFail($id);
    }

    /**
     * Get all extension settings across all tenants, ordered by key.
     */
    public function all(): Collection
    {
        return ExtensionSetting::withoutGlobalScope('tenant')
            ->orderBy('key')
            ->get();
    }

    public function create(array $data): ExtensionSetting
    {
        return DB::transaction(fn () => ExtensionSetting::withoutGlobalScope('tenant')->create($data));
    }

    public function update(ExtensionSetting $setting, array $data): ExtensionSetting
    {
        return DB::transaction(function () use ($setting, $data): ExtensionSetting {
            $setting->withoutGlobalScope('tenant')->update($data);

            return $setting->fresh();
        });
    }

    public function delete(ExtensionSetting $setting): void
    {
        DB::transaction(fn () => $setting->withoutGlobalScope('tenant')->delete());
    }
}
