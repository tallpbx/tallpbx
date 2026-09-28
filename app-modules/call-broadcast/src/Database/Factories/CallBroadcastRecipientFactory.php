<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

/**
 * @extends Factory<CallBroadcastRecipient>
 */
class CallBroadcastRecipientFactory extends Factory
{
    protected $model = CallBroadcastRecipient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcast_id' => CallBroadcast::factory(),
            'phone_number' => '+1'.fake()->numerify('##########'),
            'originate_uuid' => null,
            'call_status' => 'pending',
            'hangup_cause' => null,
            'call_duration' => null,
            'attempted_at' => null,
        ];
    }
}
