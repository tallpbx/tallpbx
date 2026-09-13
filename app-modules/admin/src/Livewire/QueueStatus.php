<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Support\OperationalControlFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component displaying queue worker and job statistics.
 *
 * Shows pending job count, failed job count, and the five most
 * recent failed jobs with retry capability. Designed for the
 * admin dashboard so operators can monitor operational health
 * without shell access. Retry actions are re-authorized
 * server-side (admin guard + admin.queue.view).
 */
#[Layout('layouts.app')]
class QueueStatus extends Component
{
    use OperationalControlFeedback;

    public int $pendingJobs = 0;

    public int $failedJobs = 0;

    public array $recentFailures = [];

    public ?string $retryResult = null;

    public function mount(): void
    {
        $this->refresh();
    }

    /**
     * Refresh all queue statistics from the database.
     */
    public function refresh(): void
    {
        $this->pendingJobs = DB::table('jobs')->count();
        $this->failedJobs = DB::table('failed_jobs')->count();

        $this->recentFailures = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                // The database-uuids failer resolves retry ids by uuid, so
                // the retry button must send the uuid, not the row id.
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'payload' => $this->extractJobName($job->payload),
                'exception' => $this->extractExceptionLine($job->exception),
                'failed_at' => $job->failed_at,
            ])
            ->toArray();
    }

    /**
     * Retry a specific failed job by its ID.
     */
    public function retry(string $id): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->authorizeRetry()) {
            $this->actionError = 'You do not have permission to retry failed jobs.';

            return;
        }

        // Only uuid (or numeric, for legacy failer providers) ids are
        // valid; 'all' would requeue every failed job.
        if (! Str::isUuid($id) && ! ctype_digit($id)) {
            $this->actionError = 'The job id is not valid.';

            return;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        // The failer resolves by uuid; an unknown id must not claim success.
        $output = trim(Artisan::output());

        if (str_contains($output, 'Unable to find failed job')) {
            $this->actionError = $output;

            return;
        }

        $this->retryResult = "Job {$id} pushed back to the queue.";
        $this->refresh();
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (! $this->authorizeRetry()) {
            $this->actionError = 'You do not have permission to retry failed jobs.';

            return;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->retryResult = 'All failed jobs pushed back to the queue.';
        $this->refresh();
    }

    /**
     * Only admins holding the queue view permission may retry jobs.
     */
    private function authorizeRetry(): bool
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

        $command = $data['data']['command'] ?? '';

        // Unserialize to get the class name, fall back to the raw name.
        try {
            $obj = unserialize($command);

            return get_class($obj);
        } catch (\Throwable) {
            // If unserialize fails, extract the class name from the raw
            // serialized string using a simple regex.
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

    public function render(): View
    {
        return view('admin::queue-status', [
            'pendingJobs' => $this->pendingJobs,
            'failedJobs' => $this->failedJobs,
            'recentFailures' => $this->recentFailures,
        ]);
    }
}
