<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'member']);
});

it('denies an impersonating admin when selected_tenant_id points to a tenant the impersonated user does not belong to', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin');
    actingAs($this->user, 'web')
        ->withSession([
            'impersonation.original_admin_id' => $admin->id,
            'selected_tenant_id' => (string) $this->otherTenant->id,
        ])
        ->get(route('panel.dashboard'))
        ->assertForbidden();
});

it('allows an impersonating admin when selected_tenant_id points to a tenant the impersonated user belongs to', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin');
    actingAs($this->user, 'web')
        ->withSession([
            'impersonation.original_admin_id' => $admin->id,
            'selected_tenant_id' => (string) $this->tenant->id,
        ])
        ->get(route('panel.dashboard'))
        ->assertOk();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenant->id);
});

it('allows an un-impersonated admin access without tenant restriction', function (): void {
    $admin = grantAdminPermissions();

    actingAs($admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk();

    expect(app(TenantManager::class)->hasTenant())->toBeFalse();
});
