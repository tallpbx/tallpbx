<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use Modules\MusicOnHold\Models\MusicOnHold;

/**
 * Creates baseline music on hold entries for a tenant.
 */
final class DefaultMusicOnHoldProvisioner
{
    /**
     * Provision missing music on hold rows for the tenant.
     *
     * @return array{created: int, skipped: int}
     */
    public function provision(Tenant $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->entries() as $entry) {
            $exists = MusicOnHold::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('name', $entry['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            MusicOnHold::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                ...$entry,
                'enabled' => true,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Return default music on hold entries.
     *
     * @return array<int, array{name: string, audio_file: string, description: string}>
     */
    private function entries(): array
    {
        return [
            ['name' => 'default', 'audio_file' => '$${hold_music}', 'description' => 'Default FreeSWITCH hold music.'],
            ['name' => 'silence', 'audio_file' => 'silence', 'description' => 'Silent hold music.'],
        ];
    }
}
