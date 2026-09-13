<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MusicOnHold\Models\MusicOnHold;

/**
 * Factory for generating music on hold instances in tests and seeders.
 *
 * @extends Factory<MusicOnHold>
 */
class MusicOnHoldFactory extends Factory
{
    protected $model = MusicOnHold::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true).' Music',
            'audio_file' => fake()->optional()->filePath(),
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
        ];
    }

    /**
     * Set the tenant for this music on hold entry.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
