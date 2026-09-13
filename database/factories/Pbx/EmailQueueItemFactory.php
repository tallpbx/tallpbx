<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\EmailQueue\Models\EmailQueueItem;

/**
 * Generate fake EmailQueueItem records for testing.
 *
 * Creates queued email entries with random recipients, subjects, and delivery statuses.
 */
class EmailQueueItemFactory extends Factory
{
    protected $model = EmailQueueItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'to' => fake()->email(),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(3),
            'status' => fake()->randomElement(['pending', 'sent', 'failed']),
            'sent_at' => null,
        ];
    }
}
