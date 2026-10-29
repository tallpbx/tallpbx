<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\BaseEditComponent;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Livewire;
use ReflectionMethod;

/*
|--------------------------------------------------------------------------
| Discovery Helpers
|--------------------------------------------------------------------------
*/

/**
 * Discover edit components whose mount() bypasses the tenant scope.
 *
 * @return array<string, array{class: class-string, param: string, model: class-string}>
 */
function discoverTenantBypassingEditComponents(): array
{
    $components = [];
    $projectRoot = dirname(__DIR__, 3);

    foreach (glob($projectRoot.'/app-modules/*/src/Livewire/*Edit.php') as $file) {
        $source = (string) file_get_contents($file);

        if (! preg_match('#app-modules/([^/]+)/src/Livewire/([^/]+)\.php$#', $file, $m)) {
            continue;
        }

        $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $m[1])));
        $class = "Modules\\{$module}\\Livewire\\{$m[2]}";

        if (! class_exists($class) || ! is_subclass_of($class, BaseEditComponent::class)) {
            continue;
        }

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

/**
 * Discover list components whose load query bypasses the tenant scope.
 *
 * @return array<string, array{class: class-string, model: class-string, viewKey: string|null}>
 */
function discoverUnscopedListComponents(): array
{
    $components = [];
    $projectRoot = dirname(__DIR__, 3);

    $files = array_merge(
        glob($projectRoot.'/app-modules/*/src/Livewire/*List.php') ?: [],
        [$projectRoot.'/app-modules/fax/src/Livewire/FaxInbox.php'],
    );

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, "withoutGlobalScope('tenant')")) {
            continue;
        }

        if (str_contains($source, 'authorizeSystemAdmin')) {
            continue;
        }

        if (! preg_match('#app-modules/([^/]+)/src/Livewire/([^/]+)\.php$#', $file, $m)) {
            continue;
        }

        $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $m[1])));
        $class = "Modules\\{$module}\\Livewire\\{$m[2]}";

        if (! class_exists($class) || ! is_subclass_of($class, BaseListComponent::class)) {
            continue;
        }

        if (! preg_match('/([A-Za-z]+)::withoutGlobalScope/', $source, $modelMatch)) {
            continue;
        }

        $model = null;

        if (preg_match('/use (Modules[\\\\A-Za-z]*\\\\'.$modelMatch[1].');/', $source, $useMatch)) {
            $model = $useMatch[1];
        } elseif (preg_match('/use (Modules[\\\\A-Za-z]+)\\\\([A-Za-z]+) as '.$modelMatch[1].';/', $source, $aliasMatch)) {
            $model = $aliasMatch[1].'\\'.$aliasMatch[2];
        }

        if ($model === null) {
            continue;
        }

        $viewKey = null;

        if (preg_match("/return view\s*\(.+?,\s*\[\s*'([a-zA-Z]+)'\s*=>/s", $source, $viewMatch)) {
            $viewKey = $viewMatch[1];
        }

        $components[$m[2]] = [
            'class' => $class,
            'model' => $model,
            'viewKey' => $viewKey,
        ];
    }

    ksort($components);

    return $components;
}

/**
 * Collect the keys of every record of the given model class that the mounted
 * component exposes, through public collection properties and view data.
 *
 * @return array<int, string|int>
 */
function exposedRecordKeys($livewire, string $model, ?string $viewKey): array
{
    $keys = [];

    $collect = function ($data) use ($model, &$keys): void {
        $items = match (true) {
            $data instanceof EloquentCollection => $data->all(),
            $data instanceof LengthAwarePaginator => $data->items(),
            default => [],
        };

        foreach ($items as $item) {
            if ($item instanceof $model) {
                $keys[] = $item->getKey();
            }
        }
    };

    if ($viewKey !== null) {
        $collect($livewire->viewData($viewKey));
    }

    foreach (get_object_vars($livewire->instance()) as $property) {
        $collect($property);
    }

    $reflection = new \ReflectionClass($livewire->instance());
    foreach ($reflection->getMethods() as $method) {
        if (! empty($method->getAttributes(Computed::class))) {
            try {
                $collect($livewire->instance()->{$method->getName()});
            } catch (\Throwable) {
            }
        }
    }

    return $keys;
}

/*
|--------------------------------------------------------------------------
| Tenant Rollout Guard Suites
|--------------------------------------------------------------------------
*/

describe('tenant rollout guards', function (): void {
    beforeEach(function (): void {
        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $this->user = tenantUser($this->tenantA);
    });

    afterEach(function (): void {
        app(TenantManager::class)->clear();
    });

    it('blocks a tenant user from opening another tenant\'s record in any edit form', function (string $class, string $param, string $model): void {
        /** @var class-string $model */
        $foreignRecord = $model::factory()->create(['tenant_id' => $this->tenantB->id]);

        Livewire::actingAs($this->user, 'web')
            ->test($class, [$param => (string) $foreignRecord->getKey()])
            ->assertForbidden();
    })->with(fn () => discoverTenantBypassingEditComponents());

    it('allows a tenant user to open their own record in any edit form', function (string $class, string $param, string $model): void {
        /** @var class-string $model */
        $ownRecord = $model::factory()->create(['tenant_id' => $this->tenantA->id]);

        Livewire::actingAs($this->user, 'web')
            ->test($class, [$param => (string) $ownRecord->getKey()])
            ->assertOk();
    })->with(fn () => discoverTenantBypassingEditComponents());

    it('keeps a tenant user from seeing another tenant\'s records in any panel list', function (string $class, string $model, ?string $viewKey): void {
        /** @var class-string $model */
        $ownRecord = $model::factory()->create(['tenant_id' => $this->tenantA->id]);
        $foreignRecord = $model::factory()->create(['tenant_id' => $this->tenantB->id]);

        $livewire = Livewire::actingAs($this->user, 'web')->test($class);

        $exposed = exposedRecordKeys($livewire, $model, $viewKey);

        expect($exposed)
            ->toContain($ownRecord->getKey())
            ->not->toContain($foreignRecord->getKey());
    })->with(fn () => discoverUnscopedListComponents());
});
