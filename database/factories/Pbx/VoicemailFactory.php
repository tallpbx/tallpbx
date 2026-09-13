<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Voicemails\Models\Voicemail;

/**
 * @extends Factory<Voicemail>
 */
class VoicemailFactory extends Factory
{
    protected $model = Voicemail::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'voicemail_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'mailbox' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->name().'\'s Voicemail',
            'password' => fake()->numerify('####'),
            'email' => fake()->safeEmail(),
            'greeting_message' => null,
            'require_password' => true,
            'forward_to_email' => false,
            'delete_after_email' => false,
            'enabled' => true,
        ];
    }

    /**
     * Perform the withoutPassword operation.
     */
    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'require_password' => false,
            'password' => null,
        ]);
    }

    /**
     * Perform the withEmailForwarding operation.
     */
    public function withEmailForwarding(): static
    {
        return $this->state(fn (array $attributes) => [
            'forward_to_email' => true,
            'email' => fake()->safeEmail(),
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
