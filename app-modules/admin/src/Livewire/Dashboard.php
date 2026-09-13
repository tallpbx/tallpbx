<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin dashboard full-page component.
 *
 * Delegates core stats rendering to the dashboard.stats
 * Livewire component registered in AppServiceProvider.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): mixed
    {
        return view('admin::dashboard');
    }
}
