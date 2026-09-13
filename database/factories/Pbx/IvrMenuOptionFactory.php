<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Models\IvrMenuOption;

/**
 * Factory for generating IVR menu option instances in tests and seeders.
 *
 * @extends Factory<IvrMenuOption>
 */
class IvrMenuOptionFactory extends Factory
{
    protected $model = IvrMenuOption::class;

    public function definition(): array
    {
        return [
            'ivr_menu_id' => IvrMenu::factory(),
            'digit' => (string) fake()->randomDigit(),
            'action' => fake()->randomElement(['transfer', 'playback', 'voicemail', 'menu', 'top', 'exit']),
            'action_data' => fake()->optional()->word(),
            'order' => fake()->numberBetween(0, 20),
            'enabled' => true,
        ];
    }
}
