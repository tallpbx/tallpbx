<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Conferences\Models\Conference;

/**
 * @extends Factory<Conference>
 */
class ConferenceFactory extends Factory
{
    protected $model = Conference::class;

    /**
     * Define the model's default state.
     *
     * Creates a conference room with a random name, the default
     * FreeSWITCH profile, and a random PIN.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Conference',
            'profile' => 'sample',
            'pin' => fake()->optional(0.8)->numerify('####'),
            'max_members' => 100,
            'enabled' => true,
        ];
    }
}
