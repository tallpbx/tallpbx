<?php

declare(strict_types=1);

namespace Modules\Dialplans\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Dialplans\Models\DialplanDetail;

/**
 * @extends Factory<DialplanDetail>
 */
class DialplanDetailFactory extends Factory
{
    protected $model = DialplanDetail::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tag' => 'condition',
            'field' => 'destination_number',
            'expression' => '^'.fake()->numberBetween(100, 999).'$',
            'action' => 'bridge',
            'data' => 'user/'.fake()->numberBetween(100, 999),
            'order' => fake()->numberBetween(10, 999),
        ];
    }
}
