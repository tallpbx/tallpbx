<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\FollowMe\Livewire\FollowMeEdit;
use Modules\FollowMe\Livewire\FollowMeList;
use Modules\FollowMe\Models\FollowMe;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the follow me list component', function () {
    FollowMe::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeList::class)
        ->assertOk()
        ->assertSee('Follow Me')
        ->assertViewHas('followMeRecords', function ($records) {
            return $records->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('ringTimeout', 30)
        ->assertSet('enabled', true);
});

it('creates a new follow me record', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Work Forwarding')
        ->set('extension', '1000')
        ->set('destination', '+15551234567')
        ->call('save')
        ->assertRedirect(route('panel.follow-me.index'));

    $this->assertDatabaseHas('follow_me', [
        'name' => 'Work Forwarding',
        'extension' => '1000',
        'destination' => '+15551234567',
    ]);
});

it('updates an existing follow me record', function () {
    $record = FollowMe::factory()->create(['name' => 'Old Forwarding']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeEdit::class, ['followMeId' => $record->id])
        ->set('name', 'Updated Forwarding')
        ->set('destination', '+15559876543')
        ->call('save')
        ->assertRedirect(route('panel.follow-me.index'));

    $this->assertDatabaseHas('follow_me', [
        'id' => $record->id,
        'name' => 'Updated Forwarding',
        'destination' => '+15559876543',
    ]);
});

it('deletes a follow me record', function () {
    $record = FollowMe::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeList::class)
        ->call('deleteFollowMe', $record->id)
        ->assertDispatched('follow-me-deleted');

    $this->assertModelMissing($record);
});

it('opens the shared confirmation modal before deleting a follow-me rule', function (): void {
    $record = FollowMe::factory()->create(['name' => 'After hours']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeList::class)
        ->call('confirmFollowMeDeletion', $record->id)
        ->assertSet('pendingDeletionId', $record->id)
        ->assertSet('pendingDeletionName', 'After hours')
        ->assertSee('Delete Follow-Me Rule?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates extension is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeEdit::class)
        ->set('extension', '')
        ->call('save')
        ->assertHasErrors(['extension' => 'required']);
});

it('shows empty state when no follow me records exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FollowMeList::class)
        ->assertSee('No follow me records found');
});
