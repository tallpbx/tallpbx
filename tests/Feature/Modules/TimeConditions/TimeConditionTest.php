<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\TimeConditions\Livewire\TimeConditionsEdit;
use Modules\TimeConditions\Livewire\TimeConditionsList;
use Modules\TimeConditions\Models\TimeCondition;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the time conditions list component', function () {
    TimeCondition::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsList::class)
        ->assertOk()
        ->assertSee('Time Conditions')
        ->assertViewHas('timeConditions', function ($conditions) {
            return $conditions->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('enabled', true);
});

it('creates a new time condition', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Business Hours')
        ->set('weekdays', 'mon,tue,wed,thu,fri')
        ->set('startTime', '09:00')
        ->set('endTime', '17:00')
        ->call('save')
        ->assertRedirect(route('panel.time-conditions.index'));

    $this->assertDatabaseHas('time_conditions', [
        'name' => 'Business Hours',
        'weekdays' => 'mon,tue,wed,thu,fri',
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
});

it('updates an existing time condition', function () {
    $condition = TimeCondition::factory()->create(['name' => 'Old Hours']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsEdit::class, ['timeConditionId' => $condition->id])
        ->set('name', 'Extended Hours')
        ->set('endTime', '20:00')
        ->call('save')
        ->assertRedirect(route('panel.time-conditions.index'));

    $this->assertDatabaseHas('time_conditions', [
        'id' => $condition->id,
        'name' => 'Extended Hours',
        'end_time' => '20:00',
    ]);
});

it('deletes a time condition', function () {
    $condition = TimeCondition::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsList::class)
        ->call('deleteTimeCondition', $condition->id)
        ->assertDispatched('time-condition-deleted');

    $this->assertModelMissing($condition);
});

it('opens the shared confirmation modal before deleting a time condition', function (): void {
    $condition = TimeCondition::factory()->create(['name' => 'Business hours']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsList::class)
        ->call('confirmTimeConditionDeletion', $condition->id)
        ->assertSet('pendingDeletionId', $condition->id)
        ->assertSet('pendingDeletionName', 'Business hours')
        ->assertSee('Delete Time Condition?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state when no time conditions exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TimeConditionsList::class)
        ->assertSee('No time conditions found');
});
