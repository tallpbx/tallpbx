<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\CallBroadcast\Jobs\SendCallBroadcast;
use Modules\CallBroadcast\Livewire\BroadcastList;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

function broadcastSendFixture(): CallBroadcast
{
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'draft',
    ]);
    CallBroadcastRecipient::factory()->count(2)->create(['broadcast_id' => $broadcast->id]);

    return $broadcast;
}

it('sends a draft broadcast and dispatches the originate job', function () {
    Queue::fake();
    $broadcast = broadcastSendFixture();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->call('confirmSend', $broadcast->id)
        ->assertSet('sendingId', $broadcast->id)
        ->call('sendBroadcast')
        ->assertSet('sendingId', null);

    expect($broadcast->fresh()->status)->toBe('sending');
    Queue::assertPushed(SendCallBroadcast::class, fn ($job) => $job->broadcastId === $broadcast->id);
});

it('runs the originate job end to end after the send action', function () {
    Queue::fake();
    $broadcast = broadcastSendFixture();
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->with(1)->andReturn(true);
    $mock->shouldReceive('bgapi')->twice()->andReturn('+OK');
    $mock->shouldReceive('disconnect');
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->call('confirmSend', $broadcast->id)
        ->call('sendBroadcast');

    // The queued job must actually originate for the claimed broadcast:
    // the component already flipped it to sending, so the job runs for
    // sending state, not just draft.
    $job = Queue::pushed(SendCallBroadcast::class)->first();
    $job->handle();

    expect($broadcast->fresh()->status)->toBe('sending')
        ->and($broadcast->recipients()->where('call_status', 'attempted')->count())->toBe(2);
});

it('does not send a broadcast that is not a draft', function () {
    Queue::fake();
    $broadcast = broadcastSendFixture();
    $broadcast->update(['status' => 'completed']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->call('confirmSend', $broadcast->id)
        ->call('sendBroadcast');

    expect($broadcast->fresh()->status)->toBe('completed');
    Queue::assertNotPushed(SendCallBroadcast::class);
});

it('renders a send button only for draft broadcasts', function () {
    $draft = broadcastSendFixture();
    $completed = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Done',
        'status' => 'completed',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->assertSeeHtml('wire:click="confirmSend(\''.$draft->id.'\')"')
        ->assertDontSeeHtml('wire:click="confirmSend(\''.$completed->id.'\')"');
});

it('renders answered and failed counts for non-draft broadcasts', function () {
    $broadcast = broadcastSendFixture();
    $broadcast->update(['status' => 'completed']);
    $broadcast->recipients()->first()->update(['call_status' => 'answered', 'call_duration' => 12]);
    $broadcast->recipients()->get()->last()->update(['call_status' => 'failed', 'hangup_cause' => 'NO_ANSWER']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->assertSee('1 answered', false)
        ->assertSee('1 Failed', false);
});

it('hides outcome counts for draft broadcasts', function () {
    broadcastSendFixture();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->assertDontSee('answered', false)
        ->assertDontSee('failed', false);
});
