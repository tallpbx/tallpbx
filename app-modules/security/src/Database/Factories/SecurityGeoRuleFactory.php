<?php

declare(strict_types=1);

namespace Modules\Security\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Security\Models\SecurityGeoRule;

/**
 * @extends Factory<SecurityGeoRule>
 */
class SecurityGeoRuleFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<SecurityGeoRule>
     */
    protected $model = SecurityGeoRule::class;

    /**
     * Define the default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enabled' => false,
            'mode' => 'blacklist',
            'port_scope' => 'all_traffic',
            'countries' => ['US'],
            'sync_interval' => 'weekly',
            'last_sync_at' => null,
            'last_status' => null,
            'last_error' => null,
            'v4_cidrs_count' => 0,
            'v6_cidrs_count' => 0,
        ];
    }
}
