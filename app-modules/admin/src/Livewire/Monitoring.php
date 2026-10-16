<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public bool $showQueueModal = false;

    public array $recentFailures = [];

    public ?string $retryResult = null;

    public ?string $actionError = null;

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

        if ($this->showQueueModal) {
            $this->loadRecentFailures();
        }
    }

    /**
     * Open the queue modal and load recent failed jobs.
     */
    public function openQueueModal(): void
    {
        $this->showQueueModal = true;
        $this->retryResult = null;
        $this->actionError = null;
        $this->loadRecentFailures();
    }

    /**
     * Close the queue modal.
     */
    public function closeQueueModal(): void
    {
        $this->showQueueModal = false;
        $this->retryResult = null;
        $this->actionError = null;
    }

    /**
     * Load the most recent failed jobs from the database.
     */
    public function loadRecentFailures(): void
    {
        $this->pendingJobs = (int) DB::table('jobs')->count();
        $this->failedJobs = (int) DB::table('failed_jobs')->count();

        $this->recentFailures = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'payload' => $this->extractJobName((string) $job->payload),
                'exception' => $this->extractExceptionLine((string) $job->exception),
                'failed_at' => (string) $job->failed_at,
            ])
            ->toArray();
    }

    /**
     * Retry a specific failed job by its UUID.
     */
    public function retry(string $id): void
    {
        $this->retryResult = null;
        $this->actionError = null;

        if (! $this->authorizeQueueAction()) {
            $this->actionError = 'You do not have permission to retry failed jobs.';

            return;
        }

        if (! Str::isUuid($id) && ! ctype_digit($id)) {
            $this->actionError = 'The job id is not valid.';

            return;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        $output = trim(Artisan::output());

        if (str_contains($output, 'Unable to find failed job')) {
            $this->actionError = $output;

            return;
        }

        $this->retryResult = "Job {$id} pushed back to the queue.";
        $this->loadRecentFailures();
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): void
    {
        $this->retryResult = null;
        $this->actionError = null;

        if (! $this->authorizeQueueAction()) {
            $this->actionError = 'You do not have permission to retry failed jobs.';

            return;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->retryResult = 'All failed jobs pushed back to the queue.';
        $this->loadRecentFailures();
    }

    /**
     * Only admins holding the queue view permission may retry jobs.
     */
    private function authorizeQueueAction(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin !== null && $admin->hasPermission('admin.queue.view');
    }

    /**
     * Extract a human-readable job name from the payload JSON.
     */
    private function extractJobName(string $payload): string
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return 'Unknown job';
        }

        if (isset($data['displayName'])) {
            return (string) $data['displayName'];
        }

        $command = $data['data']['command'] ?? '';

        try {
            $obj = unserialize($command);

            return get_class($obj);
        } catch (\Throwable) {
            if (preg_match('/O:\d+:"([^"]+)"/', $command, $m)) {
                return $m[1];
            }

            return 'Unknown job';
        }
    }

    /**
     * Extract the first meaningful line from the exception stack trace.
     */
    private function extractExceptionLine(string $exception): string
    {
        $lines = explode("\n", $exception);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                return mb_substr($line, 0, 200);
            }
        }

        return 'Unknown error';
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
