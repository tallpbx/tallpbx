<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use Modules\FeatureCodes\Models\FeatureCode;

/**
 * Creates common star feature codes for a tenant.
 */
final class DefaultFeatureCodesProvisioner
{
    /**
     * Provision missing feature codes for the tenant.
     *
     * @return array{created: int, skipped: int}
     */
    public function provision(Tenant $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->featureCodes() as $featureCode) {
            $exists = FeatureCode::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', $featureCode['code'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            FeatureCode::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                ...$featureCode,
                'enabled' => true,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Return the default feature code rows.
     *
     * @return array<int, array{name: string, code: string, description: string}>
     */
    private function featureCodes(): array
    {
        return [
            ['name' => 'Voicemail', 'code' => '*97', 'description' => 'Check voicemail.'],
            ['name' => 'Voicemail Main', 'code' => '*98', 'description' => 'Open the voicemail main menu.'],
            ['name' => 'Group Intercom', 'code' => '*8', 'description' => 'Start group intercom.'],
            ['name' => 'Call Pickup', 'code' => '*870', 'description' => 'Pickup a ringing extension.'],
            ['name' => 'Call Forward All Activate', 'code' => '*22', 'description' => 'Forward all calls.'],
            ['name' => 'Call Forward All Deactivate', 'code' => '*23', 'description' => 'Cancel call forwarding.'],
            ['name' => 'Do Not Disturb', 'code' => '*9664', 'description' => 'Toggle do not disturb.'],
            ['name' => 'Call Record', 'code' => '*732', 'description' => 'Start call recording.'],
            ['name' => 'Call Record Stop', 'code' => '*733', 'description' => 'Stop call recording.'],
            ['name' => 'Call Broadcast', 'code' => '*80', 'description' => 'Start call broadcast.'],
            ['name' => 'Agent Login', 'code' => '*85', 'description' => 'Call center agent login.'],
            ['name' => 'Agent Logout', 'code' => '*86', 'description' => 'Call center agent logout.'],
            ['name' => 'Follow Me Toggle', 'code' => '*72', 'description' => 'Toggle follow-me.'],
            ['name' => 'Call Block', 'code' => '*878', 'description' => 'Block the last caller.'],
            ['name' => 'Call Return', 'code' => '*69', 'description' => 'Return the last call.'],
            ['name' => 'Conference', 'code' => '*55', 'description' => 'Join a conference.'],
            ['name' => 'Call Park', 'code' => '*5900', 'description' => 'Park a call.'],
            ['name' => 'Echo Test', 'code' => '*9196', 'description' => 'Test audio loopback latency and quality.'],
        ];
    }
}
