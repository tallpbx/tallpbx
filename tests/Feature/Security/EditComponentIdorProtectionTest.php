<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Modules\Admin\Livewire\AdminsEdit;
use Modules\Admin\Livewire\UsersEdit;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);

    $this->superAdminGroup = Group::whereNull('tenant_id')
        ->where('name', 'Super Administrators')
        ->firstOrFail();

    $this->superAdmin = Admin::factory()->create(['enabled' => true]);
    $this->superAdmin->groups()->attach($this->superAdminGroup->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('prevents an admin with only create ability from modifying a super admin via adminId IDOR update', function (): void {
    $limitedGroup = Group::create([
        'name' => 'Admin Creators',
        'tenant_id' => null,
    ]);
    $createPerm = Permission::where('name', 'admin.users.create')->firstOrFail();
    $limitedGroup->permissions()->attach($createPerm->id);

    $limitedAdmin = Admin::factory()->create(['enabled' => true]);
    $limitedAdmin->groups()->attach($limitedGroup->id);

    $originalEmail = $this->superAdmin->email;

    $renderResponse = $this->actingAs($limitedAdmin, 'admin')
        ->get(route('panel.admins.create'));

    $renderResponse->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $renderResponse->getContent(), $matches);
    expect($matches)->not->toBeEmpty('wire:snapshot must be present in component HTML');

    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

    $updateResponse = $this->actingAs($limitedAdmin, 'admin')
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('panel.livewire.update'), [
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => [
                        'adminId' => $this->superAdmin->id,
                        'email' => 'hijacked@example.com',
                        'name' => 'Hijacked Admin',
                    ],
                    'calls' => [
                        [
                            'path' => '',
                            'method' => 'save',
                            'params' => [],
                        ],
                    ],
                ],
            ],
        ]);

    expect($this->superAdmin->fresh()->email)->toBe($originalEmail);
    // Pin deterministic non-success status code (Livewire CannotUpdateLockedPropertyException returns 500, or authorization returns 403)
    $updateResponse->assertStatus(500);
});

it('forbids a non-super-admin from assigning the Super Administrators group on AdminsEdit', function (): void {
    $adminGroup = Group::create([
        'name' => 'User Managers',
        'tenant_id' => null,
    ]);
    $perms = Permission::whereIn('name', ['admin.users.create', 'admin.users.update', 'admin.users.view'])->get();
    $adminGroup->permissions()->attach($perms->pluck('id'));

    $manager = Admin::factory()->create(['enabled' => true]);
    $manager->groups()->attach($adminGroup->id);

    Livewire::actingAs($manager, 'admin')
        ->test(AdminsEdit::class)
        ->set('name', 'Escalated Admin')
        ->set('email', 'escalated@example.com')
        ->set('password', 'ValidPass123!')
        ->set('password_confirmation', 'ValidPass123!')
        ->set('selectedGroupIds', [(string) $this->superAdminGroup->id])
        ->call('save')
        ->assertForbidden();

    expect(Admin::where('email', 'escalated@example.com')->exists())->toBeFalse();
});

it('forbids a non-super-admin from editing an existing Super Administrator on AdminsEdit', function (): void {
    $adminGroup = Group::create([
        'name' => 'User Managers',
        'tenant_id' => null,
    ]);
    $perms = Permission::whereIn('name', ['admin.users.create', 'admin.users.update', 'admin.users.view'])->get();
    $adminGroup->permissions()->attach($perms->pluck('id'));

    $manager = Admin::factory()->create(['enabled' => true]);
    $manager->groups()->attach($adminGroup->id);

    Livewire::actingAs($manager, 'admin')
        ->test(AdminsEdit::class, ['adminId' => $this->superAdmin->id])
        ->assertForbidden();
});

it('restricts tenant users on UsersEdit from attaching selectedTenantIds outside their accessible tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantUser = grantTenantUserPermissions($tenantA, [
        'admin.users.create',
        'admin.users.update',
        'admin.users.view',
    ]);

    Livewire::actingAs($tenantUser, 'web')
        ->test(UsersEdit::class)
        ->set('name', 'Tenant User 2')
        ->set('email', 'tenant2@example.com')
        ->set('password', 'ValidPass123!')
        ->set('selectedTenantIds', [$tenantA->id, $tenantB->id])
        ->call('save')
        ->assertForbidden();

    expect(User::where('email', 'tenant2@example.com')->exists())->toBeFalse();
});
