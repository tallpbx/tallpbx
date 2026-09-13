<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallBroadcast\Models\CallBroadcast;

/**
 * @extends Factory<CallBroadcast>
 */
class CallBroadcastFactory extends Factory
{
    protected $model = CallBroadcast::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->sentence(3).' Alert',
            'status' => fake()->randomElement(['draft', 'sending', 'completed']),
        ];
    }
}
