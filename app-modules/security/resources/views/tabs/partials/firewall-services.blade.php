{{-- Standard Services Section Header --}}
<tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
    <td colspan="9" class="py-2 px-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="uppercase tracking-wider text-xs">{{ __('admin.security_core_services_title') }}</span>
            </div>
            <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_core_services_note') }}</span>
        </div>
    </td>
</tr>

{{-- Core PBX Services Rows --}}
@foreach ($this->serviceRules as $service)
    <tr class="hover {{ ! $service->enabled ? 'opacity-50' : '' }}"
        wire:key="sys-service-{{ $service->id }}"
        wire:loading.class="opacity-40 pointer-events-none"
        wire:target="toggleSystemService({{ $service->id }}), openEditSystemServiceModal({{ $service->id }})">
        <td class="text-center px-1">
            <input wire:click="toggleSystemService({{ $service->id }})" type="checkbox"
                   wire:loading.attr="disabled"
                   wire:target="toggleSystemService({{ $service->id }})"
                   aria-label="{{ ($service->name ?? 'Service').' '.($service->enabled ? __('client.enabled') : __('client.disabled')) }}"
                   class="toggle toggle-success toggle-sm"
                   @checked($service->enabled)
                   title="{{ $service->enabled ? __('client.enabled') : __('client.disabled') }}" />
        </td>
        <td class="px-3">
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
                <div class="mt-1" x-data="{ tftpCountersOpen: false }">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button type="button" @click="tftpCountersOpen = ! tftpCountersOpen"
                                class="badge badge-warning badge-xs gap-1 cursor-pointer h-auto py-1 leading-tight text-center max-w-full">
                            <x-heroicon-o-shield-check class="w-3 h-3 shrink-0" />
                            <span>{{ __('admin.security_tftp_defense_label') }}</span>
                            <x-heroicon-s-chevron-down class="w-2.5 h-2.5 transition-transform shrink-0" x-bind:class="tftpCountersOpen ? 'rotate-180' : ''" />
                        </button>
                        <x-tooltip :tip="__('admin.security_tftp_defense_tooltip')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-3.5 h-3.5 text-base-content/60 cursor-help" />
                        </x-tooltip>
                        <label class="label cursor-pointer gap-1 p-0"
                               wire:loading.class="opacity-70 pointer-events-none"
                               wire:target="setTftpDefense">
                            <input wire:click="setTftpDefense({{ $tftpDefense['enabled'] ? 'false' : 'true' }})"
                                   wire:loading.attr="disabled"
                                   wire:target="setTftpDefense"
                                   type="checkbox" class="toggle toggle-sm {{ $tftpDefense['enabled'] ? 'toggle-success' : 'toggle-neutral' }}" @checked($tftpDefense['enabled']) />
                            <span wire:loading.remove wire:target="setTftpDefense" class="label-text text-xs">
                                {{ $tftpDefense['enabled'] ? __('client.enabled') : __('client.disabled') }}
                            </span>
                            <span wire:loading wire:target="setTftpDefense" class="inline-flex items-center gap-1 text-xs text-warning">
                                <span class="loading loading-spinner loading-xs"></span>
                                <span>{{ $tftpDefense['enabled'] ? __('admin.security_tftp_disabling') : __('admin.security_tftp_enabling') }}</span>
                            </span>
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
        <td class="text-center font-mono text-xs font-semibold text-base-content/80 px-1.5">
            {{ $service->protocol === 'both' ? 'TCP/UDP' : strtoupper($service->protocol) }}
        </td>
        <td class="px-2">
            @if ($service->protocol === 'icmp')
                <div>
                    <span class="font-mono text-xs font-semibold text-base-content">echo-request</span>
                    @if ($service->rate_limit)
                        <div class="font-mono text-xs text-base-content/60">
                            {{ $service->rate_limit }}/s (burst {{ $service->burst ?? $service->rate_limit }})
                        </div>
                    @endif
                </div>
            @else
                <span class="font-mono text-sm font-semibold text-base-content">{{ $service->port_range }}</span>
            @endif
        </td>
        <td class="px-2">
            @if ($service->source_ip === 'any' || $service->source_ip === '0.0.0.0/0' || empty($service->source_ip))
                <span class="badge badge-ghost badge-xs text-center inline-block max-w-full">{{ __('admin.security_source_anywhere') }}</span>
            @else
                <span class="font-mono text-xs text-base-content/70">{{ $service->source_ip }}</span>
            @endif
        </td>
        <td class="text-center px-1.5">
            @if ($service->enabled)
                <span class="badge badge-success badge-sm text-center inline-block max-w-full">{{ __('admin.security_action_allow') }}</span>
            @else
                <span class="badge badge-ghost badge-sm text-center inline-block max-w-full">{{ __('client.disabled') }}</span>
            @endif
        </td>
        {{-- Reorder and delete columns reserved empty; edit sits in the shared
             primary-action column so it lines up across all sections. --}}
        <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
        <td class="text-center px-1 w-10">
            <x-icon-button icon="heroicon-o-pencil" :label="__('admin.security_edit_service')" wire:click="openEditSystemServiceModal({{ $service->id }})" class="btn-square text-base-content/70 hover:text-base-content" />
        </td>
        <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
    </tr>
@endforeach

