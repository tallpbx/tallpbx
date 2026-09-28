<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\TenantLimits\Livewire\TenantLimitsEdit;
use Modules\TenantLimits\Livewire\TenantLimitsList;
use Modules\TenantLimits\Models\TenantLimit;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    TenantLimit::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsList::class)
        ->assertOk()
        ->assertSee('Tenant Limits')
        ->assertViewHas('limits', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $limit = TenantLimit::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsEdit::class, ['limitId' => $limit->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates a tenant limit', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('resource', 'extensions')
        ->set('softLimit', 10)
        ->set('hardLimit', 20)
        ->call('save')
        ->assertRedirect(route('panel.tenant-limits.index'));

    expect(TenantLimit::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'resource']);
});

it('deletes a tenant limit', function () {
    $limit = TenantLimit::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsList::class)
        ->call('deleteLimit', $limit->id)
        ->assertOk();

    expect(TenantLimit::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a tenant limit', function (): void {
    $limit = TenantLimit::factory()->create(['resource' => 'extensions']);
    Livewire::actingAs($this->admin, 'admin')->test(TenantLimitsList::class)->call('confirmLimitDeletion', $limit->id)->assertSet('pendingDeletionId', $limit->id)->assertSet('pendingDeletionName', 'extensions')->assertSee('Delete Tenant Limit?');
});

it('shows empty state when no limits exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantLimitsList::class)
        ->assertSee('No limits found');
});
