<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Provision\Models\ProvisionTemplate;

/**
 * Generate fake ProvisionTemplate records for testing.
 *
 * Creates provisioning templates with random vendor assignments and template names.
 */
class ProvisionTemplateFactory extends Factory
{
    protected $model = ProvisionTemplate::class;

    public function definition(): array
    {
        $vendor = fake()->randomElement(['grandstream', 'polycom', 'cisco', 'yealink']);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => ucfirst($vendor).' Default Template',
            'vendor' => $vendor,
            'model' => null,
            'file_path' => "{$vendor}/default.blade.php",
            'enabled' => true,
        ];
    }
}
