<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Dialplans\Models\DialplanDetail;

/**
 * @extends Factory<DialplanDetail>
 */
class DialplanDetailFactory extends Factory
{
    protected $model = DialplanDetail::class;

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
