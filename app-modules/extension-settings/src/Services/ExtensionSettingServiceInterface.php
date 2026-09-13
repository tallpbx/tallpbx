<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\ExtensionSettings\Models\ExtensionSetting;

/**
 * Contract for extension setting CRUD operations.
 *
 * All operations internally handle tenant-scope bypass
 * where needed so callers never need to call
 * withoutGlobalScope('tenant') directly.
 */
interface ExtensionSettingServiceInterface
{
    /**
     * Find an extension setting by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): ExtensionSetting;

    /**
     * Get all extension settings across all tenants, ordered by key.
     *
     * @return Collection<int, ExtensionSetting>
     */
    public function all(): Collection;

    public function create(array $data): ExtensionSetting;

    public function update(ExtensionSetting $setting, array $data): ExtensionSetting;

    public function delete(ExtensionSetting $setting): void;
}
