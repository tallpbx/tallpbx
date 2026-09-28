<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\EmailTemplates\Models\EmailTemplate;

/**
 * Generate fake EmailTemplate records for testing.
 *
 * Creates email notification templates with random names, subjects, and body content.
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Template',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(3),
        ];
    }
}
