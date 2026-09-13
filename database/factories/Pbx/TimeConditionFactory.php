<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\TimeConditions\Models\TimeCondition;

/**
 * @extends Factory<TimeCondition>
 */
class TimeConditionFactory extends Factory
{
    protected $model = TimeCondition::class;

    /**
     * Define the model's default state.
     *
     * Creates a time condition with a random name, standard business
     * hours weekdays and time window.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Hours',
            'timezone' => null,
            'weekdays' => 'mon,tue,wed,thu,fri',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'destination_on_match' => null,
            'destination_on_no_match' => null,
            'enabled' => true,
        ];
    }
}
