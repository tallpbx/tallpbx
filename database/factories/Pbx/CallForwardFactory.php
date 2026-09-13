<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallForwards\Models\CallForward;
use Modules\Extensions\Models\Extension;

/**
 * Factory for generating call forward instances in tests and seeders.
 *
 * @extends Factory<CallForward>
 */
class CallForwardFactory extends Factory
{
    protected $model = CallForward::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'extension_uuid' => Extension::factory(),
            'forward_type' => fake()->randomElement(['unconditional', 'busy', 'noanswer', 'notfound']),
            'destination' => fake()->phoneNumber(),
            'ring_timeout' => fake()->randomElement([15, 20, 30, 45, 60]),
            'enabled' => true,
        ];
    }

    /**
     * Set the tenant for this call forward.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Set the forward type.
     */
    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'forward_type' => $type,
        ]);
    }
}
