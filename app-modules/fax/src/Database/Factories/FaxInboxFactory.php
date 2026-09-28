<?php

declare(strict_types=1);

namespace Modules\Fax\Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Fax\Models\FaxInbox;

/**
 * @extends Factory<FaxInbox>
 */
class FaxInboxFactory extends Factory
{
    protected $model = FaxInbox::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'caller_id' => fake()->numerify('+1##########'),
            'pages' => fake()->numberBetween(1, 10),
            'document_path' => 'faxes/'.fake()->uuid().'.pdf',
            'received_at' => fake()->dateTimeThisMonth(),
        ];
    }
}
