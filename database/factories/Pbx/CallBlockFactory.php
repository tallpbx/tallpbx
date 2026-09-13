<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallBlocks\Models\CallBlock;

/**
 * @extends Factory<CallBlock>
 */
class CallBlockFactory extends Factory
{
    protected $model = CallBlock::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Block',
            'caller_id_number' => fake()->numerify('##########'),
            'description' => fake()->optional()->sentence(),
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
