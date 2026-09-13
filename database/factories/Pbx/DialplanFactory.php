<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;

/**
 * @extends Factory<Dialplan>
 */
class DialplanFactory extends Factory
{
    protected $model = Dialplan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'context' => 'default',
            'order' => fake()->numberBetween(10, 999),
            'enabled' => true,
        ];
    }

    /**
     * Perform the withDetails operation.
     */
    public function withDetails(int $count = 1): static
    {
        return $this->has(
            DialplanDetail::factory()->count($count),
            'details',
        );
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
