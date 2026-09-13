<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\NumberTranslations\Models\NumberTranslation;

/**
 * Generate fake NumberTranslation records for testing.
 *
 * Creates number manipulation rules with random match patterns and replacement strings.
 */
class NumberTranslationFactory extends Factory
{
    protected $model = NumberTranslation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Translation',
            'match_pattern' => '^'.fake()->randomElement(['011', '00', '1']).'(\\d+)$',
            'replace_pattern' => '$1',
            'direction' => fake()->randomElement(['inbound', 'outbound', 'both']),
            'enabled' => true,
            'order' => 0,
        ];
    }
}
