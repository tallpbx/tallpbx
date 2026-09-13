<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Devices\Models\Device;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'vendor' => fake()->randomElement(['Polycom', 'Yealink', 'Cisco', 'Grandstream', 'Snom']),
            'model' => fake()->randomElement(['VVX 450', 'T46S', '8821', 'GXP2170', 'D735']),
            'mac_address' => fake()->unique()->macAddress(),
            'template' => null,
            'enabled' => true,
        ];
    }

    /**
     * Scope the factory state to a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
