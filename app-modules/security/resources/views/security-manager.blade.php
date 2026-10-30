<div class="space-y-6">
    {{-- Header & Top Actions --}}
    <x-page-header :title="__('admin.security_title')" :tooltip="__('admin.security_description')" />

    {{-- Transient feedback toast (shared standard component — see tallpbx-custom skill) --}}
    <x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />

    {{-- Cockpit Alert Banners (Lockout Warning, Drift Warning, Whole-Firewall-Off, Observe Mode, Pre-Filters Disabled) --}}
    @include('security::partials.cockpit-banners')

    {{-- Zone 1: System Status Overview Cards --}}
    @include('security::partials.status-overview-cards')

    {{-- Firewall Switches: page-global operational state, visible on every tab --}}
    @include('security::partials.firewall-switches')

    {{-- Security Center Tabs: Firewall Rules first as master cockpit, followed by address registries --}}
    @if ($firewallEnabled)
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
                <button role="tab" type="button" wire:click="$set('activeTab', 'geo-blocking')"
                        class="tab gap-1.5 {{ $activeTab === 'geo-blocking' ? 'tab-active' : '' }}">
                    <x-heroicon-o-globe-americas class="w-4 h-4" />
                    <span>{{ __('admin.security_tab_geo_blocking') }}</span>
                </button>
            </div>
        </div>

        {{-- Tab Panels --}}
        @if ($activeTab === 'firewall-rules')
            <div wire:key="security-tab-firewall-rules">
                @include('security::tabs.firewall-rules')
            </div>
        @elseif ($activeTab === 'block-allow')
            <div wire:key="security-tab-block-allow">
                @include('security::tabs.block-allow')
            </div>
        @elseif ($activeTab === 'attackers')
            <div wire:key="security-tab-attackers">
                @include('security::tabs.attackers')
            </div>
        @elseif (in_array($activeTab, ['external-blocklists', 'threat-feeds'], true))
            <div wire:key="security-tab-external-blocklists">
                @include('security::tabs.external-blocklists')
            </div>
        @elseif ($activeTab === 'geo-blocking')
            <div wire:key="security-tab-geo-blocking">
                @include('security::tabs.geo-blocking')
            </div>
        @endif
    @endif

    {{-- Drawers & Modals --}}
    <div wire:key="security-modal-protection-settings">
        @include('security::modals.protection-settings')
    </div>
    <div wire:key="security-modal-confirm-disable-prefilter">
        @include('security::modals.confirm-disable-prefilter')
    </div>
    <div wire:key="security-modal-default-policy">
        @include('security::modals.default-policy')
    </div>
    <div wire:key="security-modal-manual-ban">
        @include('security::modals.manual-ban')
    </div>
    <div wire:key="security-modal-custom-rule">
        @include('security::modals.custom-rule')
    </div>
    <div wire:key="security-modal-edit-system-service">
        @include('security::modals.edit-system-service')
    </div>
    <div wire:key="security-drawer-observe-activity">
        @include('security::drawers.observe-activity')
    </div>

    {{-- Confirmation Modals --}}
    <x-confirmation-modal
        :open="$confirmingClearGeoRules"
        :title="__('admin.security_geo_clear_rules')"
        :message="__('admin.security_geo_clear_confirm')"
        :confirm-label="__('admin.security_geo_clear_rules')"
        confirm-action="clearGeoRules"
        cancel-action="cancelClearGeoRules"
    />

    <x-confirmation-modal
        :open="$confirmingResetPreFilterOrder"
        :title="__('admin.security_prefilter_reset')"
        :message="__('admin.security_prefilter_reset_confirm')"
        :confirm-label="__('admin.security_prefilter_reset')"
        confirm-action="resetPreFilterOrder"
        cancel-action="cancelResetPreFilterOrder"
    />

    <x-confirmation-modal
        :open="$confirmingRemoveAllFeedBlocks"
        :title="__('admin.security_threat_feed_remove_blocks')"
        :message="__('admin.security_threat_feed_remove_blocks_confirm')"
        :confirm-label="__('admin.security_threat_feed_remove_blocks')"
        confirm-action="removeAllFeedBlocks"
        cancel-action="cancelRemoveAllFeedBlocks"
    />
</div>
