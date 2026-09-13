<?php

declare(strict_types=1);

namespace App\Livewire\Layout;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Display settings component for controlling layout mode, sidebar size, and theme.
 *
 * Persists preferences (layout mode, sidebar collapsed state, and color theme)
 * to the authenticated user or admin record, and dispatches browser events
 * for Alpine.js to apply dynamic visual changes without full page reloads.
 */
class DisplaySettings extends Component
{
    /**
     * The active color theme ('light', 'dark', or 'system').
     */
    public string $theme = 'system';

    /**
     * The active layout mode ('sidebar' or 'horizontal').
     */
    public string $layoutMode = 'sidebar';

    /**
     * Whether the sidebar is collapsed into mini icon-rail mode.
     */
    public bool $sidebarCollapsed = false;

    /**
     * Initialize preferences from the authenticated user or admin model.
     */
    public function mount(): void
    {
        if (auth()->guard('admin')->check()) {
            $admin = auth()->guard('admin')->user();
            $this->theme = $admin->theme ?? 'system';
            $this->layoutMode = $admin->layout_mode ?? 'sidebar';
            $this->sidebarCollapsed = (bool) ($admin->sidebar_collapsed ?? false);

            // Dispatch authoritative state to Alpine.js
            $this->dispatch('theme-changed', theme: $this->theme);
            $this->dispatch('layout-changed', mode: $this->layoutMode);
            $this->dispatch('sidebar-collapse-changed', collapsed: $this->sidebarCollapsed);
        } elseif (auth()->check()) {
            $user = auth()->user();
            $this->theme = $user->theme ?? 'system';
            $this->layoutMode = $user->layout_mode ?? 'sidebar';
            $this->sidebarCollapsed = (bool) ($user->sidebar_collapsed ?? false);

            // Dispatch authoritative state to Alpine.js
            $this->dispatch('theme-changed', theme: $this->theme);
            $this->dispatch('layout-changed', mode: $this->layoutMode);
            $this->dispatch('sidebar-collapse-changed', collapsed: $this->sidebarCollapsed);
        }
    }

    /**
     * Set the layout mode ('sidebar' or 'horizontal') and persist to database.
     */
    public function setLayoutMode(string $mode): void
    {
        if (! in_array($mode, ['sidebar', 'horizontal'], true)) {
            return;
        }

        $this->layoutMode = $mode;

        if (auth()->guard('admin')->check()) {
            auth()->guard('admin')->user()->update(['layout_mode' => $mode]);
        } elseif (auth()->check()) {
            auth()->user()->update(['layout_mode' => $mode]);
        }

        $this->dispatch('layout-changed', mode: $mode);
    }

    /**
     * Set the sidebar collapsed state and persist to database.
     */
    #[On('sidebar-collapse-toggled')]
    public function setSidebarCollapsed(bool $collapsed): void
    {
        $this->sidebarCollapsed = $collapsed;

        if (auth()->guard('admin')->check()) {
            auth()->guard('admin')->user()->update(['sidebar_collapsed' => $collapsed]);
        } elseif (auth()->check()) {
            auth()->user()->update(['sidebar_collapsed' => $collapsed]);
        }

        $this->dispatch('sidebar-collapse-changed', collapsed: $collapsed);
    }

    /**
     * Set the color theme ('light', 'dark', or 'system') and persist to database.
     */
    public function setTheme(string $theme): void
    {
        if (! in_array($theme, ['light', 'dark', 'system'], true)) {
            return;
        }

        $this->theme = $theme;

        if (auth()->guard('admin')->check()) {
            auth()->guard('admin')->user()->update(['theme' => $theme]);
        } elseif (auth()->check()) {
            auth()->user()->update(['theme' => $theme]);
        }

        $this->dispatch('theme-changed', theme: $theme);
    }

    /**
     * Render the display settings component view.
     */
    public function render(): View
    {
        return view('livewire.layout.display-settings');
    }
}
