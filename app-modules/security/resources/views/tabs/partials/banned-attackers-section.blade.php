{{-- Card: Currently Blocked Attackers (dynamic kernel drops) --}}
<div id="attackers-section" class="card bg-base-100 shadow-sm border {{ ! $prefilterEnabled ? 'border-warning/30' : 'border-base-200' }} scroll-mt-6">
    <div class="card-body p-4 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_banned_attackers') }}</h2>
                    <span class="badge {{ $attackProtectionEnabled ? 'badge-success' : 'badge-neutral' }} badge-sm font-semibold">
                        {{ $attackProtectionEnabled ? __('admin.security_threat_feed_status_active') : __('admin.security_threat_feed_status_disabled') }}
                    </span>
                    @if ($attackProtectionEnabled)
                        <span class="badge badge-neutral badge-sm font-mono">{{ $this->totalActiveBans }}</span>
                    @else
                        <span class="badge badge-neutral badge-sm font-mono">0</span>
                        @if ($this->totalActiveBans > 0)
                            <span class="text-xs text-base-content/60">({{ __('admin.security_in_list_note', ['count' => $this->totalActiveBans]) }})</span>
                        @endif
                    @endif
                    <span class="badge badge-ghost badge-sm font-mono opacity-75" title="@banned_ips">@banned_ips</span>
                    @if (! $prefilterEnabled)
                        <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                    @endif
                    <x-tooltip :tip="__('admin.security_threats_desc')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </div>
                <p class="text-xs text-base-content/60 mt-1 flex items-center gap-1.5 flex-wrap">
                    <span>{{ __('admin.security_banned_attackers_cli_hint') }}</span>
                    <code class="px-1.5 py-0.5 rounded bg-base-200 text-xs font-mono select-all text-base-content">sudo nft list set inet tallpbx_filter banned_ips</code>
                </p>
            </div>
            <div class="flex flex-col items-end gap-2 self-start sm:self-auto">
                <label class="label cursor-pointer justify-start gap-2 p-0"
                       wire:loading.class="opacity-70 pointer-events-none"
                       wire:target="setAttackProtectionEnabled">
                    <input wire:click="setAttackProtectionEnabled({{ $attackProtectionEnabled ? 'false' : 'true' }})"
                           wire:loading.attr="disabled"
                           wire:target="setAttackProtectionEnabled"
                           type="checkbox" class="toggle toggle-success toggle-sm" @checked($attackProtectionEnabled) />
                    <span wire:loading.remove wire:target="setAttackProtectionEnabled" class="label-text font-medium text-xs sm:text-sm">
                        {{ $attackProtectionEnabled ? __('admin.enabled') : __('admin.disabled') }}
                    </span>
                    <span wire:loading wire:target="setAttackProtectionEnabled" class="inline-flex items-center gap-1.5 label-text font-medium text-xs sm:text-sm text-success">
                        <span class="loading loading-spinner loading-xs"></span>
                        <span>{{ $attackProtectionEnabled ? __('admin.security_attack_protection_disabling') : __('admin.security_attack_protection_enabling') }}</span>
                    </span>
                </label>
                <div class="flex flex-wrap items-center justify-end gap-2">
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
                    @forelse ($this->bans as $ban)
                        <tr class="hover"
                            wire:key="active-ban-{{ $ban->ip_address }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="unban('{{ $ban->ip_address }}'), promoteToWhitelist('{{ $ban->ip_address }}'), promoteToBlacklist('{{ $ban->ip_address }}')">
                            <td class="font-mono font-medium text-base-content/70">
                                {{ $ban->ip_address }}
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
                                            wire:loading.attr="disabled"
                                            wire:target="unban('{{ $ban->ip_address }}')"
                                            class="btn btn-outline btn-xs join-item"
                                            title="{{ __('admin.security_unblock') }}">
                                        <span wire:loading wire:target="unban('{{ $ban->ip_address }}')" class="loading loading-spinner loading-xs"></span>
                                        <span wire:loading.remove wire:target="unban('{{ $ban->ip_address }}')">{{ __('admin.security_unblock') }}</span>
                                    </button>
                                    <button wire:click="promoteToWhitelist('{{ $ban->ip_address }}')" type="button"
                                            wire:loading.attr="disabled"
                                            wire:target="promoteToWhitelist('{{ $ban->ip_address }}')"
                                            class="btn btn-success btn-xs join-item"
                                            title="{{ __('admin.security_trust_ip') }}">
                                        <span wire:loading wire:target="promoteToWhitelist('{{ $ban->ip_address }}')" class="loading loading-spinner loading-xs"></span>
                                        <span wire:loading.remove wire:target="promoteToWhitelist('{{ $ban->ip_address }}')">{{ __('admin.security_trust_ip') }}</span>
                                    </button>
                                    <button wire:click="promoteToBlacklist('{{ $ban->ip_address }}')" type="button"
                                            wire:loading.attr="disabled"
                                            wire:target="promoteToBlacklist('{{ $ban->ip_address }}')"
                                            class="btn btn-error btn-xs join-item"
                                            title="{{ __('admin.security_block_permanently') }}">
                                        <span wire:loading wire:target="promoteToBlacklist('{{ $ban->ip_address }}')" class="loading loading-spinner loading-xs"></span>
                                        <span wire:loading.remove wire:target="promoteToBlacklist('{{ $ban->ip_address }}')">{{ __('admin.security_block_permanently') }}</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-6 text-base-content/60">
                                <x-heroicon-o-shield-check class="w-6 h-6 mx-auto text-success/60 mb-1.5" style="width: 1.5rem; height: 1.5rem;" />
                                <div class="text-sm font-medium">{{ __('admin.security_no_attackers') }}</div>
                                <div class="text-xs text-base-content/50 mt-1">{{ __('admin.security_no_attackers_help') }}</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->totalActiveBans > 100)
            <div class="p-2.5 rounded-box bg-base-200/50 border border-base-200 text-xs text-base-content/70 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5">
                <span>{{ __('admin.security_showing_recent_bans', ['limit' => 100, 'total' => number_format($this->totalActiveBans)]) }}</span>
                <span class="font-mono text-xs opacity-75">sudo nft list set inet tallpbx_filter banned_ips</span>
            </div>
        @endif
    </div>
</div>

