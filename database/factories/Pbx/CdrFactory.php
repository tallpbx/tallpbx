<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\XmlCdr\Models\Cdr;

/**
 * @extends Factory<Cdr>
 */
class CdrFactory extends Factory
{
    protected $model = Cdr::class;

    public function definition(): array
    {
        $start = fake()->dateTimeThisMonth();
        $answer = (clone $start)->modify('+'.fake()->numberBetween(1, 10).' seconds');
        $end = (clone $answer)->modify('+'.fake()->numberBetween(5, 300).' seconds');

        return [
            'tenant_id' => Tenant::factory(),
            'call_uuid' => fake()->uuid(),
            'caller_id' => fake()->numerify('+1##########'),
            'caller_id_name' => fake()->name(),
            'destination' => fake()->numerify('+1##########'),
            'duration' => fake()->numberBetween(10, 600),
            'billsec' => fake()->numberBetween(5, 300),
            'hangup_cause' => fake()->randomElement(['NORMAL_CLEARING', 'USER_BUSY', 'NO_ANSWER', 'CALL_REJECTED']),
            'direction' => fake()->randomElement(['inbound', 'outbound']),
            'start_stamp' => $start,
            'answer_stamp' => $answer,
            'end_stamp' => $end,
        ];
    }
}
