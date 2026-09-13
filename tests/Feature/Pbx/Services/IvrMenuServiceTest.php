<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Services\IvrMenuServiceInterface;

beforeEach(function () {
    $this->service = app(IvrMenuServiceInterface::class);
});

it('creates an IVR menu', function () {
    $tenant = Tenant::factory()->create();

    $menu = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main IVR',
        'greeting' => 'Welcome to our company',
        'timeout' => 15,
        'max_failures' => 5,
        'enabled' => true,
    ]);

    expect($menu)
        ->toBeInstanceOf(IvrMenu::class)
        ->name->toBe('Main IVR')
        ->greeting->toBe('Welcome to our company')
        ->timeout->toBe(15)
        ->max_failures->toBe(5)
        ->enabled->toBeTrue();
});

it('updates an IVR menu', function () {
    $menu = IvrMenu::factory()->create([
        'name' => 'Old Name',
        'timeout' => 10,
    ]);

    $updated = $this->service->update($menu, [
        'name' => 'Updated IVR',
        'timeout' => 20,
    ]);

    expect($updated->name)->toBe('Updated IVR')
        ->and($updated->timeout)->toBe(20);
});

it('deletes an IVR menu', function () {
    $menu = IvrMenu::factory()->create();

    $this->service->delete($menu);

    $this->assertModelMissing($menu);
});

it('fetches IVR menus by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    IvrMenu::factory()->forTenant($tenant1->id)->count(3)->create();
    IvrMenu::factory()->forTenant($tenant2->id)->count(2)->create();

    $tenant1Menus = $this->service->getByTenant($tenant1->id);
    $tenant2Menus = $this->service->getByTenant($tenant2->id);

    expect($tenant1Menus)->toHaveCount(3)
        ->and($tenant2Menus)->toHaveCount(2);
});

it('enforces unique name per tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Duplicate IVR',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Duplicate IVR',
    ]);
});

it('allows same name in different tenants', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $menu1 = $this->service->create([
        'tenant_id' => $tenant1->id,
        'name' => 'Sales IVR',
    ]);

    $menu2 = $this->service->create([
        'tenant_id' => $tenant2->id,
        'name' => 'Sales IVR',
    ]);

    expect($menu1->id)->not->toBe($menu2->id);
});
