<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ImpersonationServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Base class for the unified panel Livewire list components.
 *
 * Uses a single layout (layouts.app) for both Admin and User models.
 * The isAdminGuard() helper is preserved for tenant-scoped query logic
 * in subclasses. The render() method is handled automatically.
 *
 * Subclasses define their own collection property, load/delete logic,
 * and any custom methods.
 */
abstract class BaseListComponent extends Component
{
    use Concerns\HasOperationalFeedback;

    /**
     * Check whether the current user is authenticated via the admin guard.
     */
    protected function isAdminGuard(): bool
    {
        return Auth::guard('admin')->check()
            && ! app(ImpersonationServiceInterface::class)->isImpersonating();
    }

    /**
     * Render the component's view with the unified panel layout.
     *
     * Convention: Modules\{PascalModule}\Livewire\{Name}List
     *   resolves to {kebab-module}::{kebab-name}-list
     *
     * Override this method in subclasses with non-standard view paths.
     */
    public function render(): View
    {
        return view($this->resolveListView())
            ->layout('layouts.app');
    }

    /**
     * Resolve the Blade view name from the component's fully-qualified
     * class name using the standard module naming convention.
     */
    protected function resolveListView(): string
    {
        return $this->resolveViewName();
    }

    /**
     * Convert a fully-qualified Livewire component class name to a
     * namespaced Blade view path.
     *
     * Supports both new (app-modules/{name}/src/Livewire/) and legacy
     * (modules/{name}/src/Http/Livewire/) directory structures.
     *
     * Example: Modules\Bridges\Livewire\BridgesList
     *   → bridges::bridges-list
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
