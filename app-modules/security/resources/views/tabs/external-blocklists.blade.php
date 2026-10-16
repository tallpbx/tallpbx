@php
    // Derive the single status badge the panel shows: disabled beats
    // failed beats stale beats never-synced beats active. The badge
    // temporarily reads "Syncing" (client-side, via wire:loading)
    // while the Sync Now request is in flight.
    $feedStatus = match (true) {
        $threatFeed === null => 'idle',
        ! $threatFeed->enabled => 'disabled',
        $threatFeed->last_status === 'failed' => 'error',
        $threatFeed->isStale() => 'stale',
        $threatFeed->last_sync_at === null => 'idle',
        default => 'active',
    };
    $feedBadgeClass = match ($feedStatus) {
        'active' => 'badge-success',
        'error' => 'badge-error',
        'stale' => 'badge-warning',
        default => 'badge-neutral',
    };
@endphp
<div class="space-y-6">
    @if (! $prefilterEnabled)
        <div class="alert alert-warning/15 border border-warning/30 text-base-content rounded-box flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5" role="alert">
            <div class="flex items-center gap-2.5">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning shrink-0" />
                <div class="text-xs">
                    <span class="font-semibold text-warning">{{ __('admin.security_prefilter_bypassed_notice_title') }}:</span>
                    <span class="opacity-90">{{ __('admin.security_prefilter_bypassed_threat_feeds_notice') }}</span>
                </div>
            </div>
            <button wire:click="setPrefilterEnabled(true)"
                    wire:loading.attr="disabled"
                    wire:target="setPrefilterEnabled"
                    type="button"
                    class="btn btn-warning btn-xs shrink-0 whitespace-nowrap self-start sm:self-auto">
                <span wire:loading.remove wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1">
                    <x-heroicon-o-shield-check class="w-3.5 h-3.5" />
                    <span>{{ __('admin.security_prefilter_enable_action') }}</span>
                </span>
                <span wire:loading wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1">
                    <span class="loading loading-spinner loading-xs"></span>
                    <span>{{ __('admin.security_prefilter_enabling') }}</span>
                </span>
            </button>
        </div>
    @endif
    <div class="card bg-base-100 shadow-sm border {{ ! $prefilterEnabled ? 'border-warning/30' : 'border-base-200' }}">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_threat_feeds_title') }}</h2>
                        <span class="badge {{ $feedBadgeClass }} badge-sm font-semibold" wire:loading.remove wire:target="syncThreatFeedNow">{{ __('admin.security_threat_feed_status_'.$feedStatus) }}</span>
                        <span class="badge badge-info badge-sm font-semibold" wire:loading wire:target="syncThreatFeedNow">{{ __('admin.security_threat_feed_status_syncing') }}</span>
                        @if ($feedEnabled)
                            <span class="badge badge-neutral badge-sm font-mono">{{ number_format($threatFeed?->entries_count ?? 0) }}</span>
                        @else
                            <span class="badge badge-neutral badge-sm font-mono">0</span>
                            @if (($threatFeed?->entries_count ?? 0) > 0)
                                <span class="text-xs text-base-content/60">({{ __('admin.security_in_list_note', ['count' => number_format($threatFeed->entries_count)]) }})</span>
                            @endif
                        @endif
                        <span class="badge badge-ghost badge-sm font-mono text-[11px] opacity-75" title="@external_blocklist_ips">@external_blocklist_ips</span>
                        @if (! $prefilterEnabled)
                            <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                        @endif
                        <x-tooltip :tip="__('admin.security_threat_feeds_tooltip')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <p class="text-xs text-base-content/60 mt-1 flex items-center gap-1.5 flex-wrap">
                        <span>{{ __('admin.security_threat_feed_cli_hint') }}</span>
                        <code class="px-1.5 py-0.5 rounded bg-base-200 text-xs font-mono select-all text-base-content">sudo nft list set inet tallpbx_filter external_blocklist_ips</code>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
                    <label class="label cursor-pointer justify-start gap-2 p-0 mr-2"
                           wire:loading.class="opacity-70 pointer-events-none"
                           wire:target="setFeedEnabled">
                        <input wire:click="setFeedEnabled({{ $feedEnabled ? 'false' : 'true' }})"
                               wire:loading.attr="disabled"
                               wire:target="setFeedEnabled"
                               type="checkbox" class="toggle toggle-error toggle-sm" @checked($feedEnabled) />
                        <span wire:loading.remove wire:target="setFeedEnabled" class="label-text font-medium text-xs sm:text-sm">
                            {{ $feedEnabled ? __('client.disable') : __('client.enable') }}
                        </span>
                        <span wire:loading wire:target="setFeedEnabled" class="inline-flex items-center gap-1.5 label-text font-medium text-xs sm:text-sm text-error">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ $feedEnabled ? __('admin.security_threat_feed_disabling') : __('admin.security_threat_feed_enabling') }}</span>
                        </span>
                    </label>
                    <button wire:click="syncThreatFeedNow"
                            wire:loading.attr="disabled"
                            wire:target="syncThreatFeedNow"
                            type="button" class="btn btn-outline btn-sm gap-1">
                        <span wire:loading.remove wire:target="syncThreatFeedNow" class="inline-flex items-center gap-1">
                            <x-heroicon-o-arrow-path class="w-4 h-4" />
                            <span>{{ __('admin.security_threat_feed_sync_now') }}</span>
                        </span>
                        <span wire:loading wire:target="syncThreatFeedNow" class="inline-flex items-center gap-1">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.security_threat_feed_syncing') }}</span>
                        </span>
                    </button>
                    <button wire:click="removeAllFeedBlocks"
                            wire:loading.attr="disabled"
                            wire:target="removeAllFeedBlocks"
                            type="button"
                            wire:confirm="{{ __('admin.security_threat_feed_remove_blocks_confirm') }}"
                            class="btn btn-outline btn-error btn-sm gap-1">
                        <span wire:loading.remove wire:target="removeAllFeedBlocks" class="inline-flex items-center gap-1">
                            <x-heroicon-o-trash class="w-4 h-4" />
                            <span>{{ __('admin.security_threat_feed_remove_blocks') }}</span>
                        </span>
                        <span wire:loading wire:target="removeAllFeedBlocks" class="inline-flex items-center gap-1">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.security_threat_feed_removing_blocks') }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                {{-- Left: feed configuration & information --}}
                <div class="space-y-4">
                    <div class="space-y-2 text-xs text-base-content/70 bg-base-200/40 p-3.5 rounded-box border border-base-200">
                        <p>{{ __('admin.security_threat_feed_description') }}</p>
                        <p class="text-base-content/60">{{ __('admin.security_threat_feed_whitelist_hint') }}</p>
                        
                        <div class="flex flex-wrap items-center gap-3 pt-1 border-t border-base-200/60 mt-2">
                            <a href="https://www.voipbl.org/check/" target="_blank" rel="noopener noreferrer"
                               class="link link-primary inline-flex items-center gap-1 font-medium">
                                <span>{{ __('admin.security_threat_feed_search_url_label') }}</span>
                                <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5" />
                            </a>
                            <span class="text-base-content/30">•</span>
                            <a href="https://www.voipbl.org/update/" target="_blank" rel="noopener noreferrer"
                               class="link link-hover inline-flex items-center gap-1 font-medium text-base-content/70">
                                <span>{{ __('admin.security_threat_feed_raw_url_label') }}</span>
                                <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5" />
                            </a>
                        </div>
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.security_threat_feed_interval') }}</span>
                        </label>
                        <select wire:model="feedSyncInterval" class="select select-bordered select-sm w-full">
                            <option value="hourly">{{ __('admin.security_threat_feed_every_hour') }}</option>
                            <option value="4_hours">{{ __('admin.security_threat_feed_every_4h') }}</option>
                            <option value="12_hours">{{ __('admin.security_threat_feed_every_12h') }}</option>
                            <option value="daily">{{ __('admin.security_threat_feed_daily') }}</option>
                        </select>
                    </div>

                    <p class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_ipv4_note') }}</p>

                    <button wire:click="saveFeedSettings"
                            wire:loading.attr="disabled"
                            wire:target="saveFeedSettings"
                            type="button" class="btn btn-primary btn-sm">
                        <span wire:loading.remove wire:target="saveFeedSettings">
                            {{ __('client.save') }}
                        </span>
                        <span wire:loading wire:target="saveFeedSettings" class="inline-flex items-center gap-1.5">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.saving') }}</span>
                        </span>
                    </button>
                </div>

            {{-- Right: live metrics --}}
            <div class="grid grid-cols-2 gap-3">
                <div class="p-3 bg-base-200/50 rounded-box border border-base-200">
                    <div class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_metric_entries') }}</div>
                    @if ($feedEnabled)
                        <div class="text-lg font-semibold font-mono text-base-content">{{ number_format($threatFeed?->entries_count ?? 0) }}</div>
                    @else
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg font-semibold font-mono text-base-content/60">0</span>
                            <span class="badge badge-neutral badge-xs opacity-75">{{ __('admin.security_threat_feed_status_disabled') }}</span>
                        </div>
                        @if (($threatFeed?->entries_count ?? 0) > 0)
                            <div class="text-[11px] text-base-content/60 mt-0.5">
                                {{ __('admin.security_threat_feed_downloaded_count', ['count' => number_format($threatFeed->entries_count)]) }}
                            </div>
                        @endif
                    @endif
                </div>
                <div class="p-3 bg-base-200/50 rounded-box border border-base-200">
                    <div class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_metric_rejected') }}</div>
                    <div class="text-lg font-semibold font-mono text-base-content">{{ $threatFeed?->last_rejected_lines ?? 0 }}</div>
                </div>
                <div class="p-3 bg-base-200/50 rounded-box border border-base-200">
                    <div class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_metric_last_sync') }}</div>
                    <div class="text-sm font-medium text-base-content">
                        {{ $threatFeed?->last_sync_at?->diffForHumans() ?? __('admin.security_threat_feed_metric_never') }}
                    </div>
                </div>
                <div class="p-3 bg-base-200/50 rounded-box border border-base-200">
                    <div class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_metric_dropped') }}</div>
                    <div class="text-lg font-semibold font-mono text-base-content">{{ $feedDropCounter ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
