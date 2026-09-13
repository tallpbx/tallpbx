<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Modules\Admin\Livewire\TenantsEdit;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
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
