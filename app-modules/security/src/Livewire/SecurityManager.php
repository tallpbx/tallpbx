<?php

declare(strict_types=1);

namespace Modules\Security\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]

/**
 * Security Command Center Livewire component.
 *
 * Orchestrates firewall management, trusted and blocked IP lists,
 * and automatic intrusion protection in a single streamlined interface.
 */
class SecurityManager extends Component
{
    /**
     * Active interface tab ('overview', 'firewall', 'ip_lists', 'bans', 'settings').
     */
    public string $activeTab = 'overview';

    /**
     * Render the Security Command Center Blade view.
     */
    public function render(): View
    {
        return view('security::security-manager');
    }
}
