<div class="space-y-6">
    @if (! $prefilterEnabled)
        <div class="alert alert-warning/15 border border-warning/30 text-base-content rounded-box flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5" role="alert">
            <div class="flex items-center gap-2.5">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning shrink-0" />
                <div class="text-xs">
                    <span class="font-semibold text-warning">{{ __('admin.security_prefilter_bypassed_notice_title') }}:</span>
                    <span class="opacity-90">{{ __('admin.security_prefilter_bypassed_attackers_notice') }}</span>
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

    {{-- Card: Currently Blocked Attackers (dynamic kernel drops) --}}
    <div id="attackers-section" class="card bg-base-100 shadow-sm border {{ ! $prefilterEnabled ? 'border-warning/30' : 'border-base-200' }} scroll-mt-6">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_banned_attackers') }}</h2>
                        <span class="badge badge-neutral badge-sm font-mono">{{ $activeBans->count() }}</span>
                        @if (! $prefilterEnabled)
                            <span class="badge badge-warning badge-xs font-semibold">{{ __('admin.security_bypassed_badge') }}</span>
                        @endif
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
                            <tr class="hover"
                                wire:key="active-ban-{{ $ban->ip_address }}"
                                wire:loading.class="opacity-40 pointer-events-none"
                                wire:target="unban('{{ $ban->ip_address }}'), promoteToWhitelist('{{ $ban->ip_address }}'), promoteToBlacklist('{{ $ban->ip_address }}')">
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
                                    <tr class="hover"
                                        wire:key="scanner-incident-{{ $incident->ip_address }}"
                                        wire:loading.class="opacity-40 pointer-events-none"
                                        wire:target="promoteScannerIncident('{{ $incident->ip_address }}')">
                                        <td class="font-mono font-medium text-warning">{{ $incident->ip_address }}</td>
                                        <td class="text-xs text-base-content/70 max-w-md truncate" title="{{ $incident->reason }}">{{ $incident->reason }}</td>
                                        <td>{{ $incident->attempt_count }}</td>
                                        <td class="text-right whitespace-nowrap">
                                            <button wire:click="promoteScannerIncident('{{ $incident->ip_address }}')" type="button"
                                                    wire:loading.attr="disabled"
                                                    wire:target="promoteScannerIncident('{{ $incident->ip_address }}')"
                                                    class="btn btn-error btn-xs">
                                                <span wire:loading wire:target="promoteScannerIncident('{{ $incident->ip_address }}')" class="loading loading-spinner loading-xs"></span>
                                                <span wire:loading.remove wire:target="promoteScannerIncident('{{ $incident->ip_address }}')">{{ __('admin.security_scanner_add_to_ban') }}</span>
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
</div>
