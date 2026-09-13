<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\BaseEditComponent;
use Livewire\Livewire;

/**
 * Guard rollout test: every panel edit component that loads a record with
 * the tenant scope bypassed must refuse to mount when a tenant user opens
 * a record that belongs to another tenant.
 *
 * The component list is discovered by scanning the filesystem and reading
 * each mount() method's source, so a newly added edit component is covered
 * automatically and a missed guard fails here until it is fixed.
 */

/**
 * Discover edit components whose mount() bypasses the tenant scope.
 *
 * @return array<string, array{class: class-string, param: string, model: class-string}>
 */
function discoverTenantBypassingEditComponents(): array
{
    $components = [];

    // Datasets are collected before the app boots, so resolve the project
    // root from this file's location instead of base_path().
    $projectRoot = dirname(__DIR__, 2);

    foreach (glob($projectRoot.'/app-modules/*/src/Livewire/*Edit.php') as $file) {
        $source = (string) file_get_contents($file);

        // Derive the fully-qualified component class from the module path.
        if (! preg_match('#app-modules/([^/]+)/src/Livewire/([^/]+)\.php$#', $file, $m)) {
            continue;
        }

        $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $m[1])));
        $class = "Modules\\{$module}\\Livewire\\{$m[2]}";

        if (! class_exists($class) || ! is_subclass_of($class, BaseEditComponent::class)) {
            continue;
        }

        // Inspect only the mount() body: components that bypass the tenant
        // scope unconditionally need the ownership guard, while mounts that
        // bypass it only inside an isAdminGuard() branch (e.g. BridgesEdit)
        // already keep tenant users on the scoped query.
        if (! preg_match('/function mount.*?\n    \}/s', $source, $mountMatch)) {
            continue;
        }

        $mountSource = $mountMatch[0];

        if (! str_contains($mountSource, "withoutGlobalScope('tenant')->findOrFail")) {
            continue;
        }

        if (str_contains($mountSource, 'isAdminGuard()')) {
            continue;
        }

        $reflection = new ReflectionMethod($class, 'mount');
        $parameter = $reflection->getParameters()[0] ?? null;

        if ($parameter === null) {
            continue;
        }

        // Extract the model used in the mount() bypass so the test can
        // create a foreign-tenant record through its factory.
        if (! preg_match('/([A-Za-z]+)::withoutGlobalScope/', $mountSource, $modelMatch)) {
            continue;
        }

        if (! preg_match('/use (Modules[\\\\A-Za-z]*\\\\'.$modelMatch[1].');/', $source, $useMatch)) {
            continue;
        }

        $components[$m[2]] = [
            'class' => $class,
            'param' => $parameter->getName(),
            'model' => $useMatch[1],
        ];
    }

    ksort($components);

    return $components;
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    // The acting user belongs to tenant A only; tenantUser() also sets the
    // active tenant context to tenant A.
    $this->user = tenantUser($this->tenantA);
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

it('blocks a tenant user from opening another tenant\'s record in any edit form', function (string $class, string $param, string $model) {
    /** @var class-string $model */
    $foreignRecord = $model::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($this->user, 'web')
        ->test($class, [$param => (string) $foreignRecord->getKey()])
        ->assertForbidden();
})->with(fn () => discoverTenantBypassingEditComponents());

it('allows a tenant user to open their own record in any edit form', function (string $class, string $param, string $model) {
    /** @var class-string $model */
    $ownRecord = $model::factory()->create(['tenant_id' => $this->tenantA->id]);

    Livewire::actingAs($this->user, 'web')
        ->test($class, [$param => (string) $ownRecord->getKey()])
        ->assertOk();
})->with(fn () => discoverTenantBypassingEditComponents());
