<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\PinNumbers\Models\PinNumber;

/**
 * Factory for generating PIN number instances in tests and seeders.
 *
 * @extends Factory<PinNumber>
 */
class PinNumberFactory extends Factory
{
    protected $model = PinNumber::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pin_number' => fake()->unique()->numerify('####'),
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
        ];
    }

    /**
     * Set the tenant for this PIN number.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
