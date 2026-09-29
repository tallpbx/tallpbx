<div class="space-y-6">
    {{-- Header & Top Actions --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ __('admin.security_title') }}</h1>
                <x-tooltip :tip="__('admin.security_description')" align="start" position="right">
                    {{-- Info icon marks this as hover tooltip text; size and style match the page-title info icon used across the UI, and the shield stays reserved for the nav menu. --}}
                    <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
                </x-tooltip>
            </div>
        </div>
    </div>

    {{-- Transient feedback toast (shared standard component — see tallpbx-custom skill) --}}
    <x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />

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
            <button wire:click="whitelistCurrentIp" type="button" class="btn btn-warning btn-sm whitespace-nowrap">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                {{ __('admin.security_protect_my_ip') }}
            </button>
        </div>
    @endif

    {{-- Firewall Drift Banner (the live kernel policy differs from the saved policy or ruleset content has drifted) --}}
    @if (($liveFirewallPolicy !== null && $liveFirewallPolicy !== $firewallDefaultPolicy) || $firewallSyncState === 'drift')
        <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-heroicon-o-arrow-path class="w-6 h-6 text-warning flex-shrink-0" />
                <div>
                    <div class="font-semibold">{{ __('admin.security_drift_warning_title') }}</div>
                    <div class="text-xs opacity-90">
                        @if ($liveFirewallPolicy === 'absent')
                            {{ __('admin.security_drift_not_loaded_body') }}
                        @elseif ($liveFirewallPolicy !== null && $liveFirewallPolicy !== $firewallDefaultPolicy)
                            {{ __('admin.security_drift_warning_body', [
                                'live' => $liveFirewallPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept'),
                                'saved' => $firewallDefaultPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept'),
                            ]) }}
                        @else
                            {{ __('admin.security_drift_content_body') }}
                        @endif
                    </div>
                </div>
            </div>
            <button wire:click="applyFirewallChanges" type="button" class="btn btn-warning btn-sm whitespace-nowrap">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                {{ __('admin.security_drift_reapply') }}
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
            <button wire:click="setFirewallEnabled(true)" type="button" class="btn btn-error btn-sm whitespace-nowrap">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                {{ __('admin.security_firewall_off_banner_action') }}
            </button>
        </div>
    @endif

    {{-- Observe-Mode Banner (evaluating and recording, nothing is blocked) --}}
    @if ($firewallObserveMode)
        <div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-heroicon-o-eye class="w-6 h-6 text-warning flex-shrink-0" />
                <div>
                    <div class="font-semibold">{{ __('admin.security_observe_banner_title') }}</div>
                    <div class="text-xs opacity-90">{{ __('admin.security_observe_banner_body') }}</div>
                </div>
            </div>
            <button wire:click="setObserveMode(false)" type="button" class="btn btn-warning btn-sm whitespace-nowrap">
                <x-heroicon-o-shield-check class="w-4 h-4" />
                {{ __('admin.security_observe_banner_action') }}
            </button>
        </div>
    @endif

    {{-- Zone 1: System Status Overview Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Firewall Status --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_firewall_status') }}</span>
                    @if ($firewallEnabled)
                        <span class="badge badge-success badge-sm gap-1">
                            <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                            {{ __('admin.active') }}
                        </span>
                    @else
                        <span class="badge badge-neutral badge-sm">{{ __('admin.security_firewall_disabled') }}</span>
                    @endif
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <div class="text-lg font-semibold text-base-content">nftables</div>
                    @if ($firewallSyncState === 'verified')
                        <span class="badge badge-success badge-xs gap-1" title="{{ $firewallSyncAppliedAt ? __('admin.security_sync_applied_at', ['time' => $firewallSyncAppliedAt]) : '' }}">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-success-content"></span>
                            {{ __('admin.security_sync_verified') }}
                        </span>
                    @elseif ($firewallSyncState === 'drift')
                        <span class="badge badge-warning badge-xs gap-1">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-warning-content"></span>
                            {{ __('admin.security_sync_drift') }}
                        </span>
                    @elseif ($firewallSyncState === 'unknown')
                        <span class="badge badge-ghost badge-xs gap-1 text-base-content/60">
                            {{ __('admin.security_sync_unverified') }}
                        </span>
                    @endif
                </div>
                <div class="flex items-center justify-between text-xs text-base-content/60 mt-0.5">
                    <span>{{ $firewallDefaultPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept') }}</span>
                    @if ($firewallSyncAppliedAt)
                        @php
                            try {
                                $syncDisplayTime = \Illuminate\Support\Carbon::parse($firewallSyncAppliedAt)->diffForHumans();
                            } catch (\Throwable) {
                                $syncDisplayTime = $firewallSyncAppliedAt;
                            }
                        @endphp
                        <span class="text-[10px] opacity-75 font-mono">{{ $syncDisplayTime }}</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Attack Protection --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_attack_protection') }}</span>
                    @if ($attackProtectionEnabled)
                        <span class="badge badge-success badge-sm gap-1">
                            <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                            {{ __('admin.active') }}
                        </span>
                    @else
                        <span class="badge badge-neutral badge-sm">{{ __('admin.security_firewall_disabled') }}</span>
                    @endif
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">
                    {{ implode(', ', array_filter([$protectSip ? 'SIP' : null, $protectWeb ? 'Web' : null, $protectSsh ? 'SSH' : null])) ?: 'None' }}
                </div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_attack_help') }}</p>
            </div>
        </div>

        {{-- Blocked Attackers --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_blocked_attackers') }}</span>
                    <span class="badge badge-neutral badge-sm">{{ $bannedCount }}</span>
                </div>
                <div class="mt-2 text-lg font-semibold text-base-content">{{ $bannedCount }} {{ __('admin.security_active_bans') }}</div>
                <p class="text-xs text-base-content/60">{{ __('admin.security_bans_help') }}</p>
            </div>
        </div>

        {{-- Administrator Connection (Lockout Guard) --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-base-content/70">{{ __('admin.security_your_connection') }}</span>
                    @if ($isCurrentIpWhitelisted)
                        <span class="badge badge-success badge-sm">{{ __('admin.security_protected') }}</span>
                    @else
                        <span class="badge badge-warning badge-sm">{{ __('admin.security_unprotected') }}</span>
                    @endif
                </div>
                <div class="mt-2 text-lg font-semibold font-mono text-base-content">{{ $adminIp }}</div>
                <div class="mt-1">
                    @if ($isCurrentIpWhitelisted)
                        <p class="text-xs text-success">{{ __('admin.security_lockout_help') }}</p>
                    @else
                        <button wire:click="whitelistCurrentIp" type="button" class="btn btn-warning btn-xs gap-1 mt-1">
                            <x-heroicon-o-shield-check class="w-3 h-3" />
                            {{ __('admin.security_protect_my_ip') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Firewall Switches: page-global operational state, visible on every tab --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                {{-- Whole firewall --}}
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:click="setFirewallEnabled({{ $firewallEnabled ? 'false' : 'true' }})"
                           @if ($firewallEnabled) wire:confirm="{{ __('admin.security_firewall_off_confirm') }}" @endif
                           type="checkbox" class="toggle toggle-primary toggle-sm" @checked($firewallEnabled) />
                    <span class="text-sm font-medium">{{ __('admin.security_toggle_firewall') }}</span>
                    <x-tooltip :tip="__('admin.security_toggle_firewall_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>

                {{-- Built-in pre-filter (stages 1–7) --}}
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:click="setPrefilterEnabled({{ $prefilterEnabled ? 'false' : 'true' }})"
                           type="checkbox" class="toggle toggle-primary toggle-sm" @checked($prefilterEnabled) />
                    <span class="text-sm font-medium">{{ __('admin.security_toggle_prefilter') }}</span>
                    <x-tooltip :tip="__('admin.security_toggle_prefilter_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>

                {{-- Global observe mode --}}
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:click="setObserveMode({{ $firewallObserveMode ? 'false' : 'true' }})"
                           type="checkbox" class="toggle toggle-warning toggle-sm" @checked($firewallObserveMode) />
                    <span class="text-sm font-medium">{{ __('admin.security_toggle_observe') }}</span>
                    <x-tooltip :tip="__('admin.security_toggle_observe_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
            </div>

            @if (! $prefilterEnabled)
                <span class="text-xs font-medium text-warning">{{ __('admin.security_prefilter_off_badge') }}</span>
            @endif
        </div>
    </div>

    {{-- Evaluation-Ordered Tabs: left to right mirrors the kernel evaluation order --}}
    <div class="overflow-x-auto">
        <div role="tablist" class="tabs tabs-lift tabs-sm">
            <button role="tab" type="button" wire:click="$set('activeTab', 'block-allow')"
                    class="tab gap-1.5 {{ $activeTab === 'block-allow' ? 'tab-active' : '' }}">
                <x-heroicon-o-no-symbol class="w-4 h-4" />
                {{ __('admin.security_tab_block_allow') }}
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'attackers')"
                    class="tab gap-1.5 {{ $activeTab === 'attackers' ? 'tab-active' : '' }}">
                <x-heroicon-o-shield-exclamation class="w-4 h-4" />
                {{ __('admin.security_tab_attackers') }}
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'threat-feeds')"
                    class="tab gap-1.5 {{ $activeTab === 'threat-feeds' ? 'tab-active' : '' }}">
                <x-heroicon-o-globe-alt class="w-4 h-4" />
                {{ __('admin.security_tab_threat_feeds') }}
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'firewall-rules')"
                    class="tab gap-1.5 {{ $activeTab === 'firewall-rules' ? 'tab-active' : '' }}">
                <x-heroicon-o-fire class="w-4 h-4" />
                {{ __('admin.security_tab_firewall_rules') }}
            </button>
        </div>
    </div>

    {{-- Tab Panel: Block & Allow Lists (allow list first, matching the kernel evaluation order) --}}
    @if ($activeTab === 'block-allow')
    <div class="space-y-6">
        {{-- Card: Whitelist (trusted allow list) --}}
        <div id="whitelist-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_whitelist_ips') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $whitelistCount }}</span>
                            <x-tooltip :tip="__('admin.security_whitelist_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                    </div>
                </div>

                {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
                <div class="flex flex-col lg:flex-row gap-6 items-start">
                    {{-- Left Column: Quick-Add Form & Self-Whitelisting (Fixed Ergonomic Width) --}}
                    <div class="w-full lg:w-80 lg:shrink-0 space-y-3">
                        <form wire:submit="addWhitelistIp" class="space-y-2.5 bg-base-200/50 p-3.5 rounded-box border border-base-200">
                            <div class="text-xs font-semibold text-base-content/80 flex items-center gap-1.5">
                                <x-heroicon-o-shield-check class="w-4 h-4 text-success" />
                                <span>{{ __('admin.security_add_to_whitelist') }}</span>
                            </div>
                            <div class="space-y-2">
                                <div>
                                    <input wire:model="newWhitelistIp" type="text"
                                           placeholder="{{ __('admin.security_ip_or_cidr') }}"
                                           class="input input-bordered input-sm w-full font-mono @error('newWhitelistIp') input-error @enderror" />
                                    @error('newWhitelistIp')
                                        <span class="text-error text-xs mt-0.5 block">{{ $message }}</span>
                                    @enderror
                                </div>
                                <input wire:model="newWhitelistDescription" type="text"
                                       placeholder="{{ __('admin.security_ip_description') }}"
                                       class="input input-bordered input-sm w-full" />
                                <button type="submit" class="btn btn-primary btn-sm w-full gap-1 shadow-xs">
                                    <x-heroicon-o-plus class="w-4 h-4" />
                                    <span>{{ __('admin.security_add_to_whitelist') }}</span>
                                </button>
                            </div>
                        </form>

                        {{-- Admin Self-Whitelisting Lockout Guard --}}
                        @if (! $isCurrentIpWhitelisted)
                            <div class="p-3 bg-warning/10 border border-warning/30 rounded-box flex items-center justify-between gap-2">
                                <div class="text-xs">
                                    <div class="font-semibold text-warning-content flex items-center gap-1">
                                        <x-heroicon-o-exclamation-triangle class="w-3.5 h-3.5 text-warning shrink-0" />
                                        <span>Lockout Safety</span>
                                    </div>
                                    <div class="text-base-content/70 text-[11px] mt-0.5">Your IP (<span class="font-mono">{{ $adminIp }}</span>) is not whitelisted.</div>
                                </div>
                                <button wire:click="whitelistCurrentIp" type="button" class="btn btn-warning btn-xs whitespace-nowrap shadow-xs">
                                    {{ __('admin.security_protect_my_ip') }}
                                </button>
                            </div>
                        @else
                            <div class="p-2.5 bg-success/10 border border-success/30 rounded-box flex items-center gap-2 text-xs text-success font-medium">
                                <x-heroicon-o-shield-check class="w-4 h-4 text-success shrink-0" />
                                <span>Your current IP (<span class="font-mono font-bold">{{ $adminIp }}</span>) is whitelisted.</span>
                            </div>
                        @endif
                    </div>

                    {{-- Right Column: Search Filter & Scrollable Table Viewport (Flex Expand) --}}
                    <div class="w-full lg:flex-1 space-y-3">
                        <div class="relative">
                            <input wire:model.live.debounce.250ms="whitelistSearch" type="text"
                                   placeholder="{{ __('admin.security_search_whitelist') }}"
                                   class="input input-bordered input-sm w-full pl-9" />
                            <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-2.5 text-base-content/40" />
                        </div>

                        <div class="overflow-x-auto max-h-80 overflow-y-auto border border-base-200 rounded-box">
                            <table class="table table-pin-rows">
                                <thead>
                                    <tr>
                                        <th>{{ __('admin.security_ip_or_cidr') }}</th>
                                        <th>{{ __('admin.description') }}</th>
                                        <th class="text-right">{{ __('admin.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($whitelistIps as $item)
                                        <tr class="hover">
                                            <td class="font-mono font-medium text-success">
                                                {{ $item->ip_address }}
                                                @if ($item->ip_address === $adminIp)
                                                    <span class="badge badge-success badge-sm ml-1">You</span>
                                                @endif
                                            </td>
                                            <td class="text-base-content/70 truncate max-w-xs">{{ $item->description ?: '—' }}</td>
                                            <td class="text-right">
                                                <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$item->ip_address" wire:click="deleteIp({{ $item->id }})" class="text-error p-1" />
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center py-6 text-base-content/60">
                                                {{ __('admin.security_no_whitelist_found') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Card: Blacklist (permanent kernel drop) --}}
        <div id="blacklist-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_blacklist_ips') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $blacklistCount }}</span>
                            <x-tooltip :tip="__('admin.security_blacklist_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                    </div>
                </div>

                {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
                <div class="flex flex-col lg:flex-row gap-6 items-start">
                    {{-- Left Column: Quick-Add Form (Fixed Ergonomic Width) --}}
                    <div class="w-full lg:w-80 lg:shrink-0 space-y-3">
                        <form wire:submit="addBlacklistIp" class="space-y-2.5 bg-base-200/50 p-3.5 rounded-box border border-base-200">
                            <div class="text-xs font-semibold text-base-content/80 flex items-center gap-1.5">
                                <x-heroicon-o-no-symbol class="w-4 h-4 text-error" />
                                <span>{{ __('admin.security_add_to_blacklist') }}</span>
                            </div>
                            <div class="space-y-2">
                                <div>
                                    <input wire:model="newBlacklistIp" type="text"
                                           placeholder="{{ __('admin.security_ip_or_cidr') }}"
                                           class="input input-bordered input-sm w-full font-mono @error('newBlacklistIp') input-error @enderror" />
                                    @error('newBlacklistIp')
                                        <span class="text-error text-xs mt-0.5 block">{{ $message }}</span>
                                    @enderror
                                </div>
                                <input wire:model="newBlacklistDescription" type="text"
                                       placeholder="{{ __('admin.security_ip_description') }}"
                                       class="input input-bordered input-sm w-full" />
                                <button type="submit" class="btn btn-error btn-sm w-full gap-1 shadow-xs">
                                    <x-heroicon-o-plus class="w-4 h-4" />
                                    <span>{{ __('admin.security_add_to_blacklist') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    {{-- Right Column: Search Filter & Scrollable Table Viewport (Flex Expand) --}}
                    <div class="w-full lg:flex-1 space-y-3">
                        <div class="relative">
                            <input wire:model.live.debounce.250ms="blacklistSearch" type="text"
                                   placeholder="{{ __('admin.security_search_blacklist') }}"
                                   class="input input-bordered input-sm w-full pl-9" />
                            <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-2.5 text-base-content/40" />
                        </div>

                        <div class="overflow-x-auto max-h-80 overflow-y-auto border border-base-200 rounded-box">
                            <table class="table table-pin-rows">
                                <thead>
                                    <tr>
                                        <th>{{ __('admin.security_ip_or_cidr') }}</th>
                                        <th>{{ __('admin.description') }}</th>
                                        <th class="text-right">{{ __('admin.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($blacklistIps as $item)
                                        <tr class="hover">
                                            <td class="font-mono font-medium text-error">
                                                {{ $item->ip_address }}
                                            </td>
                                            <td class="text-base-content/70 truncate max-w-xs">{{ $item->description ?: '—' }}</td>
                                            <td class="text-right">
                                                <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$item->ip_address" wire:click="deleteIp({{ $item->id }})" class="text-error p-1" />
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center py-6 text-base-content/60">
                                                {{ __('admin.security_no_blacklist_found') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div>
    @endif

    {{-- Tab Panel: Attackers (dynamic bans and the signatures that feed them) --}}
    @if ($activeTab === 'attackers')
        {{-- Card: Currently Blocked Attackers (dynamic kernel drops) --}}
        <div id="attackers-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_banned_attackers') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $activeBans->count() }}</span>
                            <x-tooltip :tip="__('admin.security_threats_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 self-start sm:self-auto">
                        <button wire:click="openSettingsDrawer" type="button" class="btn btn-outline btn-sm gap-1">
                            <x-heroicon-o-cog-6-tooth class="w-4 h-4" />
                            <span>{{ __('admin.security_protection_settings') }}</span>
                        </button>
                        <button wire:click="openManualBanModal" type="button" class="btn btn-outline btn-sm gap-1">
                            <x-heroicon-o-no-symbol class="w-4 h-4 text-error" />
                            <span>{{ __('admin.security_block_manually') }}</span>
                        </button>
                    </div>
                </div>

                {{-- Threat Table --}}
                <div class="overflow-x-auto max-h-80 overflow-y-auto border border-base-200 rounded-box">
                    <table class="table table-pin-rows">
                        <thead>
                            <tr>
                                <th>{{ __('admin.security_attacker_ip') }}</th>
                                <th>{{ __('admin.security_attack_type') }}</th>
                                <th>{{ __('admin.security_attempts') }}</th>
                                <th>{{ __('admin.security_expires') }}</th>
                                <th class="text-right">{{ __('admin.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activeBans as $ban)
                                <tr class="hover">
                                    <td class="font-mono font-medium text-error">
                                        {{ $ban->ip_address }}
                                        {{-- The reason carries the signature that triggered the ban (e.g. sip_scanner · User-Agent: friendly-scanner),
                                             so a false positive is diagnosable before it becomes a support call. --}}
                                        <div class="text-xs font-sans font-normal text-base-content/60 max-w-xs truncate" title="{{ $ban->reason }}">{{ $ban->reason }}</div>
                                    </td>
                                    <td>
                                        @php
                                            $vectorLabel = match ($ban->vector) {
                                                'sip_auth' => __('admin.vector_sip'),
                                                'web_auth' => __('admin.vector_web'),
                                                'ssh' => __('admin.vector_ssh'),
                                                'sip_scanner' => __('admin.security_vector_sip_scanner'),
                                                default => __('admin.vector_manual'),
                                            };
                                            $vectorBadge = match ($ban->vector) {
                                                'sip_auth' => 'badge-primary',
                                                'web_auth' => 'badge-info',
                                                'ssh' => 'badge-secondary',
                                                'sip_scanner' => 'badge-warning',
                                                default => 'badge-neutral',
                                            };
                                        @endphp
                                        <span class="badge {{ $vectorBadge }} badge-sm">{{ $vectorLabel }}</span>
                                    </td>
                                    <td>{{ $ban->attempt_count }}</td>
                                    <td class="text-base-content/70">
                                        @if ($ban->expires_at)
                                            {{ $ban->expires_at->diffForHumans() }}
                                        @else
                                            <span class="badge badge-ghost badge-sm">Permanent</span>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <div class="join">
                                            <button wire:click="unban('{{ $ban->ip_address }}')" type="button"
                                                    class="btn btn-outline btn-xs join-item"
                                                    title="{{ __('admin.security_unblock') }}">
                                                {{ __('admin.security_unblock') }}
                                            </button>
                                            <button wire:click="promoteToWhitelist('{{ $ban->ip_address }}')" type="button"
                                                    class="btn btn-success btn-xs join-item"
                                                    title="{{ __('admin.security_trust_ip') }}">
                                                {{ __('admin.security_trust_ip') }}
                                            </button>
                                            <button wire:click="promoteToBlacklist('{{ $ban->ip_address }}')" type="button"
                                                    class="btn btn-error btn-xs join-item"
                                                    title="{{ __('admin.security_block_permanently') }}">
                                                {{ __('admin.security_block_permanently') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-6 text-base-content/60">
                                        <x-heroicon-o-shield-check class="w-6 h-6 mx-auto text-success/60 mb-1.5" style="width: 1.5rem; height: 1.5rem;" />
                                        <div class="text-sm font-medium">{{ __('admin.security_no_attackers') }}</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Card: SIP Bot & Scanner Signatures --}}
        <div class="card bg-base-100 shadow-sm border border-base-200 mt-4">
            <div class="card-body p-4 space-y-4">
                <div class="border-b border-base-200 pb-3">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_scanner_title') }}</h2>
                        <x-tooltip :tip="__('admin.security_scanner_desc')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_scanner_desc') }}</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    {{-- Left: enforcement, duration, custom signatures --}}
                    <div class="space-y-4">
                        <div>
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input wire:click="setSipScannerEnforcement({{ $sipScanner['enforcement'] ? 'false' : 'true' }})"
                                       type="checkbox" class="toggle toggle-error toggle-sm" @checked($sipScanner['enforcement']) />
                                <span class="label-text font-medium">{{ __('admin.security_scanner_enforcement') }}</span>
                            </label>
                            <p class="text-xs text-base-content/60 mt-1">{{ __('admin.security_scanner_enforcement_help') }}</p>
                        </div>

                        <div class="form-control">
                            <label class="label justify-start gap-2 pb-1">
                                <span class="label-text font-medium">{{ __('admin.security_scanner_duration') }}</span>
                            </label>
                            <select wire:change="setSipScannerBanSeconds($event.target.value)" class="select select-bordered select-sm w-full max-w-xs">
                                <option value="3600" @selected($sipScanner['ban_seconds'] === 3600)>{{ __('admin.security_scanner_duration_1h') }}</option>
                                <option value="86400" @selected($sipScanner['ban_seconds'] === 86400)>{{ __('admin.security_scanner_duration_24h') }}</option>
                                <option value="604800" @selected($sipScanner['ban_seconds'] === 604800)>{{ __('admin.security_scanner_duration_7d') }}</option>
                                <option value="0" @selected($sipScanner['ban_seconds'] === 0)>{{ __('admin.security_scanner_duration_permanent') }}</option>
                            </select>
                        </div>

                        <div>
                            <span class="text-sm font-medium text-base-content block mb-1.5">{{ __('admin.security_scanner_custom') }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap mb-2">
                                @forelse ($sipScanner['custom'] as $signature)
                                    <span class="badge badge-warning badge-sm gap-1 font-mono">
                                        {{ $signature }}
                                        <button type="button" wire:click="removeScannerSignature('{{ $signature }}')" class="cursor-pointer" title="{{ __('client.delete') }}">
                                            <x-heroicon-s-x-mark class="w-3 h-3" />
                                        </button>
                                    </span>
                                @empty
                                    <span class="text-xs text-base-content/60">{{ __('admin.security_scanner_custom_empty') }}</span>
                                @endforelse
                            </div>
                            <div class="flex items-start gap-2">
                                <input wire:model="newScannerSignature" type="text" placeholder="Zoiper"
                                       class="input input-bordered input-sm font-mono w-full max-w-xs @error('newScannerSignature') input-error @enderror" />
                                <button wire:click="addScannerSignature" type="button" class="btn btn-outline btn-sm">{{ __('admin.security_scanner_add') }}</button>
                            </div>
                            @error('newScannerSignature') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            <p class="text-xs text-base-content/60 mt-1">{{ __('admin.security_scanner_custom_help') }}</p>
                        </div>
                    </div>

                    {{-- Right: the read-only curated tiers --}}
                    <div class="space-y-3">
                        <div>
                            <span class="badge badge-error badge-sm mb-1.5">{{ __('admin.security_scanner_autoban_group') }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                @foreach ($sipScanner['defaults']['high'] as $entry)
                                    <span class="badge badge-ghost badge-sm font-mono">{{ $entry['pattern'] }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <span class="badge badge-warning badge-sm mb-1.5">{{ __('admin.security_scanner_record_group') }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                @foreach ($sipScanner['defaults']['low'] as $entry)
                                    <span class="badge badge-ghost badge-sm font-mono">{{ $entry['pattern'] }}</span>
                                @endforeach
                            </div>
                        </div>
                        <p class="text-xs text-base-content/60">{{ __('admin.security_scanner_incidents_help') }}</p>
                    </div>
                </div>

                {{-- Detected but not blocked incidents with the one-click enforcement --}}
                @if ($sipScanner['incidents']->isNotEmpty())
                    <div class="border-t border-base-200 pt-3">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-sm font-medium">{{ __('admin.security_scanner_incidents') }}</span>
                            <span class="badge badge-warning badge-sm font-mono">{{ $sipScanner['incidents']->count() }}</span>
                        </div>
                        <div class="overflow-x-auto max-h-60 overflow-y-auto border border-base-200 rounded-box">
                            <table class="table table-sm table-pin-rows">
                                <thead>
                                    <tr>
                                        <th>{{ __('admin.security_attacker_ip') }}</th>
                                        <th>{{ __('admin.security_attack_type') }}</th>
                                        <th>{{ __('admin.security_attempts') }}</th>
                                        <th class="text-right">{{ __('admin.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sipScanner['incidents'] as $incident)
                                        <tr class="hover">
                                            <td class="font-mono font-medium text-warning">{{ $incident->ip_address }}</td>
                                            <td class="text-xs text-base-content/70 max-w-md truncate" title="{{ $incident->reason }}">{{ $incident->reason }}</td>
                                            <td>{{ $incident->attempt_count }}</td>
                                            <td class="text-right whitespace-nowrap">
                                                <button wire:click="promoteScannerIncident('{{ $incident->ip_address }}')" type="button" class="btn btn-error btn-xs">
                                                    {{ __('admin.security_scanner_add_to_ban') }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Tab Panel: Threat Feeds (public blocklist management) --}}
    @if ($activeTab === 'threat-feeds')
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
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_threat_feeds_title') }}</h2>
                            <span class="badge {{ $feedBadgeClass }} badge-sm" wire:loading.remove wire:target="syncThreatFeedNow">{{ __('admin.security_threat_feed_status_'.$feedStatus) }}</span>
                            <span class="badge badge-info badge-sm" wire:loading wire:target="syncThreatFeedNow">{{ __('admin.security_threat_feed_status_syncing') }}</span>
                            <x-tooltip :tip="__('admin.security_threat_feeds_tooltip')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                        <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_threat_feeds_desc') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
                        <button wire:click="syncThreatFeedNow" type="button" class="btn btn-outline btn-sm gap-1">
                            <x-heroicon-o-arrow-path class="w-4 h-4" />
                            <span>{{ __('admin.security_threat_feed_sync_now') }}</span>
                        </button>
                        <button wire:click="removeAllFeedBlocks" type="button"
                                wire:confirm="{{ __('admin.security_threat_feed_remove_blocks_confirm') }}"
                                class="btn btn-outline btn-error btn-sm gap-1">
                            <x-heroicon-o-trash class="w-4 h-4" />
                            <span>{{ __('admin.security_threat_feed_remove_blocks') }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    {{-- Left: feed configuration --}}
                    <div class="space-y-4">
                        <label class="label cursor-pointer justify-start gap-2 p-0">
                            <input wire:model="feedEnabled" type="checkbox" class="toggle toggle-error toggle-sm" />
                            <span class="label-text font-medium">{{ __('admin.security_threat_feed_enable') }}</span>
                        </label>

                        <div>
                            <span class="text-sm font-medium text-base-content block mb-1.5">{{ __('admin.security_threat_feed_country_mode') }}</span>
                            <div class="space-y-1">
                                <label class="label cursor-pointer justify-start gap-2 py-0.5">
                                    <input wire:model.live="feedCountryMode" type="radio" value="all" class="radio radio-primary radio-sm" />
                                    <span class="label-text">{{ __('admin.security_threat_feed_mode_all') }}</span>
                                </label>
                                <label class="label cursor-pointer justify-start gap-2 py-0.5">
                                    <input wire:model.live="feedCountryMode" type="radio" value="blacklist" class="radio radio-primary radio-sm" />
                                    <span class="label-text">{{ __('admin.security_threat_feed_mode_bc') }}</span>
                                </label>
                                <label class="label cursor-pointer justify-start gap-2 py-0.5">
                                    <input wire:model.live="feedCountryMode" type="radio" value="whitelist" class="radio radio-primary radio-sm" />
                                    <span class="label-text">{{ __('admin.security_threat_feed_mode_wc') }}</span>
                                </label>
                            </div>
                        </div>

                        @if ($feedCountryMode !== 'all')
                            <div class="form-control">
                                <label class="label justify-start gap-2 pb-1">
                                    <span class="label-text font-medium">{{ __('admin.security_threat_feed_countries') }}</span>
                                </label>
                                <input wire:model="feedCountriesInput" type="text" placeholder="US, CA, GB"
                                       class="input input-bordered input-sm font-mono w-full @error('feedCountriesInput') input-error @enderror" />
                                @error('feedCountriesInput') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                                <span class="text-xs text-base-content/60 mt-1">{{ __('admin.security_threat_feed_countries_help') }}</span>
                            </div>
                        @endif

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

                        <button wire:click="saveFeedSettings" type="button" class="btn btn-primary btn-sm">
                            {{ __('client.save') }}
                        </button>
                    </div>

                    {{-- Right: live metrics --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 bg-base-200/50 rounded-box border border-base-200">
                            <div class="text-xs text-base-content/60">{{ __('admin.security_threat_feed_metric_entries') }}</div>
                            <div class="text-lg font-semibold font-mono text-base-content">{{ $threatFeed?->entries_count ?? 0 }}</div>
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
    @endif

    {{-- Tab Panel: Firewall Rules (full pipeline summary, last) --}}
    @if ($activeTab === 'firewall-rules')

    {{-- Zone 4: Sequential Firewall Rules (full evaluation pipeline) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_firewall_rules') }}</h2>
                        <x-tooltip :tip="__('admin.security_firewall_rules_tooltip')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_firewall_rules_desc') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Add Custom Rule --}}
                    <button wire:click="openCustomRuleModal" type="button" class="btn btn-neutral btn-sm gap-1">
                        <x-heroicon-o-plus class="w-4 h-4" />
                        <span>{{ __('admin.security_add_rule') }}</span>
                    </button>
                </div>
            </div>

            {{-- Unified Firewall Rules Table --}}
            <div x-data="{ showSystemPreFilters: false }" class="overflow-x-auto border border-base-200 rounded-box">
                <table class="table">
                    <thead>
                        <tr class="bg-base-200/40 text-base-content/70">
                            <th class="w-14 text-center">{{ __('client.status') }}</th>
                            <th>{{ __('admin.security_rule_name') }}</th>
                            <th class="w-24">{{ __('admin.security_protocol') }}</th>
                            <th>{{ __('admin.security_port') }}</th>
                            <th>{{ __('admin.security_source_ip') }}</th>
                            <th>{{ __('admin.security_action') }}</th>
                            <th class="text-right">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        {{-- Ingress Pre-Filters Collapsible Header --}}
                        <tr class="bg-base-200/40 text-xs font-semibold text-base-content/80 cursor-pointer hover:bg-base-200/70 transition-colors select-none"
                            @click="showSystemPreFilters = !showSystemPreFilters"
                            title="{{ __('admin.security_toggle_invariants_tooltip') }}">
                            <td colspan="7" class="py-2.5 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_system_invariants_prefilters') }}</span>
                                        <span class="badge badge-ghost badge-sm text-xs font-normal">
                                            {{ __('admin.security_invariants_rules_count', ['count' => count($preFilterRows)]) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        {{-- The reset escape hatch always restores a known-safe evaluation order. --}}
                                        <button wire:click.stop="resetPreFilterOrder" type="button"
                                                wire:confirm="{{ __('admin.security_prefilter_reset_confirm') }}"
                                                @disabled(! $prefilterEnabled)
                                                class="btn btn-ghost btn-xs gap-1 text-base-content/70 normal-case font-medium">
                                            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                                            <span>{{ __('admin.security_prefilter_reset') }}</span>
                                        </button>
                                        <div class="flex items-center gap-1.5 text-xs font-medium text-primary">
                                            <span x-text="showSystemPreFilters ? '{{ __('admin.security_hide_rules') }}' : '{{ __('admin.security_show_rules') }}'"></span>
                                            <x-heroicon-s-chevron-down class="w-4 h-4 transition-transform duration-200" ::class="showSystemPreFilters ? 'rotate-180' : ''" />
                                        </div>
                                    </div>
                                </div>
                                @if (! $prefilterEnabled)
                                    <div class="text-warning text-[11px] font-normal mt-1 normal-case tracking-normal">{{ __('admin.security_prefilter_disabled_note') }}</div>
                                @endif
                            </td>
                        </tr>

                        {{-- Pre-Filter Rows (rendered in the stored evaluation order) --}}
                        @foreach ($preFilterRows as $preFilterRow)
                            <tr class="hover {{ $preFilterRow['invariant'] ? 'bg-base-200/5' : '' }}" x-show="showSystemPreFilters" x-cloak>
                                <td class="text-center">
                                    <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full {{ $preFilterRow['status_class'] }} {{ $preFilterRow['count_pulse'] ? 'animate-pulse' : '' }}" title="Active"></span>
                                </td>
                                <td>
                                    {{-- Fixed-width tracks keep the rule name, kernel badge and info icon aligned across every pre-filter row. --}}
                                    <div class="flex items-center gap-1.5 font-medium text-base-content">
                                        <span class="w-[17.25rem] shrink-0">{{ $preFilterRow['label'] }}</span>
                                        <span class="w-56 shrink-0"><span class="badge badge-ghost badge-sm font-mono">{{ $preFilterRow['badge'] }}</span></span>
                                        @if ($preFilterRow['tooltip'])
                                            <x-tooltip :tip="$preFilterRow['tooltip']" align="start" position="right">
                                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                            </x-tooltip>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-sm font-mono text-base-content/70">
                                    ALL
                                </td>
                                <td class="text-sm text-base-content/60">
                                    {{ __('admin.security_all_ports') }}
                                </td>
                                <td>
                                    @if ($preFilterRow['source_kind'] === 'static')
                                        <span class="font-mono text-sm text-base-content/70">{{ $preFilterRow['source_static'] }}</span>
                                        <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                                    @elseif ($preFilterRow['source_kind'] === 'anywhere')
                                        <span class="font-mono text-sm text-base-content/70">{{ __('admin.security_source_anywhere') }}</span>
                                        <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                                    @else
                                        <span class="font-mono text-sm {{ $preFilterRow['count_class'] }}">
                                            {{ $preFilterRow['count'] }} {{ trans_choice($preFilterRow['count_choice'], $preFilterRow['count']) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($preFilterRow['action'] === 'allow')
                                        <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                                    @else
                                        <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        @if ($preFilterRow['pinned'])
                                            {{-- Pinned first: localhost IPC can never be filtered. --}}
                                            <x-tooltip :tip="__('admin.security_prefilter_locked_tooltip')" align="end" position="left">
                                                <span class="inline-flex items-center text-base-content/40 px-1">
                                                    <x-heroicon-o-lock-closed class="w-3.5 h-3.5" />
                                                </span>
                                            </x-tooltip>
                                            <span class="badge badge-ghost badge-sm text-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                                        @else
                                            <span class="flex flex-col">
                                                <button wire:click="movePreFilterUp('{{ $preFilterRow['key'] }}')" type="button"
                                                        @disabled(! $prefilterEnabled)
                                                        class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content disabled:opacity-30">
                                                    <x-heroicon-s-chevron-up class="w-3 h-3" />
                                                </button>
                                                <button wire:click="movePreFilterDown('{{ $preFilterRow['key'] }}')" type="button"
                                                        @disabled(! $prefilterEnabled)
                                                        class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content disabled:opacity-30">
                                                    <x-heroicon-s-chevron-down class="w-3 h-3" />
                                                </button>
                                            </span>
                                            @if ($preFilterRow['invariant'])
                                                <span class="badge badge-ghost badge-sm text-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                                            @endif
                                            @if ($preFilterRow['manage'])
                                                <button wire:click="$set('activeTab', '{{ $preFilterRow['manage']['tab'] }}')" type="button"
                                                        class="btn btn-ghost btn-xs gap-1 {{ $preFilterRow['manage']['class'] }}">
                                                    <x-heroicon-o-arrow-up class="w-3.5 h-3.5" />
                                                    <span>{{ $preFilterRow['manage']['label'] }}</span>
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach

                        {{-- Standard Services Section Header --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_core_services_title') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_core_services_note') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- Core PBX Services Rows --}}
                        @foreach ($catalogServices as $service)
                            <tr class="hover {{ ! $service->enabled ? 'opacity-50' : '' }}">
                                <td class="text-center">
                                    <input wire:click="toggleSystemService({{ $service->id }})" type="checkbox"
                                           class="toggle toggle-success toggle-sm"
                                           @checked($service->enabled)
                                           title="{{ $service->enabled ? __('client.enabled') : __('client.disabled') }}" />
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5 font-medium text-base-content">
                                        <span>{{ $service->name }}</span>
                                        @if ($service->description)
                                            <x-tooltip :tip="$service->description" align="start" position="right">
                                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                            </x-tooltip>
                                        @endif
                                    </div>
                                    @if ($service->protocol === 'udp' && trim((string) $service->port_range) === '69')
                                        {{-- Hardened TFTP Defense Profile: one shield badge for the
                                             office manager, expandable per-rule counters for the
                                             engineer (progressive depth). --}}
                                        <div class="mt-1.5" x-data="{ tftpCountersOpen: false }">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <button type="button" @click="tftpCountersOpen = ! tftpCountersOpen"
                                                        class="badge badge-warning badge-sm gap-1 cursor-pointer">
                                                    <x-heroicon-o-shield-check class="w-3.5 h-3.5" />
                                                    <span>{{ __('admin.security_tftp_defense_label') }}</span>
                                                    <x-heroicon-s-chevron-down class="w-3 h-3 transition-transform" x-bind:class="tftpCountersOpen ? 'rotate-180' : ''" />
                                                </button>
                                                <x-tooltip :tip="__('admin.security_tftp_defense_tooltip')" align="start" position="right">
                                                    <x-heroicon-o-information-circle class="w-3.5 h-3.5 text-base-content/60 cursor-help" />
                                                </x-tooltip>
                                                <label class="label cursor-pointer gap-1.5 p-0">
                                                    <input wire:click="setTftpDefense({{ $tftpDefense['enabled'] ? 'false' : 'true' }})"
                                                           type="checkbox" class="toggle toggle-warning toggle-xs" @checked($tftpDefense['enabled']) />
                                                    <span class="label-text text-xs">{{ $tftpDefense['enabled'] ? __('client.enabled') : __('client.disabled') }}</span>
                                                </label>
                                            </div>
                                            <div x-show="tftpCountersOpen" x-cloak
                                                 class="mt-1.5 p-2 rounded-box bg-base-200/50 border border-base-200 text-xs space-y-1 max-w-xs">
                                                <div class="text-base-content/60">
                                                    {{ __('admin.security_tftp_defense_rate_value', ['rate' => $tftpDefense['rate_limit'], 'burst' => $tftpDefense['burst']]) }}
                                                </div>
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="text-base-content/60">{{ __('admin.security_tftp_counter_uploads') }}</span>
                                                    <span class="font-mono font-semibold">{{ $tftpDefense['counters']['uploads'] ?? '—' }}</span>
                                                </div>
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="text-base-content/60">{{ __('admin.security_tftp_counter_traversal') }}</span>
                                                    <span class="font-mono font-semibold">{{ $tftpDefense['counters']['traversal'] ?? '—' }}</span>
                                                </div>
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="text-base-content/60">{{ __('admin.security_tftp_counter_probes') }}</span>
                                                    <span class="font-mono font-semibold">{{ $tftpDefense['counters']['probes'] ?? '—' }}</span>
                                                </div>
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="text-base-content/60">{{ __('admin.security_tftp_counter_flood') }}</span>
                                                    <span class="font-mono font-semibold">{{ $tftpDefense['counters']['flood'] ?? '—' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                                <td class="font-mono text-sm font-semibold text-base-content/80">
                                    {{ $service->protocol === 'both' ? 'TCP/UDP' : strtoupper($service->protocol) }}
                                </td>
                                <td>
                                    @if ($service->protocol === 'icmp')
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="font-mono text-sm font-semibold text-base-content">echo-request</span>
                                            @if ($service->rate_limit)
                                                <span class="font-mono text-xs text-base-content/60 ml-1">{{ $service->rate_limit }}/s limit (burst {{ $service->burst ?? $service->rate_limit }})</span>
                                            @else
                                                <span class="font-mono text-xs text-base-content/60 ml-1">{{ __('admin.security_rate_limit_unlimited') }}</span>
                                            @endif
                                            <span class="font-mono text-xs text-base-content/60 ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                                        </div>
                                    @else
                                        <span class="font-mono text-sm font-semibold text-base-content">{{ $service->port_range }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($service->source_ip === 'any' || $service->source_ip === '0.0.0.0/0' || empty($service->source_ip))
                                        <span class="badge badge-ghost badge-sm">{{ __('admin.security_source_anywhere') }}</span>
                                    @else
                                        <span class="font-mono text-sm text-primary font-medium">{{ $service->source_ip }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($service->enabled)
                                        <span class="badge badge-success badge-sm">{{ __('admin.security_action_allow') }}</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">{{ __('client.disabled') }}</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <x-icon-button icon="heroicon-o-pencil-square" :label="__('admin.security_edit_service')" wire:click="openEditSystemServiceModal({{ $service->id }})" class="text-primary" />
                                </td>
                            </tr>
                        @endforeach

                        {{-- Custom Rules Section Header --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_custom_rules_title') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step4_desc') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- Custom Sequential Rules Rows --}}
                        @forelse ($firewallRules as $rule)
                            <tr class="hover {{ ! $rule->enabled ? 'opacity-50' : '' }}">
                                {{-- Status Toggle --}}
                                <td class="text-center">
                                    <input wire:click="toggleRule({{ $rule->id }})" type="checkbox"
                                           class="toggle toggle-primary toggle-sm"
                                           @checked($rule->enabled) />
                                </td>

                                {{-- Rule Name / Description --}}
                                <td class="font-medium text-base-content">
                                    <div>{{ $rule->description }}</div>
                                    @if ($rule->service)
                                        <div class="text-xs text-base-content/60">{{ $rule->service->name }}</div>
                                    @endif
                                </td>

                                {{-- Protocol --}}
                                <td class="font-mono text-sm font-semibold text-base-content/80">
                                    @if ($rule->service)
                                        {{ $rule->service->protocol === 'both' ? 'TCP/UDP' : strtoupper($rule->service->protocol) }}
                                    @else
                                        {{ match (strtolower((string) $rule->custom_protocol)) {
                                            'all' => 'ALL',
                                            default => strtoupper((string) $rule->custom_protocol),
                                        } }}
                                    @endif
                                </td>

                                {{-- Port --}}
                                <td>
                                    @if ($rule->service)
                                        <span class="font-mono text-sm text-base-content/70 whitespace-nowrap">{{ $rule->service->port_range }}</span>
                                    @else
                                        <span class="font-mono text-sm">{{ $rule->custom_port ?? '—' }}</span>
                                    @endif
                                </td>

                                {{-- Source Network --}}
                                <td>
                                    @if ($rule->source_ip === 'any' || $rule->source_ip === '0.0.0.0/0')
                                        <span class="badge badge-ghost badge-sm">{{ __('admin.security_source_anywhere') }}</span>
                                    @else
                                        <span class="font-mono text-sm">{{ $rule->source_ip }}</span>
                                    @endif
                                </td>

                                {{-- Action Badge --}}
                                <td>
                                    @if ($rule->action === 'accept')
                                        <span class="badge badge-success badge-sm">{{ __('admin.security_action_allow') }}</span>
                                    @else
                                        <span class="badge badge-error badge-sm">{{ __('admin.security_action_block') }}</span>
                                    @endif
                                </td>

                                {{-- Reorder Arrows & Row Actions --}}
                                <td class="text-right whitespace-nowrap">
                                    {{-- items-center keeps the stacked arrow pair vertically centered against the single-row action icons --}}
                                    <div class="inline-flex items-center gap-1">
                                        <span class="flex flex-col">
                                            <button wire:click="moveRuleUp({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                                <x-heroicon-s-chevron-up class="w-3 h-3" />
                                            </button>
                                            <button wire:click="moveRuleDown({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                                <x-heroicon-s-chevron-down class="w-3 h-3" />
                                            </button>
                                        </span>
                                        <x-icon-button icon="heroicon-o-pencil-square" :label="__('client.edit').' '.$rule->source_ip" wire:click="openCustomRuleModal({{ $rule->id }})" />
                                        <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$rule->source_ip" wire:click="deleteRule({{ $rule->id }})" class="text-error" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-base-content/60">
                                    <div class="flex items-center justify-center gap-2">
                                        <x-heroicon-o-shield-check class="w-4 h-4 text-success" />
                                        <span class="text-sm text-base-content/70">{{ __('admin.security_no_rules_help') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse

                        {{-- Default Inbound Policy Section Header --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_default_policy') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step5_desc') }}</span>
                                </div>
                            </td>
                        </tr>
                        {{-- Default Inbound Fallback Policy Row --}}
                        <tr class="hover">
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-base-content/40" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_default_policy') }}</span>
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.security_unmatched_traffic') }}</span>
                                </div>
                            </td>
                            <td class="text-sm font-mono text-base-content/70">
                                ALL
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_remaining_traffic') }}
                            </td>
                            <td>
                                <span class="badge badge-ghost badge-sm">{{ __('admin.security_source_anywhere') }}</span>
                            </td>
                            <td>
                                @if ($firewallDefaultPolicy === 'drop')
                                    <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                                @else
                                    <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button wire:click="openDefaultPolicyForm" type="button" class="btn btn-ghost btn-xs gap-1 text-base-content/70">
                                    <x-heroicon-o-cog-6-tooth class="w-3.5 h-3.5" />
                                    <span>{{ __('admin.security_configure') }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- Zone 5: Settings Slide-Over Drawer --}}
    <div x-data="{ open: @entangle('showSettingsDrawer') }"
         x-show="open"
         x-cloak
         class="relative z-50">
        {{-- Backdrop --}}
        <div x-show="open"
             x-transition:enter="ease-in-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in-out duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="open = false; $wire.closeSettingsDrawer()"
             class="fixed inset-0 bg-black/40 backdrop-blur-xs"></div>

        {{-- Drawer Panel --}}
        <div class="fixed inset-y-0 right-0 max-w-md w-full bg-base-100 shadow-2xl p-6 overflow-y-auto flex flex-col justify-between border-l border-base-200">
            <div class="space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-base-200">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cog-6-tooth class="w-6 h-6 text-primary" />
                        <h3 class="text-lg font-bold text-base-content">{{ __('admin.security_protection_settings') }}</h3>
                    </div>
                    <button wire:click="closeSettingsDrawer" type="button" class="btn btn-ghost btn-circle btn-sm">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    {{-- Max Retries --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_max_retry') }}</span>
                            <x-tooltip :tip="__('admin.security_max_retry_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input wire:model="maxRetry" type="number" min="1" max="100" class="input input-bordered input-sm w-full" />
                        @error('maxRetry') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Find Time --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_find_time') }}</span>
                            <x-tooltip :tip="__('admin.security_find_time_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input wire:model="findTime" type="number" min="10" max="86400" class="input input-bordered input-sm w-full" />
                        @error('findTime') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Ban Time --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_ban_time') }}</span>
                            <x-tooltip :tip="__('admin.security_ban_time_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input wire:model="banTime" type="number" min="60" max="31536000" class="input input-bordered input-sm w-full" />
                        @error('banTime') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Attack Vectors --}}
                    <div class="space-y-2 pt-2 border-t border-base-200">
                        <div class="text-xs font-semibold text-base-content/70 mb-2">Monitored Services</div>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input wire:model="protectSip" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="label-text">{{ __('admin.security_protect_sip') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input wire:model="protectWeb" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="label-text">{{ __('admin.security_protect_web') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input wire:model="protectSsh" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="label-text">{{ __('admin.security_protect_ssh') }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-6 border-t border-base-200">
                <button wire:click="closeSettingsDrawer" type="button" class="btn btn-outline btn-sm">
                    {{ __('client.cancel') }}
                </button>
                <button wire:click="saveSettings" type="button" class="btn btn-primary btn-sm">
                    {{ __('client.save') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Modal: Default Inbound Policy --}}
    @if ($showDefaultPolicyModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-sm">
                <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_default_policy') }}</h3>
                <form wire:submit="saveDefaultPolicy" class="space-y-4 mt-4">
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_firewall_status') }} (Default Policy)</span>
                        </label>
                        <select wire:model="firewallDefaultPolicy" class="select select-bordered select-sm w-full">
                            <option value="drop">{{ __('admin.security_policy_drop') }} (Drop)</option>
                            <option value="accept">{{ __('admin.security_policy_accept') }} (Accept)</option>
                        </select>
                        @error('firewallDefaultPolicy') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="modal-action">
                        <button wire:click="closeDefaultPolicyForm" type="button" class="btn btn-outline btn-sm">
                            {{ __('client.cancel') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ __('client.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal: Manual Ban --}}
    @if ($showManualBanModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_manual_ban_title') }}</h3>
                <form wire:submit="manualBan" class="space-y-4 mt-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_attacker_ip') }}</span></label>
                        <input wire:model="manualBanIp" type="text" placeholder="e.g. 198.51.100.42 or 2001:db8::1"
                               class="input input-bordered input-sm font-mono @error('manualBanIp') input-error @enderror" />
                        @error('manualBanIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_ban_duration') }}</span></label>
                        <select wire:model="manualBanDuration" class="select select-bordered select-sm">
                            <option value="3600">{{ __('admin.security_ban_1_hour') }}</option>
                            <option value="86400">{{ __('admin.security_ban_24_hours') }}</option>
                            <option value="604800">{{ __('admin.security_ban_7_days') }}</option>
                            <option value="2592000">{{ __('admin.security_ban_30_days') }}</option>
                            <option value="-1">{{ __('admin.security_ban_permanent') }}</option>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_ban_reason') }}</span></label>
                        <input wire:model="manualBanReason" type="text" placeholder="e.g. Suspicious brute force probe"
                               class="input input-bordered input-sm" />
                    </div>

                    <div class="modal-action">
                        <button wire:click="$set('showManualBanModal', false)" type="button" class="btn btn-outline btn-sm">
                            {{ __('client.cancel') }}
                        </button>
                        <button type="submit" class="btn btn-error btn-sm">
                            {{ __('admin.security_confirm_ban') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal: Custom Firewall Rule --}}
    @if ($showRuleModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-lg">
                <h3 class="font-bold text-lg text-base-content">
                    {{ $editingRuleId ? 'Edit Firewall Rule' : __('admin.security_add_rule') }}
                </h3>
                <form wire:submit="saveCustomRule" class="space-y-4 mt-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_rule_name') }}</span></label>
                        <input wire:model="ruleDescription" type="text" placeholder="e.g. Allow Office VoIP Phones"
                               class="input input-bordered input-sm @error('ruleDescription') input-error @enderror" />
                        @error('ruleDescription') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_source_ip') }}</span></label>
                        <input wire:model="ruleSourceIp" type="text" placeholder="any or 192.168.1.0/24"
                               class="input input-bordered input-sm font-mono @error('ruleSourceIp') input-error @enderror" />
                        @error('ruleSourceIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_port') }}</span></label>
                        <select wire:model.live="ruleServiceId" class="select select-bordered select-sm">
                            <option value="">Custom Port / Protocol</option>
                            @foreach ($catalogServices as $service)
                                <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->port_range }}/{{ strtoupper($service->protocol) }})</option>
                            @endforeach
                        </select>
                    </div>

                    @if (! $ruleServiceId)
                        <div class="grid grid-cols-2 gap-2">
                            <div class="form-control">
                                <label class="label"><span class="label-text font-medium">Custom Port</span></label>
                                <input wire:model="ruleCustomPort" type="text" placeholder="e.g. 5060, 10000-20000"
                                       class="input input-bordered input-sm font-mono @error('ruleCustomPort') input-error @enderror" />
                                @error('ruleCustomPort') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-medium">Protocol</span></label>
                                <select wire:model="ruleCustomProtocol" class="select select-bordered select-sm">
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                    <option value="all">Both (All)</option>
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-2">
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">{{ __('admin.security_action') }}</span></label>
                            <select wire:model="ruleAction" class="select select-bordered select-sm">
                                <option value="accept">{{ __('admin.security_action_allow') }} (Accept)</option>
                                <option value="drop">{{ __('admin.security_action_block') }} (Drop)</option>
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">{{ __('client.status') }}</span></label>
                            <label class="label cursor-pointer justify-start gap-2 pt-2">
                                <input wire:model="ruleEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                                <span class="label-text">{{ __('client.enabled') }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="modal-action">
                        <button wire:click="$set('showRuleModal', false)" type="button" class="btn btn-outline btn-sm">
                            {{ __('client.cancel') }}
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ __('client.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal: Edit Core PBX System Service --}}
    @if ($showSystemServiceModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-lg">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-bold text-lg text-base-content">
                            {{ __('admin.security_edit_service_title', ['name' => $systemServiceName]) }}
                        </h3>
                        <p class="text-xs text-base-content/60 mt-0.5">{{ $systemServiceDescription }}</p>
                    </div>
                    <button wire:click="$set('showSystemServiceModal', false)" type="button" class="btn btn-ghost btn-circle btn-sm">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>

                {{-- Safety Alert Banner --}}
                <div class="alert alert-warning text-xs mt-4 py-2.5 px-3 rounded-lg flex items-start gap-2">
                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 shrink-0 text-warning" />
                    <div class="space-y-1">
                        <div class="font-semibold">{{ __('admin.security_edit_service_warning') }}</div>
                        @if (in_array($systemServiceName, ['Web Admin Portal', 'SSH Console'], true))
                            <div class="text-[11px] opacity-90">{{ __('admin.security_edit_service_lockout_note', ['ip' => $adminIp]) }}</div>
                        @endif
                    </div>
                </div>

                <form wire:submit="saveSystemService" class="space-y-4 mt-4">
                    @if ($systemServiceProtocol === 'icmp')
                        {{-- ICMP Diagnostic Echo --}}
                        <div class="form-control">
                            <label class="label justify-start gap-2">
                                <span class="label-text font-medium">{{ __('admin.security_service_port_range') }}</span>
                            </label>
                            <input type="text" value="echo-request (ICMP & ICMPv6 Echo)" disabled
                                   class="input input-bordered input-sm font-mono bg-base-200/60 cursor-not-allowed text-base-content/80" />
                        </div>

                        {{-- Protocol --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_protocol') }}</span></label>
                            <select wire:model="systemServiceProtocol" disabled class="select select-bordered select-sm w-full bg-base-200/60 cursor-not-allowed">
                                <option value="icmp">ICMP (Ping Diagnostics & IPv6)</option>
                            </select>
                        </div>

                        {{-- Burstable Rate Limiting Option --}}
                        <div class="p-3.5 bg-base-200/50 rounded-lg space-y-3 border border-base-200">
                            <div class="flex items-center justify-between">
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input wire:model.live="systemServiceRateLimitEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-xs" />
                                    <span class="label-text font-semibold text-xs">{{ __('admin.security_enable_rate_limit') }}</span>
                                </label>
                                <span class="text-[11px] font-mono px-2 py-0.5 rounded {{ $systemServiceRateLimitEnabled ? 'bg-success/10 text-success' : 'bg-base-content/10 text-base-content/70' }}">
                                    {{ $systemServiceRateLimitEnabled ? __('admin.security_rate_limited_active') : __('admin.security_unlimited_active') }}
                                </span>
                            </div>
                            @if ($systemServiceRateLimitEnabled)
                                <div class="grid grid-cols-2 gap-2 pt-1">
                                    <div class="form-control">
                                        <label class="label p-0 pb-1">
                                            <span class="label-text text-xs">{{ __('admin.security_rate_limit_pps') }}</span>
                                        </label>
                                        <input wire:model="systemServiceRateLimit" type="number" min="1" max="1000" class="input input-bordered input-xs font-mono" />
                                    </div>
                                    <div class="form-control">
                                        <label class="label p-0 pb-1">
                                            <span class="label-text text-xs">{{ __('admin.security_rate_burst_packets') }}</span>
                                        </label>
                                        <input wire:model="systemServiceBurst" type="number" min="1" max="1000" class="input input-bordered input-xs font-mono" />
                                    </div>
                                </div>
                            @endif
                            <div class="text-[11px] text-base-content/60 italic pt-1 border-t border-base-200/50">
                                {{ __('admin.security_icmp_dual_stack_note') }}
                            </div>
                        </div>
                    @else
                        {{-- Port Range --}}
                        <div class="form-control">
                            <label class="label justify-start gap-2">
                                <span class="label-text font-medium">{{ __('admin.security_service_port_range') }}</span>
                                <x-tooltip :tip="__('admin.security_service_port_range_help')" align="start" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                </x-tooltip>
                            </label>
                            <input wire:model="systemServicePortRange" type="text"
                                   placeholder="e.g. 5060, 10000-20000"
                                   class="input input-bordered input-sm font-mono @error('systemServicePortRange') input-error @enderror" />
                            @error('systemServicePortRange') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                        </div>

                        {{-- Protocol --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">{{ __('admin.security_service_protocol') }}</span></label>
                            <select wire:model="systemServiceProtocol" class="select select-bordered select-sm w-full">
                                <option value="both">{{ __('admin.security_service_protocol_both') }}</option>
                                <option value="tcp">{{ __('admin.security_service_protocol_tcp') }}</option>
                                <option value="udp">{{ __('admin.security_service_protocol_udp') }}</option>
                            </select>
                            @error('systemServiceProtocol') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    {{-- Source Network Restriction --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_service_source_ip') }}</span>
                            <x-tooltip :tip="__('admin.security_service_source_ip_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                        <input wire:model="systemServiceSourceIp" type="text" placeholder="any or 10.8.0.0/24"
                               class="input input-bordered input-sm font-mono @error('systemServiceSourceIp') input-error @enderror" />
                        @error('systemServiceSourceIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Status Enabled / Disabled --}}
                    <div class="form-control pt-1">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input wire:model="systemServiceEnabled" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="label-text font-medium">{{ __('admin.security_service_enabled') }}</span>
                        </label>
                    </div>

                    <div class="modal-action flex items-center justify-between pt-2">
                        <button wire:click="resetSystemServiceToDefault({{ (int) $editingSystemServiceId }})" type="button"
                                class="btn btn-outline btn-warning btn-sm gap-1">
                            <x-heroicon-o-arrow-path class="w-4 h-4" />
                            <span>{{ __('admin.security_restore_defaults') }}</span>
                        </button>
                        <div class="flex items-center gap-2">
                            <button wire:click="$set('showSystemServiceModal', false)" type="button" class="btn btn-outline btn-sm">
                                {{ __('client.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary btn-sm">
                                {{ __('client.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

