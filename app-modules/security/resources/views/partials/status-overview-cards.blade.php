{{-- Zone 1: System Status Overview Cards --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    {{-- Firewall Status --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <div class="flex items-start justify-between gap-2">
                <span class="text-sm font-medium text-base-content/70 min-w-0">{{ __('admin.security_firewall_status') }}</span>
                @if ($firewallEnabled)
                    <span class="badge badge-success badge-sm gap-1 shrink-0">
                        <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                        {{ __('admin.active') }}
                    </span>
                @else
                    <span class="badge badge-neutral badge-sm shrink-0">{{ __('admin.security_firewall_disabled') }}</span>
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
            <div class="flex items-start justify-between gap-2">
                <span class="text-sm font-medium text-base-content/70 min-w-0">{{ __('admin.security_attack_protection') }}</span>
                @if ($attackProtectionEnabled)
                    <span class="badge badge-success badge-sm gap-1 shrink-0">
                        <span class="inline-block w-2 h-2 rounded-full bg-success-content"></span>
                        {{ __('admin.active') }}
                    </span>
                @else
                    <span class="badge badge-neutral badge-sm shrink-0">{{ __('admin.security_firewall_disabled') }}</span>
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
            <div class="flex items-start justify-between gap-2">
                <span class="text-sm font-medium text-base-content/70 min-w-0">{{ __('admin.security_blocked_attackers') }}</span>
                <span class="badge badge-neutral badge-sm shrink-0">{{ $bannedCount }}</span>
            </div>
            <div class="mt-2 text-lg font-semibold text-base-content">{{ $bannedCount }} {{ __('admin.security_active_bans') }}</div>
            <p class="text-xs text-base-content/60">{{ __('admin.security_bans_help') }}</p>
        </div>
    </div>

    {{-- Administrator Connection (Lockout Guard) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <div class="flex items-start justify-between gap-2">
                <span class="text-sm font-medium text-base-content/70 min-w-0">{{ __('admin.security_your_connection') }}</span>
                @if ($isCurrentIpWhitelisted)
                    <span class="badge badge-success badge-sm shrink-0">{{ __('admin.security_protected') }}</span>
                @else
                    <span class="badge badge-warning badge-sm shrink-0">{{ __('admin.security_unprotected') }}</span>
                @endif
            </div>
            <div class="mt-2 text-lg font-semibold font-mono text-base-content">{{ $adminIp }}</div>
            <div class="mt-1">
                @if ($isCurrentIpWhitelisted)
                    <p class="text-xs text-success">{{ __('admin.security_lockout_help') }}</p>
                @else
                    <button wire:click="whitelistCurrentIp"
                            wire:loading.attr="disabled"
                            wire:target="whitelistCurrentIp"
                            type="button"
                            class="btn btn-warning btn-xs gap-1 mt-1">
                        <span wire:loading.remove wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1">
                            <x-heroicon-o-shield-check class="w-3 h-3" />
                            <span>{{ __('admin.security_protect_my_ip') }}</span>
                        </span>
                        <span wire:loading wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.security_protecting_my_ip') }}</span>
                        </span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
