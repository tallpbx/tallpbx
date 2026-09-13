<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Backups\Models\Backup;

/**
 * Full-page monitoring dashboard showing system health metrics.
 *
 * Displays disk, memory, service status, FreeSWITCH runtime stats,
 * certificate expiry, queue worker status, and backup summary.
 * All data comes from read-only system queries — no shell commands
 * accept user input.
 */
#[Layout('layouts.app')]
class Monitoring extends Component
{
    public array $disk = [];

    public array $memory = [];

    public array $services = [];

    public array $freeswitch = [];

    public ?array $certificate = null;

    public int $pendingJobs = 0;

    public int $failedJobs = 0;

    public int $backupCount = 0;

    /**
     * Collect all health metrics on component mount.
     */
    public function mount(SystemHealth $health): void
    {
        $summary = $health->summary();

        $this->disk = $summary['disk'];
        $this->memory = $summary['memory'];
        $this->services = $summary['services'];
        $this->freeswitch = $summary['freeswitch'];
        $this->certificate = $summary['certificate'];

        // Queue stats from database
        $this->pendingJobs = (int) DB::table('jobs')->count();
        $this->failedJobs = (int) DB::table('failed_jobs')->count();

        // Backup count
        if (class_exists(Backup::class)) {
            $this->backupCount = Backup::count();
        }
    }

    /**
     * Refresh metrics in response to a user action.
     */
    public function refresh(): void
    {
        $this->mount(app(SystemHealth::class));
    }

    /**
     * Render the monitoring dashboard view.
     */
    public function render(): View
    {
        return view('admin::monitoring');
    }
}
