<?php

declare(strict_types=1);

namespace Modules\Tenant\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tenant dashboard full-page component.
 *
 * Delegates core stats rendering to the dashboard.stats
 * Livewire component.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): mixed
    {
        return view('tenant::dashboard');
    }
}
