<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Extensions\Models\Extension;

/**
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    protected $model = Extension::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'extension_number' => (string) fake()->unique()->numberBetween(100, 999),
            'display_name' => fake()->name(),
            'voicemail_enabled' => false,
            'enabled' => true,
        ];
    }

    /**
     * Perform the withVoicemail operation.
     */
    public function withVoicemail(): static
    {
        return $this->state(fn (array $attributes) => [
            'voicemail_enabled' => true,
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
