<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

/**
 * Guard rollout test: every panel list component that loads records with the
 * tenant scope bypassed must keep tenant users on their own tenant's data.
 *
 * The component list is discovered by scanning the filesystem, so a newly
 * added list component is covered automatically and a missed guard fails
 * here until it is fixed. Admin-gated lists (such as file-stores, which
 * rejects tenant users on mount) are outside this rollout's scope.
 */

/**
 * Discover list components whose load query bypasses the tenant scope.
 *
 * @return array<string, array{class: class-string, model: class-string, viewKey: string|null}>
 */
function discoverUnscopedListComponents(): array
{
    $components = [];

    // Datasets are collected before the app boots, so resolve the project
    // root from this file's location instead of base_path().
    $projectRoot = dirname(__DIR__, 2);

    $files = array_merge(
        glob($projectRoot.'/app-modules/*/src/Livewire/*List.php') ?: [],
        [$projectRoot.'/app-modules/fax/src/Livewire/FaxInbox.php'],
    );

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, "withoutGlobalScope('tenant')")) {
            continue;
        }

        // Lists that reject tenant users on mount (admin-only pages) never
        // reach the unscoped query, so they are not part of this rollout.
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

        // Extract the model class behind the unscoped query, resolving both
        // plain and aliased use statements (e.g. FaxInbox as FaxInboxModel).
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

        // Paginated lists expose records through a view variable instead of
        // a public collection property; capture the key for the assertion.
        $viewKey = null;

        if (preg_match("/return view\([^)]*?,\s*\[\s*'([a-zA-Z]+)'\s*=>/s", $source, $viewMatch)) {
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

    return $keys;
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

it('keeps a tenant user from seeing another tenant\'s records in any panel list', function (string $class, string $model, ?string $viewKey) {
    /** @var class-string $model */
    $ownRecord = $model::factory()->create(['tenant_id' => $this->tenantA->id]);
    $foreignRecord = $model::factory()->create(['tenant_id' => $this->tenantB->id]);

    $livewire = Livewire::actingAs($this->user, 'web')->test($class);

    $exposed = exposedRecordKeys($livewire, $model, $viewKey);

    // The user's own record must still be visible (proving the component
    // genuinely renders), while the other tenant's record must never leak.
    expect($exposed)
        ->toContain($ownRecord->getKey())
        ->not->toContain($foreignRecord->getKey());
})->with(fn () => discoverUnscopedListComponents());
