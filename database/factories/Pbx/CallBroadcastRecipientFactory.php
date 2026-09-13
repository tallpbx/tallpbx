<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

/**
 * @extends Factory<CallBroadcastRecipient>
 */
class CallBroadcastRecipientFactory extends Factory
{
    protected $model = CallBroadcastRecipient::class;

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
