<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FeatureCodes\Models\FeatureCode;

/**
 * @extends Factory<FeatureCode>
 */
class FeatureCodeFactory extends Factory
{
    protected $model = FeatureCode::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'code' => '*'.fake()->unique()->numberBetween(10, 99),
            'description' => fake()->sentence(),
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
