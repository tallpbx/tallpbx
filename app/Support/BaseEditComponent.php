<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Services\ImpersonationServiceInterface;
use App\Services\TenantManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Base class for admin and client Livewire edit/create components.
 *
 * Auto-detects the active guard (admin vs web) and applies the
 * correct layout. Provides common tenant properties, a guard-aware
 * loadTenants() method, and auto-resolved render() view name.
 *
 * Subclasses define their own form fields, validation rules, and
 * the mount() and save() methods with entity-specific logic.
 */
abstract class BaseEditComponent extends Component
{
    use Concerns\HasOperationalFeedback;

    /** The selected tenant ID for the record being edited. */
    public ?int $tenantId = null;

    /** Whether the record is enabled or disabled. */
    public bool $enabled = true;

    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    /**
     * Check whether the current user is authenticated via the admin guard.
     */
    protected function isAdminGuard(): bool
    {
        return Auth::guard('admin')->check()
            && ! app(ImpersonationServiceInterface::class)->isImpersonating();
    }

    /**
     * Resolve the tenant ID for the current user.
     *
     * For admin users, returns the currently selected $this->tenantId
     * (from the dropdown). For client users, auto-resolves from the
     * currently authorized tenant context.
     */
    protected function resolveTenantId(): ?int
    {
        if ($this->isAdminGuard()) {
            return $this->tenantId;
        }

        $tenantId = app(TenantManager::class)->getTenantId();

        return $tenantId !== null ? (int) $tenantId : null;
    }

    /**
     * Abort with HTTP 403 when a tenant user tries to open or edit a record
     * that belongs to another tenant.
     *
     * Admins may open any record. Tenant users may only open records of the
     * active tenant context, so edit forms never leak cross-tenant data and
     * never offer a save path into another tenant.
     *
     * @param  Model  $record  The record loaded for the edit form.
     */
    protected function assertCanAccessTenantRecord(Model $record): void
    {
        if ($this->isAdminGuard()) {
            return;
        }

        $activeTenantId = app(TenantManager::class)->getTenantId();

        // Fail closed when there is no active tenant context or the record
        // belongs to a different tenant.
        if ($activeTenantId === null || (string) $record->tenant_id !== (string) $activeTenantId) {
            abort(403, 'Cross-tenant access denied.');
        }
    }

    /**
     * Load the list of available tenants into the $tenants property.
     * Admin create forms preselect the Default tenant when present;
     * client users are auto-scoped without loading the tenant list.
     */
    protected function loadTenants(): void
    {
        if ($this->isAdminGuard()) {
            $this->tenants = Tenant::orderBy('name')->get();

            if ($this->tenantId === null) {
                $this->tenantId = $this->tenants
                    ->firstWhere('purpose', Tenant::PURPOSE_DEFAULT)
                    ?->id;
            }

            return;
        }

        $this->tenants = new Collection;
    }

    /**
     * Render the component's view with guard-appropriate layout, auto-resolved
     * from the class name.
     *
     * Convention: Modules\{PascalModule}\Livewire\{Name}Edit
     *   resolves to {kebab-module}::{kebab-name}-edit
     *
     * Override this method in subclasses with non-standard view paths.
     */
    public function render(): View
    {
        return view($this->resolveViewName())
            ->layout('layouts.app');
    }

    /**
     * Convert the fully-qualified class name to a namespaced Blade view.
     *
     * Supports both new (app-modules/{name}/src/Livewire/) and legacy
     * (modules/{name}/src/Http/Livewire/) directory structures.
     *
     * Example: Modules\Bridges\Livewire\BridgesEdit
     *   → bridges::bridges-edit
     */
    protected function resolveViewName(): string
    {
        $reflection = new \ReflectionClass(static::class);
        $filePath = $reflection->getFileName();

        // Support both new (app-modules/.../src/Livewire/) and legacy (modules/.../src/Http/Livewire/) paths
        $pattern = '#(?:app-)?modules/([^/]+)/src/(Http/)?Livewire/(.+)\.php$#';

        preg_match($pattern, $filePath, $m);

        $moduleDir = $m[1] ?? '';
        $isLegacy = ($m[2] ?? '') !== '';
        $className = $m[3] ?? '';

        $viewName = Str::of($className)->snake('-')->toString();

        // Legacy modules have views in resources/views/livewire/, new ones in resources/views/
        $prefix = $isLegacy ? 'livewire.' : '';

        return "{$moduleDir}::{$prefix}{$viewName}";
    }
}
