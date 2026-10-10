{{-- Modal: Observed Traffic Activity (Non-Blocking Mode) --}}
@if ($showObserveDrawer)
    <div class="modal modal-open" role="dialog" wire:keydown.escape.window="closeObserveDrawer">
        <div class="modal-box max-w-5xl h-[88vh] max-h-[92vh] flex flex-col p-5 space-y-3">
            {{-- Header --}}
            <div class="flex items-start justify-between border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_observe_drawer_title') }}</h3>
                        <span class="badge badge-warning badge-sm font-semibold">
                            {{ __('admin.security_toggle_observe') }}
                        </span>
                    </div>
                    <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_observe_drawer_subtitle') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="badge badge-success/15 border-success/30 text-success badge-xs gap-1 py-2 px-2.5 font-mono text-[10px]">
                        <span class="w-1.5 h-1.5 rounded-full bg-success animate-pulse"></span>
                        <span>LIVE ECHO</span>
                    </span>
                    <button wire:click="closeObserveDrawer" type="button" class="btn btn-ghost btn-sm btn-circle" aria-label="{{ __('client.close') }}">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>
            </div>

            {{-- Compact Metrics Summary Bar --}}
            <div class="p-2.5 bg-base-200/50 rounded-box border border-base-200 flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="badge badge-warning badge-sm font-semibold font-mono">
                        {{ number_format($observeMetrics['total_packets']) }}
                    </span>
                    <span class="text-xs font-semibold text-base-content/80">
                        {{ __('admin.security_observe_drawer_total_drops') }}
                    </span>
                    <span class="text-[11px] font-mono text-base-content/60">
                        ({{ $observeMetrics['total_bytes'] > 0 ? number_format($observeMetrics['total_bytes']).' B' : '0 B' }})
                    </span>
                </div>

                {{-- Stage Breakdown Badges --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ($observeMetrics['stages'] as $stage)
                        <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md border text-[11px] {{ $stage['packets'] > 0 ? 'bg-warning/15 border-warning/40 text-warning-content font-medium' : 'bg-base-100 border-base-200 text-base-content/50' }}">
                            <span>{{ $stage['label'] }}:</span>
                            <span class="font-mono font-bold {{ $stage['packets'] > 0 ? 'text-warning-content' : 'text-base-content/70' }}">{{ number_format($stage['packets']) }}</span>
                            @if ($stage['stage'] === 'tftp' && isset($stage['details']) && $stage['packets'] > 0)
                                <span class="text-[10px] opacity-75">(T:{{ $stage['details']['traversal'] }} · F:{{ $stage['details']['flood'] }})</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Recent Kernel Events Table --}}
            <div class="space-y-1.5 flex-1 min-h-0 flex flex-col">
                <div class="flex items-center justify-between px-0.5">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider text-base-content/70">
                            {{ __('admin.security_observe_drawer_recent_events') }}
                        </span>
                        <span class="badge badge-ghost badge-xs font-mono">{{ count($observeEvents) }}</span>
                    </div>
                    <span class="text-[11px] text-base-content/50">Journal entries (newest first)</span>
                </div>

                <div class="overflow-x-auto overflow-y-auto flex-1 min-h-[350px] border border-base-200 rounded-box bg-base-100">
                    <table class="table table-xs table-pin-rows">
                        <thead>
                            <tr class="bg-base-200/80 text-base-content/70 sticky top-0">
                                <th>Timestamp</th>
                                <th>Rule Stage</th>
                                <th>Interface</th>
                                <th>Source IP</th>
                                <th>Destination</th>
                                <th>Proto</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($observeEvents as $event)
                                <tr class="hover font-mono text-xs">
                                    <td class="text-base-content/60 whitespace-nowrap">{{ $event['timestamp'] }}</td>
                                    <td>
                                        <span class="badge badge-warning badge-xs font-sans font-medium">{{ $event['stage_label'] }}</span>
                                    </td>
                                    <td class="text-base-content/70">{{ $event['interface'] ?: '—' }}</td>
                                    <td class="font-semibold text-warning-content">{{ $event['src_ip'] }}</td>
                                    <td class="text-base-content/70">{{ $event['dst_ip'] }}{{ $event['dpt'] ? ':'.$event['dpt'] : '' }}</td>
                                    <td><span class="badge badge-ghost badge-xs">{{ $event['proto'] }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-12 text-base-content/50 text-xs font-sans">
                                        {{ __('admin.security_observe_drawer_no_events') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- CLI streaming command snippet footer --}}
            <div class="pt-2 border-t border-base-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 text-base-content/70">
                    <span>{{ __('admin.security_observe_drawer_cli_tip') }}</span>
                    <code class="px-2 py-0.5 rounded bg-base-200 text-xs font-mono select-all">php artisan security:observe --follow</code>
                </div>
                <button wire:click="closeObserveDrawer" type="button" class="btn btn-outline btn-sm">
                    {{ __('client.close') }}
                </button>
            </div>
        </div>
        <div class="modal-backdrop" wire:click="closeObserveDrawer"></div>
    </div>
@endif
