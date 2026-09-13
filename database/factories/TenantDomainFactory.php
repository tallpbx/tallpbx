<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => fake()->unique()->domainName(),
            'purpose' => fake()->randomElement(['sip_realm', 'provisioning', 'web', 'alias']),
            'enabled' => true,
        ];
    }

    /**
     * Perform the sipRealm operation.
     */
    public function sipRealm(): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => 'sip_realm',
        ]);
    }

    /**
     * Perform the disabled operation.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
