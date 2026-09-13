<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Livewire;

use App\Jobs\ReloadSofiaProfile;
use App\Support\BaseEditComponent;
use Illuminate\Validation\Rule;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

/**
 * Livewire component for creating and editing SIP profiles.
 */
class SipProfilesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $description = '';

    public string $sipPort = '5060';

    public string $sipIp = '';

    public ?string $profileId = null;

    private SipProfileServiceInterface $profileService;

    public function boot(SipProfileServiceInterface $profileService): void
    {
        $this->profileService = $profileService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $profileId = null): void
    {
        $this->loadTenants();

        if ($profileId !== null) {
            $this->profileId = $profileId;
            $profile = SipProfile::withoutGlobalScope('tenant')->findOrFail($profileId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($profile);
            $this->name = $profile->name;
            $this->description = $profile->description ?? '';
            $settings = $this->normalizeSettings($profile->settings ?? []);
            $this->sipPort = (string) ($settings['sip-port'] ?? '5060');
            $this->sipIp = (string) ($settings['sip-ip'] ?? '');
            $this->enabled = $profile->enabled;
        }
    }

    /**
     * Whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->profileId !== null;
    }

    /**
     * Save the SIP profile.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $settings = [];
        if ($this->profileId !== null) {
            $profile = SipProfile::withoutGlobalScope('tenant')->findOrFail($this->profileId);
            $settings = $this->normalizeSettings($profile->settings ?? []);
        }

        $settings['sip-port'] = $this->sipPort;
        $settings['sip-ip'] = $this->sipIp;

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'settings' => $settings,
            'enabled' => $this->enabled,
        ];

        if ($this->profileId !== null) {
            $this->profileService->update($profile, $data);
        } else {
            $data['tenant_id'] = $this->tenantId;
            $this->profileService->create($data);
        }

        $this->redirect(route('panel.sip-profiles.index'));

        // Rescan the specific Sofia profile instead of a full reloadxml
        ReloadSofiaProfile::dispatch($this->name, 'SIP profile '.($this->profileId !== null ? 'updated' : 'created'));
    }

    /**
     * Validation rules.
     */
    protected function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sipPort' => ['nullable', 'numeric', 'min:1', 'max:65535'],
            'sipIp' => ['nullable', 'string', 'max:255'],
        ];

        // tenantId is required for create only
        if ($this->profileId === null) {
            $rules['tenantId'] = ['required', 'exists:tenants,id'];
        }

        $uniqueRule = Rule::unique('sip_profiles', 'name');
        if ($this->profileId !== null) {
            $uniqueRule->ignore($this->profileId);
        }
        $rules['name'][] = $uniqueRule;

        return $rules;
    }

    /**
     * Normalize legacy UI-oriented keys to FreeSWITCH-native param names.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $legacyKeyMap = [
            'sip_port' => 'sip-port',
            'sip_ip' => 'sip-ip',
            'rtp_ip' => 'rtp-ip',
        ];

        foreach ($legacyKeyMap as $legacyKey => $nativeKey) {
            if (array_key_exists($legacyKey, $settings) && ! array_key_exists($nativeKey, $settings)) {
                $settings[$nativeKey] = $settings[$legacyKey];
            }

            unset($settings[$legacyKey]);
        }

        return $settings;
    }
}
