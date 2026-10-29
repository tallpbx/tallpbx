{{-- Card: SIP Bot & Scanner Signatures --}}
<div class="card bg-base-100 shadow-sm border border-base-200 mt-4">
    <div class="card-body p-4 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_scanner_title') }}</h2>
                    <span class="badge {{ $sipScanner['enforcement'] ? 'badge-success' : 'badge-neutral' }} badge-sm font-semibold">
                        {{ $sipScanner['enforcement'] ? __('admin.security_threat_feed_status_active') : __('admin.security_threat_feed_status_disabled') }}
                    </span>
                    <x-tooltip :tip="__('admin.security_scanner_desc')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </div>
                <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_scanner_desc') }}</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <label class="label cursor-pointer justify-start gap-2 p-0"
                       wire:loading.class="opacity-70 pointer-events-none"
                       wire:target="setSipScannerEnforcement">
                    <input wire:click="setSipScannerEnforcement({{ $sipScanner['enforcement'] ? 'false' : 'true' }})"
                           wire:loading.attr="disabled"
                           wire:target="setSipScannerEnforcement"
                           type="checkbox" class="toggle toggle-success toggle-sm" @checked($sipScanner['enforcement']) />
                    <span wire:loading.remove wire:target="setSipScannerEnforcement" class="label-text font-medium text-xs sm:text-sm">
                        {{ $sipScanner['enforcement'] ? __('admin.enabled') : __('admin.disabled') }}
                    </span>
                    <span wire:loading wire:target="setSipScannerEnforcement" class="inline-flex items-center gap-1.5 label-text font-medium text-xs sm:text-sm text-success">
                        <span class="loading loading-spinner loading-xs"></span>
                        <span>{{ $sipScanner['enforcement'] ? __('admin.security_scanner_disabling') : __('admin.security_scanner_enabling') }}</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            {{-- Left: duration, custom signatures --}}
            <div class="space-y-4">
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
                    <div class="flex items-center gap-1.5 mb-1.5">
                        <span class="text-sm font-medium text-base-content">{{ __('admin.security_scanner_custom') }}</span>
                        <x-tooltip :tip="__('admin.security_scanner_custom_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
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
                               class="input input-bordered input-sm font-mono w-full max-w-xs {{ $errors->has('newScannerSignature') ? 'input-error' : '' }}" />
                        <button wire:click="addScannerSignature" type="button" class="btn btn-outline btn-sm">{{ __('admin.security_scanner_add') }}</button>
                    </div>
                    <x-form-error name="newScannerSignature" />
                </div>
            </div>

            {{-- Right: the read-only curated tiers --}}
            <div class="space-y-3">
                <div>
                    @if ($sipScanner['enforcement'])
                        <div class="text-sm font-medium mb-1.5">{{ __('admin.security_scanner_high_group_active') }}</div>
                    @else
                        <div class="text-sm font-medium mb-1.5">{{ __('admin.security_scanner_high_group_monitored') }}</div>
                    @endif
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @foreach ($sipScanner['defaults']['high'] as $entry)
                            <span class="badge badge-ghost badge-sm font-mono">{{ $entry['pattern'] }}</span>
                        @endforeach
                    </div>
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1.5">
                        <span class="text-sm font-medium">{{ __('admin.security_scanner_low_group') }}</span>
                        <x-tooltip :tip="__('admin.security_scanner_incidents_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @foreach ($sipScanner['defaults']['low'] as $entry)
                            <span class="badge badge-ghost badge-sm font-mono">{{ $entry['pattern'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Detected but not blocked incidents with the one-click enforcement --}}
        <div class="border-t border-base-200 pt-3">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-sm font-medium">{{ __('admin.security_scanner_incidents') }}</span>
                <span class="badge badge-neutral badge-sm font-mono">{{ $this->scannerIncidents->count() }}</span>
            </div>
            @if ($this->scannerIncidents->isNotEmpty())
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
                            @foreach ($this->scannerIncidents as $incident)
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
                                            <span wire:loading.remove wire:target="promoteScannerIncident('{{ $incident->ip_address }}')">{{ __('admin.security_scanner_ban_ip') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-xs text-base-content/50 italic py-1">{{ __('admin.security_scanner_incidents_empty') }}</p>
            @endif
        </div>
    </div>
</div>

