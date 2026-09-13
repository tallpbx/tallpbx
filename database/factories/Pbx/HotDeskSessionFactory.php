<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Extensions\Models\Extension;
use Modules\HotDesking\Models\HotDeskSession;

/**
 * Factory for generating HotDeskSession instances in tests and seeders.
 *
 * @extends Factory<HotDeskSession>
 */
class HotDeskSessionFactory extends Factory
{
    protected $model = HotDeskSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'extension_id' => Extension::factory(),
            'device_extension_id' => Extension::factory(),
            'ip_address' => fake()->ipv4(),
            'description' => fake()->sentence(3),
            'is_active' => true,
            'login_at' => now(),
        ];
    }

    /**
     * Set the tenant for this session.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
