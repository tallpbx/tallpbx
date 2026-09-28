<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\CallCenters\Livewire\QueueEdit;
use Modules\CallCenters\Livewire\QueueList;
use Modules\CallCenters\Models\Queue;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the queue list component', function () {
    Queue::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueList::class)
        ->assertOk()
        ->assertSee('Call Center Queues')
        ->assertViewHas('queues', fn ($q) => $q->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueEdit::class)->assertOk()->assertSee('Create');
});

it('creates a queue', function () {
    $tenant = Tenant::factory()->create();
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueEdit::class)
        ->set('tenantId', $tenant->id)->set('name', 'Sales Queue')
        ->set('strategy', 'ring-all')->call('save')
        ->assertRedirect(route('panel.call-centers.queues.index'));
    $this->assertDatabaseHas('call_center_queues', ['name' => 'Sales Queue']);
});

it('updates a queue', function () {
    $queue = Queue::factory()->create(['name' => 'Old Queue']);
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueEdit::class, ['queueId' => $queue->id])
        ->set('name', 'Updated Queue')->call('save')
        ->assertRedirect(route('panel.call-centers.queues.index'));
    $this->assertDatabaseHas('call_center_queues', ['id' => $queue->id, 'name' => 'Updated Queue']);
});

it('deletes a queue', function () {
    $queue = Queue::factory()->create();
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueList::class)->call('deleteQueue', $queue->id)
        ->assertDispatched('queue-deleted');
    $this->assertModelMissing($queue);
});

it('opens the shared confirmation modal before deleting a queue', function (): void {
    $queue = Queue::factory()->create(['name' => 'Support queue']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueList::class)
        ->call('confirmQueueDeletion', $queue->id)
        ->assertSet('pendingDeletionId', $queue->id)
        ->assertSet('pendingDeletionName', 'Support queue')
        ->assertSee('Delete Queue?');
});

it('validates name required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueEdit::class)->set('name', '')->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(QueueList::class)->assertSee('No queues found');
});
