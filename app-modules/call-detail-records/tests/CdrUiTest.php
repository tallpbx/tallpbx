<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\CallDetailRecords\Livewire\CdrDetail;
use Modules\CallDetailRecords\Livewire\CdrList;
use Modules\CallDetailRecords\Models\Cdr;

/*
 * The panel must show both halves of every call at a glance: a leg filter on
 * the list, a per-row recording shortcut, and the untouched payload on the
 * detail page for technical diagnostics.
 */

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('filters the list by call side', function () {
    Cdr::factory()->create(['leg' => 'a']);
    Cdr::factory()->create(['leg' => 'b']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertViewHas('records', fn ($records) => $records->count() === 2)
        ->set('legFilter', 'b')
        ->assertViewHas('records', fn ($records) => $records->count() === 1 && $records->first()->leg === 'b')
        ->set('legFilter', 'a')
        ->assertViewHas('records', fn ($records) => $records->count() === 1 && $records->first()->leg === 'a')
        ->set('legFilter', 'all')
        ->assertViewHas('records', fn ($records) => $records->count() === 2);
});

it('shows the leg badge and the recording shortcut in the list', function () {
    $recorded = Cdr::factory()->create(['leg' => 'b', 'record_path' => '/var/lib/tallpbx/media/store/call.wav']);
    Cdr::factory()->create(['leg' => 'a', 'record_path' => null]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertSee('B')
        ->assertSee('/panel/cdr/'.$recorded->id.'/recording');

    // Unrecorded calls must not render a dead playback link.
    $other = Cdr::withoutGlobalScope('tenant')->whereNull('record_path')->first();
    expect($other)->not->toBeNull();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertDontSee('/panel/cdr/'.$other->id.'/recording');
});

it('shows the recording player and raw diagnostics on the detail page', function () {
    $cdr = Cdr::factory()->create([
        'leg' => 'a',
        'record_path' => '/var/lib/tallpbx/media/store/call.wav',
        'json_data' => ['variables' => ['uuid' => 'a-leg-uuid']],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrDetail::class, ['cdrId' => $cdr->id])
        ->assertSee('Raw Technical Diagnostics')
        ->assertSee('a-leg-uuid')
        ->assertSee('/panel/cdr/'.$cdr->id.'/recording');
});

it('explains a missing recording on the detail page', function () {
    $cdr = Cdr::factory()->create(['record_path' => null]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrDetail::class, ['cdrId' => $cdr->id])
        ->assertSee('No recording was made')
        ->assertDontSee('<audio');
});
