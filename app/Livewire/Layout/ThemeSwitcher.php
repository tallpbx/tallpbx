<?php

declare(strict_types=1);

namespace App\Livewire\Layout;

use Livewire\Component;

/**
 * Theme switcher component for light/dark/system mode.
 *
 * Syncs the selected theme to the authenticated user's profile
 * and dispatches a browser event for Alpine.js to apply.
 * Guests use localStorage-managed preferences.
 */
class ThemeSwitcher extends Component
{
    public string $theme = 'system';

    /**
     * Initialize the theme from the authenticated user's preference.
     */
    public function mount(): void
    {
        if (auth()->guard('admin')->check()) {
            $this->theme = auth()->guard('admin')->user()->theme ?? 'system';
            // Sync the Alpine theme state with the authoritative DB value.
            // This corrects Alpine after wire:navigate where <html> may have been reset.
            $this->dispatch('theme-changed', theme: $this->theme);
        } elseif (auth()->check()) {
            $this->theme = auth()->user()->theme ?? 'system';
            $this->dispatch('theme-changed', theme: $this->theme);
        }
        // For guests, defer entirely to Alpine's localStorage-managed theme.
        // Dispatching here would overwrite the guest's unsaved preference with 'system'.
    }

    /**
     * Update the theme and persist it to the user's profile.
     */
    public function setTheme(string $theme): void
    {
        $this->theme = $theme;

        if (auth()->guard('admin')->check()) {
            auth()->guard('admin')->user()->update(['theme' => $theme]);
        } elseif (auth()->check()) {
            auth()->user()->update(['theme' => $theme]);
        }

        $this->dispatch('theme-changed', theme: $theme);
    }

    /**
     * Render the theme switcher component.
     */
    public function render()
    {
        return view('livewire.layout.theme-switcher');
    }
}
