<?php

declare(strict_types=1);

namespace Modules\CallCenters\Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallCenters\Models\Queue;

/**
 * Generate fake CallCenter Queue records for testing.
 *
 * Creates call center queues with random names, strategies, and timeout values.
 */
class QueueFactory extends Factory
{
    protected $model = Queue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Queue',
            'strategy' => 'ring-all',
            'timeout' => 30,
            'music_on_hold' => null,
            'enabled' => true,
        ];
    }
}
