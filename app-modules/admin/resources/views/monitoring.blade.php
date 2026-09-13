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
            <h4 class="text-sm font-medium text-base-content/60 mb-2">{{ __('admin.monitoring_queues') }}</h4>
            <div class="flex gap-4">
                <div>
                    <div class="text-2xl font-bold">{{ $pendingJobs }}</div>
                    <div class="text-xs text-base-content/60">Pending</div>
                </div>
                <div>
                    <div class="text-2xl font-bold {{ $failedJobs > 0 ? 'text-error' : '' }}">{{ $failedJobs }}</div>
                    <div class="text-xs text-base-content/60">Failed</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ $backupCount }}</div>
                    <div class="text-xs text-base-content/60">Backups</div>
                </div>
            </div>
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
</div>
