<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\EmailQueue\Livewire\EmailQueueList;
use Modules\EmailQueue\Models\EmailQueueItem;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    EmailQueueItem::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailQueueList::class)
        ->assertOk()
        ->assertSee('Email Queue')
        ->assertViewHas('items', fn ($items) => $items->count() === 2);
});

it('shows empty state when no emails queued', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailQueueList::class)
        ->assertSee('No queued emails found');
});

it('deletes a queued email', function () {
    $item = EmailQueueItem::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailQueueList::class)
        ->call('confirmItemDeletion', $item->id)
        ->assertSet('pendingDeletionId', $item->id)
        ->call('deleteItem')
        ->assertSet('operationalMessage', 'Email deleted.')
        ->assertOk();

    expect(EmailQueueItem::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a queued email', function (): void {
    $item = EmailQueueItem::factory()->create(['subject' => 'Welcome email']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailQueueList::class)
        ->call('confirmItemDeletion', $item->id)
        ->assertSet('pendingDeletionId', $item->id)
        ->assertSet('pendingDeletionName', 'Welcome email')
        ->assertSee('Delete Email?');
});
