<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * @extends Factory<Cdr>
 */
class CdrFactory extends Factory
{
    protected $model = Cdr::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeThisMonth();
        $answer = (clone $start)->modify('+'.fake()->numberBetween(1, 10).' seconds');
        $end = (clone $answer)->modify('+'.fake()->numberBetween(5, 300).' seconds');

        return [
            'tenant_id' => Tenant::factory(),
            'call_uuid' => fake()->uuid(),
            // A B-leg only exists when the call was bridged; the factory
            // creates the common A-leg record first.
            'b_leg_uuid' => null,
            'caller_id' => fake()->numerify('+1##########'),
            'caller_id_name' => fake()->name(),
            'destination' => fake()->numerify('+1##########'),
            'duration' => fake()->numberBetween(10, 600),
            'billsec' => fake()->numberBetween(5, 300),
            'hangup_cause' => fake()->randomElement(['NORMAL_CLEARING', 'USER_BUSY', 'NO_ANSWER', 'CALL_REJECTED']),
            'direction' => fake()->randomElement(['inbound', 'outbound']),
            // The database defaults the side to 'a'; a bridged test case
            // passes 'b' explicitly.
            'record_path' => null,
            'json_data' => ['variables' => ['uuid' => fake()->uuid()]],
            'start_stamp' => $start,
            'answer_stamp' => $answer,
            'end_stamp' => $end,
        ];
    }
}
