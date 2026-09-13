<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\SipProfiles\Livewire\SipProfilesList;
use Modules\SipProfiles\Models\SipProfile;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the sip profiles list component', function () {
    SipProfile::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class)
        ->assertOk()
        ->assertSee('SIP Profiles')
        ->assertSee('A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH')
        ->assertViewHas('profiles', function ($profiles) {
            return $profiles->count() === 3;
        });
});

it('shows profile details in the table', function () {
    SipProfile::factory()->create([
        'name' => 'Internal',
        'description' => 'Internal profile',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class)
        ->assertSee('Internal')
        ->assertSee('Internal profile');
});

it('deletes a sip profile', function () {
    $profile = SipProfile::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class)
        ->call('deleteProfile', $profile->id)
        ->assertDispatched('profile-deleted');

    $this->assertModelMissing($profile);
});

it('opens the shared confirmation modal before deleting a SIP profile', function (): void {
    $profile = SipProfile::factory()->create(['name' => 'Internal profile']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class)
        ->call('confirmProfileDeletion', $profile->id)
        ->assertSet('pendingDeletionId', $profile->id)
        ->assertSet('pendingDeletionName', 'Internal profile')
        ->assertSee('Delete SIP Profile?');
});

it('shows empty state when no profiles exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class)
        ->assertSee('No SIP profiles found');
});

it('shows enabled badge for active profiles', function () {
    SipProfile::factory()->create(['name' => 'Active', 'enabled' => true]);
    SipProfile::factory()->create(['name' => 'Disabled', 'enabled' => false]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SipProfilesList::class);

    $profiles = $component->viewData('profiles');
    $active = $profiles->firstWhere('name', 'Active');
    $disabled = $profiles->firstWhere('name', 'Disabled');

    expect($active->enabled)->toBeTrue()
        ->and($disabled->enabled)->toBeFalse();
});
