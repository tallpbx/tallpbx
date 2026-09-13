<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SipProfiles\Models\SipProfile;

/**
 * Creates SIP profile records with FreeSWITCH-native parameter keys.
 *
 * @extends Factory<SipProfile>
 */
class SipProfileFactory extends Factory
{
    protected $model = SipProfile::class;

    /**
     * Return the default SIP profile attributes for factories.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'settings' => [
                'sip-port' => '5060',
                'sip-ip' => '$${local_ip_v4}',
                'rtp-ip' => '$${local_ip_v4}',
            ],
            'enabled' => true,
        ];
    }

    /**
     * Mark the SIP profile as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
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
