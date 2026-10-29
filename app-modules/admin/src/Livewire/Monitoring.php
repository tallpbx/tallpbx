<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Services\SystemHealth;
use App\Support\PanelActor;
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
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        $viewGroup = [['admin.monitoring.view']];

        return [
            '' => $viewGroup,
            'openQueueModal' => $viewGroup,
            'closeQueueModal' => $viewGroup,
            'loadRecentFailures' => $viewGroup,
            'retry' => [['admin.queue.retry']],
            'retryAll' => [['admin.queue.retry']],
            'refresh' => $viewGroup,
        ];
    }

    /**
     * Collect all health metrics on component mount.
     */
    public function mount(SystemHealth $health): void
    {
        $this->loadMetrics($health);
    }

    /**
     * Populate system health and queue counters.
     */
    private function loadMetrics(SystemHealth $health): void
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
     * Open the queue modal and load recent failed jobs.
     */
    public function openQueueModal(): void
    {
        if (! $this->authorizeQueueAction()) {
            abort(403);
        }

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
        if (! $this->authorizeQueueAction()) {
            abort(403);
        }

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

        if (! PanelActor::can('admin.queue.retry')) {
            $this->actionError = (string) __('admin.queue_permission_denied');

            return;
        }

        if (! Str::isUuid($id) && ! ctype_digit($id)) {
            $this->actionError = (string) __('admin.queue_invalid_id');

            return;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        $output = trim(Artisan::output());

        if (str_contains($output, 'Unable to find failed job')) {
            $this->actionError = $output;

            return;
        }

        $this->retryResult = (string) __('admin.queue_retried', ['id' => $id]);
        $this->loadRecentFailures();
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): void
    {
        $this->retryResult = null;
        $this->actionError = null;

        if (! PanelActor::can('admin.queue.retry')) {
            $this->actionError = (string) __('admin.queue_permission_denied');

            return;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->retryResult = (string) __('admin.queue_retried_all');
        $this->loadRecentFailures();
    }

    /**
     * Only admins holding the queue view permission may inspect or retry jobs.
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
            return (string) __('admin.queue_unknown_job');
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

            return (string) __('admin.queue_unknown_job');
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

        return (string) __('admin.queue_unknown_error');
    }

    /**
     * Refresh metrics in response to a user action.
     */
    public function refresh(): void
    {
        $this->loadMetrics(app(SystemHealth::class));

        if ($this->showQueueModal && $this->authorizeQueueAction()) {
            $this->loadRecentFailures();
        }
    }

    /**
     * Render the monitoring dashboard view.
     */
    public function render(): View
    {
        return view('admin::monitoring');
    }
}
