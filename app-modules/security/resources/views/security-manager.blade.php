<div class="space-y-6">
    {{-- Header & Top Actions --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ __('admin.security_title') }}</h1>
                <x-tooltip :tip="__('admin.security_description')" align="start" position="right">
                    <x-heroicon-o-shield-check class="w-6 h-6 text-primary cursor-help opacity-70 hover:opacity-100" />
                </x-tooltip>
            </div>
            <p class="text-sm text-base-content/70 mt-1">{{ __('admin.security_description') }}</p>
        </div>
    </div>

    {{-- Feedback Notifications --}}
    @if ($operationalMessage)
        <div class="alert alert-{{ $operationalMessageType === 'error' ? 'error' : ($operationalMessageType === 'warning' ? 'warning' : 'success') }} shadow-sm">
            <span>{{ $operationalMessage }}</span>
        </div>
    @elseif (session('status'))
        <div class="alert alert-success shadow-sm">
            <span>{{ session('status') }}</span>
        </div>
    @elseif (session('error'))
        <div class="alert alert-error shadow-sm">
            <span>{{ session('error') }}</span>
        </div>
    @endif

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
                <div class="mt-2 text-lg font-semibold text-base-content">nftables</div>
                <p class="text-xs text-base-content/60">
                    {{ $firewallDefaultPolicy === 'drop' ? __('admin.security_policy_drop') : __('admin.security_policy_accept') }}
                </p>
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

    {{-- Sequential Pipeline Stages 1 & 2: Blacklist, Attackers, and Whitelist --}}
    <div class="space-y-6">
        {{-- Card 1: Blacklist IPs (Stage 1 Permanent Kernel Drop) --}}
        <div id="blacklist-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_blacklist_ips') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $blacklistCount }}</span>
                            <span class="badge badge-error badge-xs font-mono font-bold">{{ __('admin.security_stage_1_badge') }}</span>
                            <x-tooltip :tip="__('admin.security_blacklist_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                        <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_blacklist_desc') }}</p>
                    </div>
                </div>

                {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
                <div class="flex flex-col lg:flex-row gap-6 items-start">
                    {{-- Left Column: Quick-Add Form & Kernel Rule Helper (Fixed Ergonomic Width) --}}
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

                        <div class="p-3 bg-base-200/40 rounded-box border border-base-200/80 text-xs text-base-content/70 space-y-1">
                            <div class="font-semibold flex items-center gap-1.5 text-error">
                                <x-heroicon-o-shield-exclamation class="w-4 h-4 shrink-0" />
                                <span>{{ __('admin.security_stage_1_badge') }}: Instant Drop</span>
                            </div>
                            <p class="leading-relaxed">{{ __('admin.security_blacklist_helper') }}</p>
                        </div>
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
                                                <button wire:click="deleteIp({{ $item->id }})" type="button"
                                                        class="btn btn-ghost btn-xs text-error p-1"
                                                        title="{{ __('admin.delete') }}">
                                                    <x-heroicon-o-trash class="w-4 h-4" />
                                                </button>
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

        {{-- Card 2: Currently Blocked Attackers (Stage 1 Dynamic Drops) --}}
        <div id="attackers-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_banned_attackers') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $activeBans->count() }}</span>
                            <span class="badge badge-error badge-xs font-mono font-bold">{{ __('admin.security_active_threats_badge') }}</span>
                            <x-tooltip :tip="__('admin.security_threats_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                        <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_banned_attackers_desc') }}</p>
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
                                    </td>
                                    <td>
                                        @php
                                            $vectorLabel = match ($ban->vector) {
                                                'sip_auth' => __('admin.vector_sip'),
                                                'web_auth' => __('admin.vector_web'),
                                                'ssh' => __('admin.vector_ssh'),
                                                default => __('admin.vector_manual'),
                                            };
                                            $vectorBadge = match ($ban->vector) {
                                                'sip_auth' => 'badge-primary',
                                                'web_auth' => 'badge-info',
                                                'ssh' => 'badge-secondary',
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

        {{-- Card 3: Whitelist IPs (Stage 2 Allowed Bypass) --}}
        <div id="whitelist-section" class="card bg-base-100 shadow-sm border border-base-200 scroll-mt-6">
            <div class="card-body p-4 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_whitelist_ips') }}</h2>
                            <span class="badge badge-neutral badge-sm font-mono">{{ $whitelistCount }}</span>
                            <span class="badge badge-success badge-xs font-mono font-bold">{{ __('admin.security_stage_2_badge') }}</span>
                            <x-tooltip :tip="__('admin.security_whitelist_desc')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                        <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_whitelist_desc') }}</p>
                    </div>
                </div>

                {{-- Split-Panel Workbench: Add Form (Left) & Searchable Table (Right) --}}
                <div class="flex flex-col lg:flex-row gap-6 items-start">
                    {{-- Left Column: Quick-Add Form, Self-Whitelisting & Rule Helper (Fixed Ergonomic Width) --}}
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

                        <div class="p-3 bg-base-200/40 rounded-box border border-base-200/80 text-xs text-base-content/70 space-y-1">
                            <div class="font-semibold flex items-center gap-1.5 text-success">
                                <x-heroicon-o-check-badge class="w-4 h-4 shrink-0" />
                                <span>{{ __('admin.security_stage_2_badge') }}: Complete Bypass</span>
                            </div>
                            <p class="leading-relaxed">{{ __('admin.security_whitelist_helper') }}</p>
                        </div>
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
                                                <button wire:click="deleteIp({{ $item->id }})" type="button"
                                                        class="btn btn-ghost btn-xs text-error p-1"
                                                        title="{{ __('admin.delete') }}">
                                                    <x-heroicon-o-trash class="w-4 h-4" />
                                                </button>
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
    </div>

    {{-- Zone 4: Sequential Firewall Rules & Port Access (Lower Deck) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_firewall_rules') }}</h2>
                        <span class="badge badge-primary badge-xs font-mono">{{ __('admin.security_port_rules_badge') }}</span>
                        <x-tooltip :tip="__('admin.security_firewall_rules_tooltip')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_firewall_rules_desc') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Standard PBX Ports Quick Dropdown --}}
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-outline btn-sm gap-1">
                            <x-heroicon-o-sparkles class="w-4 h-4 text-primary" />
                            <span>{{ __('admin.security_quick_add_service') }}</span>
                            <x-heroicon-o-chevron-down class="w-3 h-3 ml-1" />
                        </div>
                        <ul tabindex="0" class="dropdown-content z-20 menu p-2 shadow bg-base-100 rounded-box w-64 border border-base-200 mt-1">
                            @foreach ($catalogServices as $service)
                                <li>
                                    <button wire:click="addServiceFromCatalog({{ $service->id }})" type="button" class="flex justify-between items-center text-xs">
                                        <span class="font-medium">{{ $service->name }}</span>
                                        <span class="font-mono text-base-content/60">{{ $service->port_range }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>

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
                            <th class="w-20">{{ __('admin.security_rule_priority') }}</th>
                            <th class="w-14 text-center">{{ __('client.status') }}</th>
                            <th>{{ __('admin.security_rule_name') }}</th>
                            <th>{{ __('admin.security_service_port') }}</th>
                            <th>{{ __('admin.security_source_ip') }}</th>
                            <th>{{ __('admin.security_action') }}</th>
                            <th class="text-right">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        {{-- SYSTEM, STAGE 1 & 2: Ingress IP Pre-Filters & System Invariants Collapsible Header --}}
                        <tr class="bg-base-200/40 text-xs font-semibold text-base-content/80 cursor-pointer hover:bg-base-200/70 transition-colors select-none"
                            @click="showSystemPreFilters = !showSystemPreFilters"
                            title="{{ __('admin.security_toggle_invariants_tooltip') }}">
                            <td colspan="7" class="py-2.5 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="badge badge-neutral badge-sm font-mono font-bold">SYSTEM + STAGES 1 & 2</span>
                                        <span class="uppercase tracking-wider text-xs font-bold">{{ __('admin.security_system_invariants_prefilters') }}</span>
                                        <span class="badge badge-ghost badge-sm text-xs font-normal">
                                            {{ __('admin.security_invariants_rules_count', ['count' => 6]) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1.5 text-xs font-medium text-primary">
                                        <span x-text="showSystemPreFilters ? '{{ __('admin.security_hide_rules') }}' : '{{ __('admin.security_show_rules') }}'"></span>
                                        <x-heroicon-s-chevron-down class="w-4 h-4 transition-transform duration-200" ::class="showSystemPreFilters ? 'rotate-180' : ''" />
                                    </div>
                                </div>
                            </td>
                        </tr>

                        {{-- Base Invariant: Unconditional Loopback Interface --}}
                        <tr class="hover bg-base-200/5" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-neutral badge-sm font-mono font-semibold">{{ __('admin.security_system_badge') }}</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-success" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_rule_loopback') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">iif "lo"</span>
                                    <x-tooltip :tip="__('admin.security_loopback_tooltip')" align="start" position="right">
                                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                    </x-tooltip>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm text-base-content/70">127.0.0.1/8, ::1</span>
                                <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                            </td>
                            <td>
                                <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <span class="badge badge-ghost badge-sm text-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                            </td>
                        </tr>

                        {{-- Stage 1: Permanent Blacklist --}}
                        <tr class="hover" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-error badge-sm font-mono font-semibold">STAGE 1</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-error" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_permanent_blacklist') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">@blacklist_ips</span>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm {{ $blacklistCount > 0 ? 'text-error font-semibold' : 'text-base-content/60' }}">
                                    {{ $blacklistCount }} {{ trans_choice('admin.security_entries_count', $blacklistCount) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="#blacklist-section" class="btn btn-ghost btn-xs text-error gap-1">
                                    <x-heroicon-o-arrow-up class="w-3.5 h-3.5" />
                                    <span>{{ __('admin.security_manage_blacklist') }}</span>
                                </a>
                            </td>
                        </tr>

                        {{-- Stage 1: Active Intrusion Bans --}}
                        <tr class="hover" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-error badge-sm font-mono font-semibold">STAGE 1</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-error {{ $bannedCount > 0 ? 'animate-pulse' : '' }}" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_active_attackers') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">@banned_ips</span>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm {{ $bannedCount > 0 ? 'text-error font-semibold' : 'text-base-content/60' }}">
                                    {{ $bannedCount }} {{ trans_choice('admin.security_threats_count', $bannedCount) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="#attackers-section" class="btn btn-ghost btn-xs text-error gap-1">
                                    <x-heroicon-o-arrow-up class="w-3.5 h-3.5" />
                                    <span>{{ __('admin.security_view_threats') }}</span>
                                </a>
                            </td>
                        </tr>

                        {{-- Base Invariant: Stateful Connection Tracking (Return Fastpath) --}}
                        <tr class="hover bg-base-200/5" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-neutral badge-sm font-mono font-semibold">{{ __('admin.security_system_badge') }}</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-success" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_rule_conntrack') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">ct state established,related</span>
                                    <x-tooltip :tip="__('admin.security_conntrack_tooltip')" align="start" position="right">
                                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                    </x-tooltip>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm text-base-content/70">{{ __('admin.security_source_anywhere') }}</span>
                                <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                            </td>
                            <td>
                                <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <span class="badge badge-ghost badge-sm text-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                            </td>
                        </tr>

                        {{-- Base Invariant: Invalid Packets Defense --}}
                        <tr class="hover bg-base-200/5" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-neutral badge-sm font-mono font-semibold">{{ __('admin.security_system_badge') }}</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-error" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_rule_invalid_packets') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">ct state invalid</span>
                                    <x-tooltip :tip="__('admin.security_invalid_tooltip')" align="start" position="right">
                                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                    </x-tooltip>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm text-base-content/70">{{ __('admin.security_source_anywhere') }}</span>
                                <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                            </td>
                            <td>
                                <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <span class="badge badge-ghost badge-sm text-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                            </td>
                        </tr>

                        {{-- Stage 2: Trusted Whitelist --}}
                        <tr class="hover" x-show="showSystemPreFilters" x-cloak>
                            <td class="whitespace-nowrap">
                                <span class="badge badge-success badge-sm font-mono font-semibold">STAGE 2</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-success" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_trusted_whitelist') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono">@whitelist_ips</span>
                                </div>
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ __('admin.security_all_ports_protocols') }}
                            </td>
                            <td>
                                <span class="font-mono text-sm {{ $whitelistCount > 0 ? 'text-success font-semibold' : 'text-base-content/60' }}">
                                    {{ $whitelistCount }} {{ trans_choice('admin.security_entries_count', $whitelistCount) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="#whitelist-section" class="btn btn-ghost btn-xs text-success gap-1">
                                    <x-heroicon-o-arrow-up class="w-3.5 h-3.5" />
                                    <span>{{ __('admin.security_manage_whitelist') }}</span>
                                </a>
                            </td>
                        </tr>

                        {{-- STAGE 3: Core PBX Telephony & Management Services Header --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="badge badge-neutral badge-outline badge-sm font-mono font-bold">{{ __('admin.security_stage_3_badge') }}</span>
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_core_services_title') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_core_services_note') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- Core PBX Services Rows --}}
                        @foreach ($catalogServices as $service)
                            <tr class="hover {{ ! $service->enabled ? 'opacity-50' : '' }}">
                                <td class="whitespace-nowrap">
                                    <span class="badge badge-neutral badge-sm font-mono font-semibold">STAGE 3</span>
                                </td>
                                <td class="text-center">
                                    <input wire:click="toggleSystemService({{ $service->id }})" type="checkbox"
                                           class="toggle toggle-success toggle-sm"
                                           @checked($service->enabled)
                                           title="{{ $service->enabled ? __('client.enabled') : __('client.disabled') }}" />
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5 font-medium text-base-content">
                                        <span>{{ $service->name }}</span>
                                        @if ($service->protocol === 'icmp')
                                            <span class="badge badge-neutral badge-sm font-mono">{{ __('admin.security_system_badge') }}</span>
                                        @else
                                            <span class="badge badge-ghost badge-sm">{{ __('admin.security_core_service_badge') }}</span>
                                        @endif
                                        @if ($service->description)
                                            <x-tooltip :tip="$service->description" align="start" position="right">
                                                <x-heroicon-o-information-circle class="w-3.5 h-3.5 text-base-content/50 cursor-help" />
                                            </x-tooltip>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if ($service->protocol === 'icmp')
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="font-mono text-sm font-semibold text-base-content">echo-request</span>
                                            <span class="text-sm text-base-content/60">/ICMP</span>
                                            @if ($service->rate_limit)
                                                <span class="badge badge-neutral badge-xs font-mono ml-1">{{ $service->rate_limit }}/s limit (burst {{ $service->burst ?? $service->rate_limit }})</span>
                                            @else
                                                <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_rate_limit_unlimited') }}</span>
                                            @endif
                                            <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono text-sm font-semibold text-base-content">{{ $service->port_range }}</span>
                                            <span class="text-sm text-base-content/60">/{{ strtoupper($service->protocol) }}</span>
                                        </div>
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
                                    <button wire:click="openEditSystemServiceModal({{ $service->id }})" type="button" class="btn btn-ghost btn-xs text-primary gap-1" title="{{ __('admin.security_edit_service') }}">
                                        <x-heroicon-o-pencil-square class="w-4 h-4" />
                                        <span class="hidden sm:inline">{{ __('client.edit') }}</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach

                        {{-- STAGE 4: Custom Sequential Rules Header --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="badge badge-primary badge-outline badge-sm font-mono font-bold">{{ __('admin.security_stage_4_badge') }}</span>
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_firewall_rules') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step4_desc') }}</span>
                                </div>
                            </td>
                        </tr>

                        {{-- Custom Sequential Rules Rows --}}
                        @forelse ($firewallRules as $rule)
                            <tr class="hover {{ ! $rule->enabled ? 'opacity-50' : '' }}">
                                {{-- Priority & Up/Down Arrows --}}
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-1 font-mono text-sm">
                                        <div class="flex flex-col">
                                            <button wire:click="moveRuleUp({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                                <x-heroicon-s-chevron-up class="w-3 h-3" />
                                            </button>
                                            <button wire:click="moveRuleDown({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                                <x-heroicon-s-chevron-down class="w-3 h-3" />
                                            </button>
                                        </div>
                                        <span>{{ $rule->sequence }}</span>
                                    </div>
                                </td>

                                {{-- Status Toggle --}}
                                <td class="text-center">
                                    <input wire:click="toggleRule({{ $rule->id }})" type="checkbox"
                                           class="toggle toggle-primary toggle-sm"
                                           @checked($rule->enabled) />
                                </td>

                                {{-- Rule Name / Description --}}
                                <td class="font-medium text-base-content">{{ $rule->description }}</td>

                                {{-- Service / Port --}}
                                <td>
                                    @if ($rule->service)
                                        <div class="flex items-center gap-1.5">
                                            <span class="badge badge-neutral badge-sm">{{ $rule->service->name }}</span>
                                            <span class="font-mono text-sm text-base-content/70">{{ $rule->service->port_range }}/{{ strtoupper($rule->service->protocol) }}</span>
                                        </div>
                                    @else
                                        <span class="font-mono text-sm">{{ $rule->custom_port }}/{{ strtoupper((string) $rule->custom_protocol) }}</span>
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

                                {{-- Actions --}}
                                <td class="text-right whitespace-nowrap">
                                    <button wire:click="openCustomRuleModal({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </button>
                                    <button wire:click="deleteRule({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
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

                        {{-- STAGE 5: Default Inbound Fallback Policy --}}
                        <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                            <td colspan="7" class="py-2 px-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="badge badge-neutral badge-outline badge-sm font-mono font-bold">{{ __('admin.security_stage_5_badge') }}</span>
                                        <span class="uppercase tracking-wider text-xs">{{ __('admin.security_default_policy') }}</span>
                                    </div>
                                    <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step5_desc') }}</span>
                                </div>
                            </td>
                        </tr>
                        <tr class="hover">
                            <td class="whitespace-nowrap">
                                <span class="badge badge-neutral badge-sm font-mono font-semibold">STAGE 5</span>
                            </td>
                            <td class="text-center">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-base-content/40" title="Active"></span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span>{{ __('admin.security_default_policy') }}</span>
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.security_unmatched_traffic') }}</span>
                                </div>
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
                                <button wire:click="openSettingsDrawer" type="button" class="btn btn-ghost btn-xs gap-1 text-base-content/70">
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
                    {{-- Default Firewall Policy --}}
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">{{ __('admin.security_firewall_status') }} (Default Policy)</span>
                        </label>
                        <select wire:model="firewallDefaultPolicy" class="select select-bordered select-sm w-full">
                            <option value="drop">{{ __('admin.security_policy_drop') }} (Drop)</option>
                            <option value="accept">{{ __('admin.security_policy_accept') }} (Accept)</option>
                        </select>
                    </div>

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

    {{-- Modal: Manual Ban --}}
    @if ($showManualBanModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_manual_ban_title') }}</h3>
                <form wire:submit="manualBan" class="space-y-4 mt-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">{{ __('admin.security_attacker_ip') }}</span></label>
                        <input wire:model="manualBanIp" type="text" placeholder="e.g. 198.51.100.42"
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

