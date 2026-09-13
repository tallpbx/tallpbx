<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for system settings management.
 *
 * Provides key-value storage for application-wide and per-tenant configuration settings with type casting support.
 */
interface SettingServiceInterface
{
    /**
     * Get a setting value by key.
     *
     * @param  string  $key  The setting key
     * @param  mixed  $default  Default value if not found
     * @param  int|null  $tenantId  Tenant scope (null for system)
     */
    public function get(string $key, mixed $default = null, ?int $tenantId = null): mixed;

    /**
     * Set a setting value.
     *
     * @param  string  $key  The setting key
     * @param  mixed  $value  The value to store
     * @param  string  $type  Value type (string, integer, boolean, json)
     * @param  int|null  $tenantId  Tenant scope (null for system)
     */
    public function set(string $key, mixed $value, string $type = 'string', ?int $tenantId = null): void;

    /**
     * Delete a setting by key.
     *
     * @param  string  $key  The setting key
     * @param  int|null  $tenantId  Tenant scope (null for system/all)
     */
    public function delete(string $key, ?int $tenantId = null): void;

    /**
     * Get all system-level settings.
     */
    public function all(): Collection;
}
