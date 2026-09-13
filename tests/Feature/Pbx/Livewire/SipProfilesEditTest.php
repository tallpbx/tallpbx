<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\SipProfiles\Livewire\SipProfilesEdit;
use Modules\SipProfiles\Models\SipProfile;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class)
        ->assertOk()
        ->assertSee('Create SIP Profile')
        ->assertSee('A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH')
        ->assertSet('name', '')
        ->assertSet('description', '')
        ->assertSet('sipPort', '5060')
        ->assertSet('sipIp', '');
});

it('renders the edit form with existing profile data', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'Internal',
        'description' => 'Internal profile',
        'settings' => ['sip-port' => 5060, 'sip-ip' => '10.0.0.1'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class, ['profileId' => $profile->id])
        ->assertOk()
        ->assertSee('Edit SIP Profile')
        ->assertSee('A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH')
        ->assertSet('name', 'Internal')
        ->assertSet('description', 'Internal profile')
        ->assertSet('sipPort', '5060')
        ->assertSet('sipIp', '10.0.0.1');
});

it('creates a new sip profile', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'External')
        ->set('description', 'External SIP profile')
        ->set('sipPort', '5080')
        ->set('sipIp', '192.168.1.1')
        ->call('save')
        ->assertRedirect(route('panel.sip-profiles.index'));

    $this->assertDatabaseHas('sip_profiles', [
        'name' => 'External',
        'description' => 'External SIP profile',
    ]);

    $profile = SipProfile::withoutGlobalScope('tenant')->where('name', 'External')->first();
    expect($profile->settings)->toMatchArray([
        'sip-port' => '5080',
        'sip-ip' => '192.168.1.1',
    ]);
});

it('updates an existing sip profile', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'Old Profile',
        'settings' => ['sip-port' => '5060'],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class, ['profileId' => $profile->id])
        ->set('name', 'Updated Profile')
        ->set('sipPort', '5070')
        ->call('save')
        ->assertRedirect(route('panel.sip-profiles.index'));

    $this->assertDatabaseHas('sip_profiles', [
        'id' => $profile->id,
        'name' => 'Updated Profile',
    ]);

    $profile->refresh();
    expect($profile->settings)->toMatchArray(['sip-port' => '5070']);
});

it('preserves advanced profile settings when saving the simplified editor', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'Advanced Profile',
        'settings' => [
            'sip-port' => '5060',
            'sip-ip' => '$${local_ip_v4}',
            'rtp-ip' => '$${local_ip_v4}',
            'context' => 'tenant_1_internal',
            'dialplan' => 'XML',
            'dtmf-type' => 'rfc2833',
            'inbound-codec-prefs' => 'G722,PCMU,PCMA',
        ],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class, ['profileId' => $profile->id])
        ->set('sipPort', '5070')
        ->set('sipIp', '10.0.0.5')
        ->call('save')
        ->assertRedirect(route('panel.sip-profiles.index'));

    $profile->refresh();

    expect($profile->settings)
        ->toHaveKey('sip-port', '5070')
        ->toHaveKey('sip-ip', '10.0.0.5')
        ->toHaveKey('rtp-ip', '$${local_ip_v4}')
        ->toHaveKey('context', 'tenant_1_internal')
        ->toHaveKey('dialplan', 'XML')
        ->toHaveKey('dtmf-type', 'rfc2833')
        ->toHaveKey('inbound-codec-prefs', 'G722,PCMU,PCMA');
});

it('normalizes legacy sip profile keys when saving', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'Legacy Profile',
        'settings' => [
            'sip_port' => '5060',
            'sip_ip' => '10.0.0.1',
            'rtp_ip' => '10.0.0.1',
        ],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class, ['profileId' => $profile->id])
        ->call('save')
        ->assertRedirect(route('panel.sip-profiles.index'));

    $profile->refresh();

    expect($profile->settings)
        ->toHaveKey('sip-port', '5060')
        ->toHaveKey('sip-ip', '10.0.0.1')
        ->toHaveKey('rtp-ip', '10.0.0.1')
        ->not->toHaveKey('sip_port')
        ->not->toHaveKey('sip_ip')
        ->not->toHaveKey('rtp_ip');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates sip port is numeric', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Test')
        ->set('sipPort', 'not-a-port')
        ->call('save')
        ->assertHasErrors(['sipPort']);
});

it('validates sip profile name is unique', function () {
    SipProfile::factory()->create(['name' => 'Internal']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Internal')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('toggles enabled state', function () {
    $profile = SipProfile::factory()->create(['enabled' => true]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesEdit::class, ['profileId' => $profile->id])
        ->set('enabled', false)
        ->call('save')
        ->assertRedirect(route('panel.sip-profiles.index'));

    expect($profile->fresh()->enabled)->toBeFalse();
});
