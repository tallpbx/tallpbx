<div class="max-w-5xl">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold">{{ __('admin.monitoring') }}</h2>
            <p class="text-sm text-base-content/60 mt-1">
                Real-time system health overview — disk and memory usage, essential service status,
                FreeSWITCH telephony engine runtime, queue worker backlog, and TLS certificate expiry.
                Click Refresh to update all metrics from the live system.
            </p>
        </div>
        <button wire:click="refresh" class="btn btn-ghost btn-sm">
            {{ __('admin.refresh') }}
        </button>
    </div>

    {{-- Service Status Row --}}
    <h3 class="text-lg font-medium mb-3" title="Essential system services checked via systemctl. Red means the service is stopped or crashed — investigate immediately.">{{ __('admin.monitoring_services') }}</h3>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        @foreach ($services as $svc)
            <div class="card bg-base-100 border text-center p-3
                {{ $svc['running'] ? 'border-success/30' : 'border-error/30' }}">
                <div class="text-sm font-medium">{{ $svc['name'] }}</div>
                <div class="text-xs mt-1 {{ $svc['running'] ? 'text-success' : 'text-error' }}">
                    {{ $svc['status_text'] }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- System Metrics Row --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        {{-- Disk --}}
        <div class="card bg-base-100 border border-base-300 p-4" title="Root filesystem disk usage from df. Red bar at >90% means the disk is nearly full — free up space to avoid service disruption.">
            <h4 class="text-sm font-medium text-base-content/60 mb-2">{{ __('admin.monitoring_disk') }}</h4>
            <div class="text-2xl font-bold">{{ $disk['percent_used'] }}%</div>
            <div class="text-xs text-base-content/60 mt-1">
                {{ $disk['used_gb'] }} / {{ $disk['total_gb'] }} GB used
            </div>
            <progress class="progress w-full mt-2 {{ $disk['percent_used'] > 90 ? 'progress-error' : ($disk['percent_used'] > 70 ? 'progress-warning' : 'progress-success') }}"
                value="{{ $disk['percent_used'] }}" max="100"></progress>
        </div>

        {{-- Memory --}}
        <div class="card bg-base-100 border border-base-300 p-4" title="System memory from /proc/meminfo. Red bar at >90% means the server is low on RAM — consider reducing worker processes or adding swap.">
            <h4 class="text-sm font-medium text-base-content/60 mb-2">{{ __('admin.monitoring_memory') }}</h4>
            <div class="text-2xl font-bold">{{ $memory['percent_used'] }}%</div>
            <div class="text-xs text-base-content/60 mt-1">
                {{ $memory['used_gb'] }} / {{ $memory['total_gb'] }} GB used
            </div>
            <progress class="progress w-full mt-2 {{ $memory['percent_used'] > 90 ? 'progress-error' : ($memory['percent_used'] > 70 ? 'progress-warning' : 'progress-success') }}"
                value="{{ $memory['percent_used'] }}" max="100"></progress>
        </div>

        {{-- FreeSWITCH --}}
        <div class="card bg-base-100 border {{ $freeswitch['running'] ? 'border-success/30' : 'border-error/30' }} p-4" title="FreeSWITCH status from fs_cli. Uptime shows how long since last restart. Sessions counts active calls. CPS is the configured max calls-per-second.">
            <h4 class="text-sm font-medium text-base-content/60 mb-2">{{ __('admin.monitoring_freeswitch') }}</h4>
            @if ($freeswitch['running'])
                <div class="text-xs space-y-1">
                    <div><span class="text-base-content/60">Uptime:</span> {{ $freeswitch['uptime'] }}</div>
                    <div><span class="text-base-content/60">Sessions:</span> {{ $freeswitch['sessions'] }}</div>
                    <div><span class="text-base-content/60">CPS:</span> {{ $freeswitch['calls_per_second'] }}</div>
                </div>
            @else
                <div class="text-error text-sm">Not running</div>
            @endif
        </div>
    </div>

    {{-- Queue & Backup Row --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="card bg-base-100 border border-base-300 p-4" title="Database queue worker stats. Pending jobs are waiting to run. Failed jobs have exhausted retries — investigate on the Queue Status page.">
            <div class="flex items-center justify-between mb-2">
                <h4 class="text-sm font-medium text-base-content/60">{{ __('admin.monitoring_queues') }}</h4>
                <button type="button" wire:click="openQueueModal" class="btn btn-ghost btn-xs gap-1 text-primary hover:underline">
                    {{ __('admin.queue_status') }}
                    <x-heroicon-o-arrow-top-right-on-square class="w-3.5 h-3.5" />
                </button>
            </div>
            <div class="flex gap-6 items-baseline">
                <div>
                    <div class="text-2xl font-bold">{{ $pendingJobs }}</div>
                    <div class="text-xs text-base-content/60">{{ __('admin.queue_pending') }}</div>
                </div>
                <div>
                    <div class="text-2xl font-bold {{ $failedJobs > 0 ? 'text-error' : '' }}">{{ $failedJobs }}</div>
                    <div class="text-xs text-base-content/60">{{ __('admin.queue_failed') }}</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ $backupCount }}</div>
                    <div class="text-xs text-base-content/60">Backups</div>
                </div>
            </div>
            @if ($failedJobs > 0)
                <div class="mt-3 pt-3 border-t border-base-200 flex items-center justify-between">
                    <span class="text-xs text-error font-medium">{{ $failedJobs }} failed {{ $failedJobs === 1 ? 'job' : 'jobs' }} requiring attention</span>
                    <button type="button" wire:click="openQueueModal" class="btn btn-outline btn-error btn-xs">
                        {{ __('admin.queue_retry_all') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- Certificate --}}
        <div class="card bg-base-100 border border-base-300 p-4" title="TLS certificate expiry for the primary domain. Checked via OpenSSL or the local certificate file. Red means the cert expires in less than 30 days — renew immediately.">
            <h4 class="text-sm font-medium text-base-content/60 mb-2">{{ __('admin.monitoring_certificate') }}</h4>
            @if ($certificate)
                <div class="text-xs space-y-1">
                    <div><span class="text-base-content/60">Domain:</span> {{ $certificate['domain'] }}</div>
                    <div><span class="text-base-content/60">Issuer:</span> {{ $certificate['issuer'] }}</div>
                    <div>
                        <span class="text-base-content/60">Expires:</span>
                        <span class="{{ $certificate['days_remaining'] < 30 ? 'text-error font-medium' : ($certificate['days_remaining'] < 60 ? 'text-warning' : '') }}">
                            {{ $certificate['days_remaining'] }} days
                        </span>
                    </div>
                </div>
            @else
                <div class="text-xs text-base-content/60">No TLS certificate detected</div>
            @endif
        </div>
    </div>

    {{-- Interactive Queue Status Modal --}}
    @if ($showQueueModal)
        <div class="modal modal-open z-50">
            <div class="modal-box max-w-4xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-queue-list class="w-6 h-6 text-primary" />
                        <h3 class="font-bold text-lg text-base-content">{{ __('admin.queue_status') }}</h3>
                    </div>
                    <button type="button" wire:click="closeQueueModal" class="btn btn-ghost btn-sm btn-circle" aria-label="{{ __('admin.modal_close') }}">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>

                {{-- Stats cards inside modal --}}
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="card bg-base-200 p-3 text-center">
                        <div class="text-2xl font-bold">{{ $pendingJobs }}</div>
                        <div class="text-xs text-base-content/60">{{ __('admin.queue_pending') }}</div>
                    </div>
                    <div class="card bg-base-200 p-3 text-center">
                        <div class="text-2xl font-bold {{ $failedJobs > 0 ? 'text-error' : '' }}">{{ $failedJobs }}</div>
                        <div class="text-xs text-base-content/60">{{ __('admin.queue_failed') }}</div>
                    </div>
                    <div class="card bg-base-200 p-3 text-center">
                        <div class="text-2xl font-bold">{{ $pendingJobs + $failedJobs }}</div>
                        <div class="text-xs text-base-content/60">{{ __('admin.queue_total') }}</div>
                    </div>
                </div>

                {{-- Actions and alerts --}}
                <div class="flex items-center justify-between mb-3">
                    <button type="button" wire:click="loadRecentFailures" class="btn btn-ghost btn-xs gap-1">
                        <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                        {{ __('admin.refresh') }}
                    </button>
                    @if ($failedJobs > 0)
                        <button type="button" wire:click="retryAll" class="btn btn-outline btn-error btn-xs">
                            {{ __('admin.queue_retry_all') }}
                        </button>
                    @endif
                </div>

                @if ($retryResult)
                    <div class="alert alert-success text-xs py-2 mb-3">
                        <x-heroicon-o-check-circle class="w-4 h-4" />
                        <span>{{ $retryResult }}</span>
                    </div>
                @endif
                @if ($actionError)
                    <div class="alert alert-error text-xs py-2 mb-3">
                        <x-heroicon-o-exclamation-circle class="w-4 h-4" />
                        <span>{{ $actionError }}</span>
                    </div>
                @endif

                {{-- Failed jobs table --}}
                @if (count($recentFailures) > 0)
                    <div class="overflow-x-auto max-h-80 border border-base-300 rounded-box">
                        <table class="table table-zebra table-xs w-full">
                            <thead>
                                <tr>
                                    <th>{{ __('admin.queue_job') }}</th>
                                    <th>{{ __('admin.queue_failed_at') }}</th>
                                    <th class="text-right">{{ __('client.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentFailures as $job)
                                    <tr>
                                        <td class="max-w-md">
                                            <div class="font-medium font-mono text-xs text-primary">{{ class_basename($job['payload']) }}</div>
                                            <div class="text-xs text-base-content/70 truncate mt-0.5" title="{{ $job['exception'] }}">
                                                {{ $job['exception'] }}
                                            </div>
                                        </td>
                                        <td class="text-xs whitespace-nowrap text-base-content/60">{{ $job['failed_at'] }}</td>
                                        <td class="text-right">
                                            <button type="button" wire:click="retry('{{ $job['uuid'] }}')" class="btn btn-ghost btn-xs text-primary">
                                                {{ __('admin.retry') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card bg-base-200 p-6 text-center text-sm text-base-content/60">
                        {{ __('admin.queue_no_failures') }}
                    </div>
                @endif

                <div class="modal-action mt-4">
                    <button type="button" wire:click="closeQueueModal" class="btn btn-sm">
                        {{ __('admin.modal_close') }}
                    </button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeQueueModal"></div>
        </div>
    @endif
</div>
