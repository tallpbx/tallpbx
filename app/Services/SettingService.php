<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;

/**
 * Concrete implementation of SettingServiceInterface.
 *
 * Manages system-wide and tenant-scoped settings using the
 * settings database table.
 */
class SettingService implements SettingServiceInterface
{
    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $setting = Setting::where('key', $key)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($setting === null) {
            return $default;
        }

        return $this->castValue($setting->value, $setting->type);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, string $type = 'string', ?int $tenantId = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key, 'tenant_id' => $tenantId],
            ['value' => (string) $value, 'type' => $type],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key, ?int $tenantId = null): void
    {
        Setting::where('key', $key)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        return Setting::whereNull('tenant_id')
            ->orderBy('key')
            ->get();
    }

    /**
     * Cast a stored value back to its declared type.
     */
    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
