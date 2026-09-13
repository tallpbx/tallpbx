<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Gateways\Models\Gateway;

/**
 * @extends Factory<Gateway>
 */
class GatewayFactory extends Factory
{
    protected $model = Gateway::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->company(),
            'description' => fake()->sentence(),
            'host' => 'sip.'.fake()->domainName(),
            'port' => 5060,
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'register' => true,
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
