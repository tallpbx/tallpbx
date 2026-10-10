{{-- Lockout Warning Banner (if unprotected under drop policy) --}}
@if (! $isCurrentIpWhitelisted && $firewallDefaultPolicy === 'drop')
    <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning flex-shrink-0" />
            <div>
                <div class="font-semibold">{{ __('admin.security_lockout_warning_title') }}</div>
                <div class="text-xs opacity-90">{{ __('admin.security_lockout_warning_body', ['ip' => $adminIp]) }}</div>
            </div>
        </div>
        <button wire:click="whitelistCurrentIp"
                wire:loading.attr="disabled"
                wire:target="whitelistCurrentIp"
                type="button"
                class="btn btn-warning btn-sm whitespace-nowrap">
            <span wire:loading.remove wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                <span>{{ __('admin.security_protect_my_ip') }}</span>
            </span>
            <span wire:loading wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1.5">
                <span class="loading loading-spinner loading-xs"></span>
                <span>{{ __('admin.security_protecting_my_ip') }}</span>
            </span>
        </button>
    </div>
@endif

{{-- Firewall Drift Banner (the live kernel policy differs from the expected policy or ruleset content has drifted) --}}
@if (($liveFirewallPolicy !== null && $liveFirewallPolicy !== $expectedFirewallPolicy) || $firewallSyncState === 'drift')
    <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-arrow-path class="w-6 h-6 text-warning flex-shrink-0" />
            <div>
                <div class="font-semibold">{{ __('admin.security_drift_warning_title') }}</div>
                <div class="text-xs opacity-90">
                    @if ($liveFirewallPolicy === 'absent')
                        {{ __('admin.security_drift_not_loaded_body') }}
                    @elseif ($liveFirewallPolicy !== null && $liveFirewallPolicy !== $expectedFirewallPolicy)
                        {{ __('admin.security_drift_warning_body', [
                            'live' => $liveFirewallPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept'),
                            'saved' => $expectedFirewallPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept'),
                        ]) }}
                    @else
                        {{ __('admin.security_drift_content_body') }}
                    @endif
                </div>
            </div>
        </div>
        <button wire:click="applyFirewallChanges"
                wire:loading.attr="disabled"
                wire:target="applyFirewallChanges"
                type="button"
                class="btn btn-warning btn-sm whitespace-nowrap">
            <span wire:loading.remove wire:target="applyFirewallChanges" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                <span>{{ __('admin.security_drift_reapply') }}</span>
            </span>
            <span wire:loading wire:target="applyFirewallChanges" class="inline-flex items-center gap-1.5">
                <span class="loading loading-spinner loading-xs"></span>
                <span>{{ __('admin.security_drift_reapplying') }}</span>
            </span>
        </button>
    </div>
@endif

{{-- Whole-Firewall-Off Banner (unmissable, shown on every tab while off) --}}
@if (! $firewallEnabled)
    <div class="alert alert-error shadow-sm border border-error/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-fire class="w-6 h-6 text-error flex-shrink-0" />
            <div>
                <div class="font-semibold">{{ __('admin.security_firewall_off_banner_title') }}</div>
                <div class="text-xs opacity-90">{{ __('admin.security_firewall_off_banner_body') }}</div>
            </div>
        </div>
        <button wire:click="setFirewallEnabled(true)"
                wire:loading.attr="disabled"
                wire:target="setFirewallEnabled"
                type="button"
                class="btn btn-error btn-sm whitespace-nowrap">
            <span wire:loading.remove wire:target="setFirewallEnabled" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                <span>{{ __('admin.security_firewall_off_banner_action') }}</span>
            </span>
            <span wire:loading wire:target="setFirewallEnabled" class="inline-flex items-center gap-1.5">
                <span class="loading loading-spinner loading-xs"></span>
                <span>{{ __('admin.security_firewall_turning_on') }}</span>
            </span>
        </button>
    </div>
@endif

{{-- Observe-Mode Banner (evaluating and recording, nothing is blocked) --}}
@if ($firewallObserveMode)
    <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-eye class="w-6 h-6 text-warning-content flex-shrink-0" />
            <div>
                <div class="flex items-center gap-2">
                    <div class="font-semibold">{{ __('admin.security_observe_banner_title') }}</div>
                    @if (($observeMetrics['total_packets'] ?? 0) > 0)
                        <span class="badge badge-warning badge-sm font-mono font-semibold">
                            {{ number_format($observeMetrics['total_packets']) }} would-be drops
                        </span>
                    @endif
                </div>
                <div class="text-xs opacity-90">{{ __('admin.security_observe_banner_body') }}</div>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button wire:click="openObserveDrawer"
                    wire:loading.attr="disabled"
                    wire:target="setObserveMode"
                    type="button"
                    class="btn btn-outline border-warning-content/40 hover:bg-warning-content hover:text-warning text-warning-content btn-sm whitespace-nowrap gap-1">
                <x-heroicon-o-list-bullet class="w-4 h-4" />
                <span>{{ __('admin.security_observe_banner_view_activity') }}</span>
            </button>
            <button wire:click="setObserveMode(false)"
                    wire:loading.attr="disabled"
                    wire:target="setObserveMode"
                    type="button"
                    class="btn btn-warning btn-sm whitespace-nowrap">
                <span wire:loading.remove wire:target="setObserveMode" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-shield-check class="w-4 h-4" />
                    <span>{{ __('admin.security_observe_banner_action') }}</span>
                </span>
                <span wire:loading wire:target="setObserveMode" class="inline-flex items-center gap-1.5">
                    <span class="loading loading-spinner loading-xs"></span>
                    <span>{{ __('admin.security_observe_disabling') }}</span>
                </span>
            </button>
        </div>
    </div>
@endif

{{-- Defensive Pre-Filters Disabled Banner (shown on every tab while firewall is on but pre-filters are off) --}}
@if ($firewallEnabled && ! $prefilterEnabled)
    <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning flex-shrink-0" />
            <div>
                <div class="font-semibold">{{ __('admin.security_prefilter_disabled_banner_title') }}</div>
                <div class="text-xs opacity-90">{{ __('admin.security_prefilter_disabled_banner_body') }}</div>
            </div>
        </div>
        <button wire:click="setPrefilterEnabled(true)"
                wire:loading.attr="disabled"
                wire:target="setPrefilterEnabled"
                type="button"
                class="btn btn-warning btn-sm whitespace-nowrap">
            <span wire:loading.remove wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                <span>{{ __('admin.security_prefilter_enable_action') }}</span>
            </span>
            <span wire:loading wire:target="setPrefilterEnabled" class="inline-flex items-center gap-1.5">
                <span class="loading loading-spinner loading-xs"></span>
                <span>{{ __('admin.security_prefilter_enabling') }}</span>
            </span>
        </button>
    </div>
@endif
