<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\XmlCdr\Livewire\CdrDetail;
use Modules\XmlCdr\Livewire\CdrList;
use Modules\XmlCdr\Models\Cdr;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the CDR list component', function () {
    Cdr::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertOk()
        ->assertSee('Call Detail Records')
        ->assertViewHas('records', function ($records) {
            return $records->count() === 3;
        });
});

it('renders the CDR detail component', function () {
    $cdr = Cdr::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrDetail::class, ['cdrId' => $cdr->id])
        ->assertOk()
        ->assertSee($cdr->caller_id);
});

it('deletes a CDR record', function () {
    $cdr = Cdr::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->call('deleteCdr')
        ->assertSet('operationalMessage', 'Call detail record deleted.')
        ->assertDispatched('cdr-deleted');

    $this->assertModelMissing($cdr);
});

it('opens the shared confirmation modal before deleting a call detail record', function (): void {
    $cdr = Cdr::factory()->create(['call_uuid' => 'abc-123', 'destination' => '15551234567']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->assertSet('pendingDeletionName', 'abc-123')
        ->assertSee('Delete Call Detail Record?');
});

it('opens the confirmation modal safely when a CDR has no call UUID or destination', function (): void {
    $cdr = Cdr::factory()->create(['call_uuid' => null, 'destination' => null]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->assertSet('pendingDeletionName', '')
        ->assertSee('Delete Call Detail Record?');
});

it('shows empty state when no CDRs exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertSee('No CDR records found');
});
