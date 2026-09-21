<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Dialplans\Livewire\DialplansList;
use Modules\Dialplans\Models\Dialplan;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the dialplans list component', function () {
    Dialplan::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class)
        ->assertOk()
        ->assertSee('Dialplans')
        ->assertViewHas('dialplans', function ($dialplans) {
            return $dialplans->count() === 3;
        });
});

it('displays dialplan name and context', function () {
    Dialplan::factory()->create([
        'name' => 'Default',
        'context' => 'default',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class)
        ->assertSee('Default')
        ->assertSee('default');
});

it('deletes a dialplan', function () {
    $dialplan = Dialplan::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class)
        ->call('deleteDialplan', $dialplan->id)
        ->assertDispatched('dialplan-deleted');

    $this->assertModelMissing($dialplan);
});

it('opens the shared confirmation modal before deleting a dialplan', function (): void {
    $dialplan = Dialplan::factory()->create(['name' => 'Office routing']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class)
        ->call('confirmDialplanDeletion', $dialplan->id)
        ->assertSet('pendingDeletionId', $dialplan->id)
        ->assertSet('pendingDeletionName', 'Office routing')
        ->assertSee('Delete Dialplan?');
});

it('shows empty state when no dialplans exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class)
        ->assertSee('No dialplans found');
});

it('shows detail count for each dialplan', function () {
    Dialplan::factory()->hasDetails(5)->create(['name' => 'With Details']);
    Dialplan::factory()->create(['name' => 'Empty']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansList::class);

    $dialplans = $component->viewData('dialplans');
    $withDetails = $dialplans->firstWhere('name', 'With Details');
    $empty = $dialplans->firstWhere('name', 'Empty');

    expect($withDetails->details_count)->toBe(5)
        ->and($empty->details_count)->toBe(0);
});
