<?php

declare(strict_types=1);

namespace Modules\Security\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Security\Models\SecurityThreatFeed;

/**
 * Factory for believable threat feed configurations.
 *
 * The default state is a disabled VoIPBL feed with all countries selected
 * and no sync history yet — exactly what a freshly registered driver row
 * looks like before its first sync.
 *
 * @extends Factory<SecurityThreatFeed>
 */
class SecurityThreatFeedFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<SecurityThreatFeed>
     */
    protected $model = SecurityThreatFeed::class;

    /**
     * Define the default feed state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'voipbl',
            'name' => 'VoIPBL',
            'enabled' => false,
            'country_mode' => 'all',
            'countries' => [],
            'sync_interval' => 'daily',
            'last_sync_at' => null,
            'last_status' => null,
            'last_error' => null,
            'entries_count' => 0,
            'etag' => null,
            'last_modified_header' => null,
            'last_rejected_lines' => 0,
        ];
    }
}
