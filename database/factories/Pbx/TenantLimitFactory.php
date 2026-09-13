<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\TenantLimits\Models\TenantLimit;

/**
 * Generate fake TenantLimit records for testing.
 *
 * Creates resource limit entries with random resource names and soft/hard limits.
 */
class TenantLimitFactory extends Factory
{
    protected $model = TenantLimit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'resource' => fake()->randomElement(['extensions', 'calls', 'recordings', 'voicemails']),
            'soft_limit' => fake()->numberBetween(5, 50),
            'hard_limit' => fn (array $attrs) => $attrs['soft_limit'] + fake()->numberBetween(5, 50),
        ];
    }
}
