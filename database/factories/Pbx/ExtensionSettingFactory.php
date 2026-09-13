<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Extensions\Models\Extension;
use Modules\ExtensionSettings\Models\ExtensionSetting;

/**
 * Generate fake ExtensionSetting records for testing.
 *
 * Creates per-extension settings with random keys and values.
 */
class ExtensionSettingFactory extends Factory
{
    protected $model = ExtensionSetting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'extension_id' => Extension::factory(),
            'key' => fake()->randomElement(['call_waiting', 'call_forward', 'do_not_disturb', 'caller_id']),
            'value' => fake()->randomElement(['enabled', 'disabled', '1', '0']),
        ];
    }
}
