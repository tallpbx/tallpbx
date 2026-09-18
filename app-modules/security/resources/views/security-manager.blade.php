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
        <div class="flex items-center gap-2">
            <button wire:click="openSettingsDrawer" type="button" class="btn btn-neutral btn-sm gap-2">
                <x-heroicon-o-cog-6-tooth class="w-4 h-4" />
                <span>{{ __('admin.security_settings') }}</span>
            </button>
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

    {{-- Middle Section: Left Deck & Right Deck --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Left Deck: Trusted & Blocked IP Addresses (5 cols) --}}
        <div class="lg:col-span-5 space-y-4">
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body p-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_ip_management') }}</h2>
                            <x-tooltip :tip="__('admin.security_protect_ip_help')" align="start" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </div>
                    </div>

                    {{-- Segmented Tabs: Whitelist vs Blacklist --}}
                    <div class="join grid grid-cols-2 w-full">
                        <button wire:click="switchIpListType('whitelist')" type="button"
                                class="btn btn-sm join-item {{ $ipListType === 'whitelist' ? 'btn-primary' : 'btn-outline' }}">
                            <x-heroicon-o-check-circle class="w-4 h-4" />
                            <span>{{ __('admin.security_trusted_ips') }}</span>
                        </button>
                        <button wire:click="switchIpListType('blacklist')" type="button"
                                class="btn btn-sm join-item {{ $ipListType === 'blacklist' ? 'btn-error' : 'btn-outline' }}">
                            <x-heroicon-o-no-symbol class="w-4 h-4" />
                            <span>{{ __('admin.security_blocked_ips') }}</span>
                        </button>
                    </div>

                    {{-- Quick-Add Form --}}
                    <form wire:submit="addIp" class="space-y-2 bg-base-200/50 p-3 rounded-box">
                        <div class="text-xs font-semibold text-base-content/70">
                            {{ $ipListType === 'whitelist' ? __('admin.security_add_to_trusted') : __('admin.security_add_to_blocked') }}
                        </div>
                        <div class="flex flex-col gap-2">
                            <input wire:model="newIp" type="text"
                                   placeholder="{{ __('admin.security_ip_or_cidr') }}"
                                   class="input input-bordered input-sm w-full font-mono @error('newIp') input-error @enderror" />
                            @error('newIp')
                                <span class="text-error text-xs">{{ $message }}</span>
                            @enderror
                            <input wire:model="newIpDescription" type="text"
                                   placeholder="{{ __('admin.security_ip_description') }}"
                                   class="input input-bordered input-sm w-full" />
                            <button type="submit" class="btn {{ $ipListType === 'whitelist' ? 'btn-primary' : 'btn-error' }} btn-sm w-full">
                                <x-heroicon-o-plus class="w-4 h-4" />
                                <span>{{ $ipListType === 'whitelist' ? __('admin.security_add_to_trusted') : __('admin.security_add_to_blocked') }}</span>
                            </button>
                        </div>
                    </form>

                    {{-- Search Filter --}}
                    <div class="relative">
                        <input wire:model.live.debounce.250ms="ipSearch" type="text"
                               placeholder="{{ __('admin.security_search_ips') }}"
                               class="input input-bordered input-sm w-full pl-9" />
                        <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-2.5 text-base-content/40" />
                    </div>

                    {{-- IP Table / List --}}
                    <div class="overflow-x-auto max-h-80 overflow-y-auto border border-base-200 rounded-box">
                        <table class="table table-xs table-pin-rows">
                            <thead>
                                <tr>
                                    <th>{{ __('admin.security_ip_or_cidr') }}</th>
                                    <th>{{ __('admin.description') }}</th>
                                    <th class="text-right">{{ __('admin.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($ipLists as $item)
                                    <tr class="hover">
                                        <td class="font-mono font-medium">
                                            {{ $item->ip_address }}
                                            @if ($item->ip_address === $adminIp)
                                                <span class="badge badge-success badge-xs ml-1">You</span>
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
                                            {{ __('admin.security_no_ips_found') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Deck: Currently Blocked Attackers (7 cols) --}}
        <div class="lg:col-span-7 space-y-4">
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body p-4 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_banned_attackers') }}</h2>
                                <span class="badge badge-neutral badge-sm">{{ $activeBans->count() }}</span>
                            </div>
                            <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_banned_attackers_desc') }}</p>
                        </div>
                        <button wire:click="openManualBanModal" type="button" class="btn btn-outline btn-sm gap-1 self-start sm:self-auto">
                            <x-heroicon-o-no-symbol class="w-4 h-4 text-error" />
                            <span>{{ __('admin.security_block_manually') }}</span>
                        </button>
                    </div>

                    {{-- Threat Table --}}
                    <div class="overflow-x-auto max-h-96 overflow-y-auto border border-base-200 rounded-box">
                        <table class="table table-xs table-pin-rows">
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
                                            <span class="badge {{ $vectorBadge }} badge-xs">{{ $vectorLabel }}</span>
                                        </td>
                                        <td>{{ $ban->attempt_count }}</td>
                                        <td class="text-base-content/70">
                                            @if ($ban->expires_at)
                                                {{ $ban->expires_at->diffForHumans() }}
                                            @else
                                                <span class="badge badge-ghost badge-xs">Permanent</span>
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
                                        <td colspan="5" class="text-center py-10 text-base-content/60">
                                            <x-heroicon-o-shield-check class="w-10 h-10 mx-auto text-success/60 mb-2" />
                                            <div>{{ __('admin.security_no_attackers') }}</div>
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

    {{-- Zone 4: Sequential Firewall Rules & Port Access (Lower Deck) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_firewall_rules') }}</h2>
                        <x-tooltip :tip="__('admin.security_firewall_rules_desc')" align="start" position="right">
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

            {{-- Rules Table --}}
            <div class="overflow-x-auto border border-base-200 rounded-box">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th class="w-16">{{ __('admin.security_rule_priority') }}</th>
                            <th class="w-12">{{ __('client.status') }}</th>
                            <th>{{ __('admin.security_rule_name') }}</th>
                            <th>{{ __('admin.security_service_port') }}</th>
                            <th>{{ __('admin.security_source_ip') }}</th>
                            <th>{{ __('admin.security_action') }}</th>
                            <th class="text-right">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($firewallRules as $rule)
                            <tr class="hover {{ ! $rule->enabled ? 'opacity-50' : '' }}">
                                {{-- Priority & Up/Down Arrows --}}
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-1 font-mono text-xs">
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
                                <td>
                                    <input wire:click="toggleRule({{ $rule->id }})" type="checkbox"
                                           class="toggle toggle-primary toggle-sm"
                                           {{ $rule->enabled ? 'checked' : '' }} />
                                </td>

                                {{-- Rule Name / Description --}}
                                <td class="font-medium text-base-content">{{ $rule->description }}</td>

                                {{-- Service / Port --}}
                                <td>
                                    @if ($rule->service)
                                        <div class="flex items-center gap-1.5">
                                            <span class="badge badge-neutral badge-xs">{{ $rule->service->name }}</span>
                                            <span class="font-mono text-xs text-base-content/70">{{ $rule->service->port_range }}/{{ strtoupper($rule->service->protocol) }}</span>
                                        </div>
                                    @else
                                        <span class="font-mono text-xs">{{ $rule->custom_port }}/{{ strtoupper((string) $rule->custom_protocol) }}</span>
                                    @endif
                                </td>

                                {{-- Source Network --}}
                                <td>
                                    @if ($rule->source_ip === 'any' || $rule->source_ip === '0.0.0.0/0')
                                        <span class="badge badge-ghost badge-xs">{{ __('admin.security_source_anywhere') }}</span>
                                    @else
                                        <span class="font-mono text-xs">{{ $rule->source_ip }}</span>
                                    @endif
                                </td>

                                {{-- Action Badge --}}
                                <td>
                                    @if ($rule->action === 'accept')
                                        <span class="badge badge-success badge-xs">{{ __('admin.security_action_allow') }}</span>
                                    @else
                                        <span class="badge badge-error badge-xs">{{ __('admin.security_action_block') }}</span>
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
                                <td colspan="7" class="text-center py-6 text-base-content/60">
                                    {{ __('admin.security_no_rules') }}
                                </td>
                            </tr>
                        @endforelse
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
</div>

