<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

/**
 * Livewire component that lists SIP profiles with CRUD actions.
 */
class SipProfilesList extends BaseListComponent
{
    /** @var Collection<int, SipProfile> */
    public Collection $profiles;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private SipProfileServiceInterface $profileService;

    /** Inject the SIP-profile service. */
    public function boot(SipProfileServiceInterface $profileService): void
    {
        $this->profileService = $profileService;
    }

    /** Load the SIP profile list when the component starts. */
    public function mount(): void
    {
        $this->loadProfiles();
    }

    /**
     * Load all SIP profiles.
     */
    private function loadProfiles(): void
    {
        $this->profiles = SipProfile::withoutGlobalScope('tenant')->orderBy('name')->get();
    }

    /**
     * Delete a SIP profile by its ID.
     */
    public function deleteProfile(string $profileId): void
    {
        $profile = SipProfile::withoutGlobalScope('tenant')->findOrFail($profileId);
        $this->profileService->delete($profile);
        $this->cancelProfileDeletion();
        $this->loadProfiles();
        $this->showSuccess('SIP profile deleted.');
        $this->dispatch('profile-deleted');
    }

    /** Open the shared destructive-action confirmation for one SIP profile. */
    public function confirmProfileDeletion(string $profileId): void
    {
        $profile = SipProfile::withoutGlobalScope('tenant')->findOrFail($profileId);
        $this->pendingDeletionId = $profile->id;
        $this->pendingDeletionName = $profile->name;
    }

    /** Close the SIP-profile deletion confirmation without changing the profile. */
    public function cancelProfileDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
