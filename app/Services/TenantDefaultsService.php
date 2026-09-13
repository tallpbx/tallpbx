<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Services\TenantDefaults\DefaultDialplansProvisioner;
use App\Services\TenantDefaults\DefaultFeatureCodesProvisioner;
use App\Services\TenantDefaults\DefaultMediaDirectoriesProvisioner;
use App\Services\TenantDefaults\DefaultMusicOnHoldProvisioner;
use App\Services\TenantDefaults\DefaultSipProfilesProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates idempotent PBX default provisioning for tenants.
 */
final class TenantDefaultsService
{
    /**
     * Create the service instance.
     */
    public function __construct(
        private readonly DefaultSipProfilesProvisioner $sipProfiles,
        private readonly DefaultDialplansProvisioner $dialplans,
        private readonly DefaultFeatureCodesProvisioner $featureCodes,
        private readonly DefaultMusicOnHoldProvisioner $musicOnHold,
        private readonly DefaultMediaDirectoriesProvisioner $mediaDirectories,
    ) {}

    /**
     * Provision missing PBX defaults for the tenant.
     *
     * @return array<string, array{created: int, skipped: int}>
     */
    public function provision(Tenant $tenant): array
    {
        return DB::transaction(fn (): array => [
            'sip_profiles' => $this->sipProfiles->provision($tenant),
            'dialplans' => $this->dialplans->provision($tenant),
            'feature_codes' => $this->featureCodes->provision($tenant),
            'music_on_hold' => $this->musicOnHold->provision($tenant),
            'media_directories' => $this->mediaDirectories->provision($tenant),
        ]);
    }
}
