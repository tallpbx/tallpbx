<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\TenantsEdit;
use Modules\Admin\Livewire\TenantsList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the tenants list component', function () {
    Tenant::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->assertOk()
        ->assertSee('Tenants')
        ->assertViewHas('tenants', function ($tenants) {
            return $tenants->count() === 3;
        });
});

it('deletes a tenant after typing the tenant name', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->call('confirmTenantDeletion', $tenant->id)
        ->assertSet('pendingDeletionId', $tenant->id)
        ->set('confirmTypedInput', 'Acme Corp')
        ->call('deleteTenant')
        ->assertSet('operationalMessage', 'Tenant deleted.')
        ->assertDispatched('tenant-deleted');

    $this->assertModelMissing($tenant);
});

it('opens the typed confirmation modal before deleting a tenant', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->call('confirmTenantDeletion', $tenant->id)
        ->assertSet('pendingDeletionId', $tenant->id)
        ->assertSet('pendingDeletionName', 'Acme Corp')
        ->assertSee('Delete Tenant?')
        ->assertSeeHtml('type="text"');
});

it('keeps the tenant when the typed name does not match', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->call('confirmTenantDeletion', $tenant->id)
        ->set('confirmTypedInput', 'Wrong Name')
        ->call('deleteTenant')
        ->assertSet('deleteError', 'The typed text does not match. Nothing was changed.');

    $this->assertModelExists($tenant);
});

it('keeps the tenant and shows an in-modal error when the service refuses', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);

    $service = Mockery::mock(TenantServiceInterface::class);
    $service->shouldReceive('all')->andReturn(new Collection([$tenant]));
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('Tenant has active users.'));
    app()->instance(TenantServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->call('confirmTenantDeletion', $tenant->id)
        ->set('confirmTypedInput', 'Acme Corp')
        ->call('deleteTenant')
        ->assertSet('deleteError', 'Tenant could not be deleted. Tenant has active users.');

    $this->assertModelExists($tenant);
});

it('shows tenant with primary user and enabled status', function () {
    $user = User::factory()->create(['name' => 'Primary Admin']);
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
        'primary_user_id' => $user->id,
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->assertSee('Acme Corp')
        ->assertSee('Primary Admin');
});

it('shows disabled tenant status', function () {
    Tenant::factory()->create(['name' => 'Disabled Tenant', 'enabled' => false]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsList::class)
        ->assertSee('Disabled Tenant');
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->assertOk()
        ->assertSee('Create Tenant')
        ->assertSet('name', '')
        ->assertSet('slug', '');
});

it('renders the edit form with existing tenant data', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class, ['tenantId' => $tenant->id])
        ->assertOk()
        ->assertSee('Edit Tenant')
        ->assertSet('name', 'Acme Corp')
        ->assertSet('slug', 'acme-corp');
});

it('creates a new tenant', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->set('name', 'New Tenant')
        ->set('slug', 'new-tenant')
        ->call('save')
        ->assertRedirect(route('panel.tenants.index'));

    $this->assertDatabaseHas('tenants', [
        'name' => 'New Tenant',
        'slug' => 'new-tenant',
    ]);
});

it('updates an existing tenant', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-slug',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class, ['tenantId' => $tenant->id])
        ->set('name', 'Updated Name')
        ->set('slug', 'updated-slug')
        ->call('save')
        ->assertRedirect(route('panel.tenants.index'));

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'name' => 'Updated Name',
        'slug' => 'updated-slug',
    ]);
});

it('validates name and slug are required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->set('name', '')
        ->set('slug', '')
        ->call('save')
        ->assertHasErrors(['name', 'slug']);
});

it('validates slug is unique', function () {
    Tenant::factory()->create(['slug' => 'taken-slug']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->set('name', 'Test')
        ->set('slug', 'taken-slug')
        ->call('save')
        ->assertHasErrors(['slug']);
});

it('allows same slug when editing the same tenant', function () {
    $tenant = Tenant::factory()->create(['slug' => 'my-slug']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class, ['tenantId' => $tenant->id])
        ->set('name', 'Updated')
        ->set('slug', 'my-slug')
        ->call('save')
        ->assertRedirect(route('panel.tenants.index'));
});

it('can set a primary user for the tenant', function () {
    $user = User::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->set('name', 'Tenant With Primary')
        ->set('slug', 'tenant-with-primary')
        ->set('primaryUserId', $user->id)
        ->call('save')
        ->assertRedirect(route('panel.tenants.index'));

    $this->assertDatabaseHas('tenants', [
        'name' => 'Tenant With Primary',
        'primary_user_id' => $user->id,
    ]);
});

it('lists available users for primary user assignment', function () {
    User::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantsEdit::class)
        ->assertViewHas('users', function ($users) {
            return $users->count() >= 3;
        });
});
