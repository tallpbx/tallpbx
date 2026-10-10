{{-- Firewall Switches: page-global operational state, visible on every tab --}}
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body p-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            {{-- Whole firewall --}}
            <label class="flex items-center gap-2 cursor-pointer"
                   wire:loading.class="opacity-70 pointer-events-none"
                   wire:target="setFirewallEnabled">
                <input wire:click="setFirewallEnabled({{ $firewallEnabled ? 'false' : 'true' }})"
                       wire:loading.attr="disabled"
                       wire:target="setFirewallEnabled"
                       type="checkbox" class="toggle toggle-primary toggle-sm" @checked($firewallEnabled) />
                <span class="text-sm font-medium">{{ __('admin.security_toggle_firewall') }}</span>
                <span wire:loading wire:target="setFirewallEnabled" class="loading loading-spinner loading-xs text-primary"></span>
                <span wire:loading.remove wire:target="setFirewallEnabled">
                    <x-tooltip :tip="__('admin.security_toggle_firewall_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </span>
            </label>

            {{-- Global observe mode --}}
            <label class="flex items-center gap-2 cursor-pointer"
                   wire:loading.class="opacity-70 pointer-events-none"
                   wire:target="setObserveMode">
                <input wire:click="setObserveMode({{ $firewallObserveMode ? 'false' : 'true' }})"
                       wire:loading.attr="disabled"
                       wire:target="setObserveMode"
                       type="checkbox" class="toggle toggle-warning toggle-sm" @checked($firewallObserveMode) />
                <span class="text-sm font-medium">{{ __('admin.security_toggle_observe') }}</span>
                <span wire:loading wire:target="setObserveMode" class="loading loading-spinner loading-xs text-warning"></span>
                <span wire:loading.remove wire:target="setObserveMode">
                    <x-tooltip :tip="__('admin.security_toggle_observe_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </span>
            </label>
        </div>
    </div>
</div>
