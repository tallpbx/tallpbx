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

    {{-- Cockpit Alert Banners (Lockout Warning, Drift Warning, Whole-Firewall-Off, Observe Mode, Pre-Filters Disabled) --}}
    @include('security::partials.cockpit-banners')

    {{-- Zone 1: System Status Overview Cards --}}
    @include('security::partials.status-overview-cards')

    {{-- Firewall Switches: page-global operational state, visible on every tab --}}
    @include('security::partials.firewall-switches')

    {{-- Security Center Tabs: Firewall Rules first as master cockpit, followed by address registries --}}
    <div class="overflow-x-auto">
        <div role="tablist" class="tabs tabs-lift tabs-sm">
            <button role="tab" type="button" wire:click="$set('activeTab', 'firewall-rules')"
                    class="tab gap-1.5 {{ $activeTab === 'firewall-rules' ? 'tab-active' : '' }}">
                <x-heroicon-o-fire class="w-4 h-4" />
                {{ __('admin.security_tab_firewall_rules') }}
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'block-allow')"
                    class="tab gap-1.5 {{ $activeTab === 'block-allow' ? 'tab-active' : '' }}">
                <x-heroicon-o-no-symbol class="w-4 h-4" />
                <span>{{ __('admin.security_tab_block_allow') }}</span>
                @if (! $prefilterEnabled)
                    <x-tooltip :tip="__('admin.security_tab_prefilter_bypassed_tooltip')" position="bottom">
                        <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                    </x-tooltip>
                @endif
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'attackers')"
                    class="tab gap-1.5 {{ $activeTab === 'attackers' ? 'tab-active' : '' }}">
                <x-heroicon-o-shield-exclamation class="w-4 h-4" />
                <span>{{ __('admin.security_tab_attackers') }}</span>
                @if (! $prefilterEnabled)
                    <x-tooltip :tip="__('admin.security_tab_prefilter_bypassed_tooltip')" position="bottom">
                        <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                    </x-tooltip>
                @endif
            </button>
            <button role="tab" type="button" wire:click="$set('activeTab', 'external-blocklists')"
                    class="tab gap-1.5 {{ in_array($activeTab, ['external-blocklists', 'threat-feeds'], true) ? 'tab-active' : '' }}">
                <x-heroicon-o-globe-alt class="w-4 h-4" />
                <span>{{ __('admin.security_tab_external_blocklists') }}</span>
                @if (! $prefilterEnabled)
                    <x-tooltip :tip="__('admin.security_tab_prefilter_bypassed_tooltip')" position="bottom">
                        <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                    </x-tooltip>
                @endif
            </button>
        </div>
    </div>

    {{-- Tab Panels --}}
    @if ($activeTab === 'firewall-rules')
        @include('security::tabs.firewall-rules')
    @elseif ($activeTab === 'block-allow')
        @include('security::tabs.block-allow')
    @elseif ($activeTab === 'attackers')
        @include('security::tabs.attackers')
    @elseif (in_array($activeTab, ['external-blocklists', 'threat-feeds'], true))
        @include('security::tabs.external-blocklists')
    @endif

    {{-- Drawers & Modals --}}
    @include('security::drawers.protection-settings')
    @include('security::modals.confirm-disable-prefilter')
    @include('security::modals.default-policy')
    @include('security::modals.manual-ban')
    @include('security::modals.custom-rule')
    @include('security::modals.edit-system-service')
    @include('security::drawers.observe-activity')
</div>
