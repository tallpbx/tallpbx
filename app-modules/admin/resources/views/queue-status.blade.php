<div class="max-w-4xl">
    <h2 class="text-2xl font-semibold mb-6">{{ __('admin.queue_status') }}</h2>

    {{-- Stats cards --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-3xl font-bold">{{ $pendingJobs }}</div>
            <div class="text-sm text-base-content/60">{{ __('admin.queue_pending') }}</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-3xl font-bold text-error">{{ $failedJobs }}</div>
            <div class="text-sm text-base-content/60">{{ __('admin.queue_failed') }}</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-3xl font-bold">{{ $pendingJobs + $failedJobs }}</div>
            <div class="text-sm text-base-content/60">{{ __('admin.queue_total') }}</div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-4 mb-4">
        <button wire:click="refresh" class="btn btn-ghost btn-sm">
            <x-heroicon-o-arrow-path class="w-4 h-4" />
            {{ __('admin.refresh') }}
        </button>
        @if ($failedJobs > 0)
            <button wire:click="retryAll" class="btn btn-outline btn-sm">
                {{ __('admin.queue_retry_all') }}
            </button>
        @endif
    </div>

    @if ($retryResult)
        <div class="alert alert-success mb-4">{{ $retryResult }}</div>
    @endif
    @if ($actionMessage)
        <div class="alert alert-success mb-4">{{ $actionMessage }}</div>
    @endif
    @if ($actionError)
        <div class="alert alert-error mb-4">{{ $actionError }}</div>
    @endif

    {{-- Recent failures --}}
    @if (count($recentFailures) > 0)
        <h3 class="text-lg font-semibold mb-3">{{ __('admin.queue_recent_failures') }}</h3>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.queue_job') }}</th>
                        <th>{{ __('admin.queue_failed_at') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentFailures as $job)
                        <tr>
                            <td>
                                <div class="text-sm font-medium">{{ class_basename($job['payload']) }}</div>
                                <div class="text-xs text-base-content/60 mt-1">{{ $job['exception'] }}</div>
                            </td>
                            <td class="text-sm">{{ $job['failed_at'] }}</td>
                            <td>
                                <button wire:click="retry('{{ $job['uuid'] }}')" class="btn btn-ghost btn-xs">
                                    {{ __('admin.retry') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300 p-8 text-center text-base-content/60">
            {{ __('admin.queue_no_failures') }}
        </div>
    @endif
</div>
