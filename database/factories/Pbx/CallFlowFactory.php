<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallFlows\Models\CallFlow;

/**
 * @extends Factory<CallFlow>
 */
class CallFlowFactory extends Factory
{
    protected $model = CallFlow::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Flow',
            'extension' => fake()->numerify('1##########'),
            'destination_type' => fake()->randomElement(['ring_group', 'ivr_menu', 'extension', 'voicemail']),
            'destination_id' => null,
            'enabled' => true,
        ];
    }
}
