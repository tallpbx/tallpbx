<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\CallBlocks\Livewire\CallBlocksEdit;
use Modules\CallBlocks\Livewire\CallBlocksList;
use Modules\CallBlocks\Models\CallBlock;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the call blocks list component', function () {
    CallBlock::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksList::class)
        ->assertOk()
        ->assertSee('Call Blocks')
        ->assertViewHas('blocks', function ($blocks) {
            return $blocks->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('name', '')
        ->assertSet('enabled', true);
});

it('creates a new call block rule', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Block Spam')
        ->set('callerIdNumber', '1234567890')
        ->call('save')
        ->assertRedirect(route('panel.call-blocks.index'));

    $this->assertDatabaseHas('call_blocks', [
        'name' => 'Block Spam',
        'caller_id_number' => '1234567890',
    ]);
});

it('updates an existing call block rule', function () {
    $block = CallBlock::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksEdit::class, ['blockId' => $block->id])
        ->set('name', 'Updated Name')
        ->call('save')
        ->assertRedirect(route('panel.call-blocks.index'));

    $this->assertDatabaseHas('call_blocks', [
        'id' => $block->id,
        'name' => 'Updated Name',
    ]);
});

it('deletes a call block rule', function () {
    $block = CallBlock::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksList::class)
        ->call('deleteBlock', $block->id)
        ->assertDispatched('block-deleted');

    $this->assertModelMissing($block);
});

it('opens the shared confirmation modal before deleting a call block rule', function (): void {
    $block = CallBlock::factory()->create(['name' => 'Block unwanted calls']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksList::class)
        ->call('confirmBlockDeletion', $block->id)
        ->assertSet('pendingDeletionId', $block->id)
        ->assertSet('pendingDeletionName', 'Block unwanted calls')
        ->assertSee('Delete Call Block?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates caller id number is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksEdit::class)
        ->set('callerIdNumber', '')
        ->call('save')
        ->assertHasErrors(['callerIdNumber' => 'required']);
});

it('shows empty state when no call blocks exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallBlocksList::class)
        ->assertSee('No call blocks found');
});
