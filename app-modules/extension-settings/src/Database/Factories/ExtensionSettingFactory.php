<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Database\Factories;

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

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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
