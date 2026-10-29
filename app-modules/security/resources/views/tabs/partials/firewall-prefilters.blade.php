{{-- Ingress Pre-Filters Collapsible Header --}}
<tr x-data="{ suppressTitle: false }"
    class="bg-base-200/40 text-xs font-semibold text-base-content/80 cursor-pointer hover:bg-base-200/70 transition-colors select-none"
    @click="showSystemPreFilters = !showSystemPreFilters"
    :title="suppressTitle ? null : '{{ __('admin.security_toggle_invariants_tooltip') }}'">
    <td colspan="9" class="py-2.5 px-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                {{-- Pre-filter switch on the left with no other text --}}
                <label wire:key="prefilter-switch-{{ $prefilterEnabled ? 'on' : 'off' }}"
                       class="flex items-center cursor-pointer"
                       @click.stop
                       @mouseenter="suppressTitle = true"
                       @mouseleave="suppressTitle = false"
                       wire:loading.class="opacity-70 pointer-events-none"
                       wire:target="setPrefilterEnabled, confirmDisablePrefilter, executeDisablePrefilter">
                    <input wire:key="prefilter-input-{{ $prefilterEnabled ? 'on' : 'off' }}"
                           @if ($prefilterEnabled) wire:click="confirmDisablePrefilter" @else wire:click="setPrefilterEnabled(true)" @endif
                           wire:loading.attr="disabled"
                           wire:target="setPrefilterEnabled, confirmDisablePrefilter, executeDisablePrefilter"
                           :checked="$wire.prefilterEnabled"
                           type="checkbox"
                           aria-label="{{ __('admin.security_system_invariants_prefilters') }}"
                           class="toggle toggle-primary toggle-xs" @checked($prefilterEnabled) />
                </label>

                <span class="uppercase tracking-wider text-xs {{ ! $prefilterEnabled ? 'text-base-content/50' : '' }}">{{ __('admin.security_system_invariants_prefilters') }}</span>

                <span wire:loading wire:target="setPrefilterEnabled, executeDisablePrefilter" class="loading loading-spinner loading-xs text-primary"></span>
                <span wire:loading.remove wire:target="setPrefilterEnabled, executeDisablePrefilter"
                       @click.stop
                       @mouseenter="suppressTitle = true"
                       @mouseleave="suppressTitle = false">
                    <x-tooltip :tip="__('admin.security_toggle_prefilter_help')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </span>

                <span class="badge badge-ghost badge-sm text-xs font-normal text-center {{ ! $prefilterEnabled ? 'opacity-50' : '' }}">
                    {{ __('admin.security_invariants_rules_count', ['count' => count($this->preFilterRows) + 1]) }}
                </span>
            </div>
            <div class="flex items-center gap-3">
                {{-- The reset escape hatch always restores a known-safe evaluation order. --}}
                <button wire:click.stop="confirmResetPreFilterOrder" type="button"
                        @mouseenter="suppressTitle = true"
                        @mouseleave="suppressTitle = false"
                        @disabled(! $prefilterEnabled)
                        class="btn btn-ghost btn-xs gap-1 text-base-content/70 normal-case font-medium">
                    <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                    <span>{{ __('admin.security_prefilter_reset') }}</span>
                </button>
                <div class="flex items-center gap-1.5 text-xs font-medium text-primary hover:text-primary/80">
                    <span x-text="showSystemPreFilters ? '{{ __('admin.security_hide_rules') }}' : '{{ __('admin.security_show_rules') }}'"></span>
                    <x-heroicon-s-chevron-down class="w-4 h-4 transition-transform duration-200" ::class="showSystemPreFilters ? 'rotate-180' : ''" />
                </div>
            </div>
        </div>
    </td>
</tr>

{{-- Pre-Filter Rows (rendered in the stored evaluation order) --}}
@foreach ($this->preFilterRows as $preFilterRow)
    @php
        $rowActive = $prefilterEnabled && ($preFilterRow['enabled'] ?? true);
    @endphp
    <tr class="hover transition-opacity duration-200 {{ ! $rowActive ? 'opacity-40 bg-base-200/20' : '' }}"
        x-show="showSystemPreFilters" x-cloak>
        <td class="text-center px-1">
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full {{ ! $rowActive ? 'bg-base-content/25' : $preFilterRow['status_class'] }} {{ ($rowActive && $preFilterRow['count_pulse']) ? 'animate-pulse' : '' }}" title="{{ $rowActive ? 'Active' : 'Disabled' }}"></span>
        </td>
        {{-- Name cell is a single line, like every other section, so rows stay
             uniform in height and the columns read as one clean grid. --}}
        <td class="px-3">
            <div class="flex items-center gap-1.5 font-medium text-base-content">
                <span class="{{ ! $rowActive ? 'text-base-content/70' : '' }}">{{ $preFilterRow['label'] }}</span>
                @if ($preFilterRow['tooltip'])
                    <x-tooltip :tip="$preFilterRow['tooltip']" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                @endif
                @if ($preFilterRow['invariant'] && ! $preFilterRow['pinned'])
                    <span class="badge badge-ghost badge-xs font-mono opacity-75 inline-block">{{ __('admin.security_kernel_invariant') }}</span>
                @endif
                <span class="badge badge-ghost badge-xs font-mono opacity-75 inline-block" title="{{ $preFilterRow['badge'] }}">{{ $preFilterRow['badge'] }}</span>
            </div>
        </td>
        <td class="text-center text-xs font-mono font-semibold text-base-content/70 px-1.5">
            ALL
        </td>
        <td class="text-xs text-base-content/60 px-2">
            {{ __('admin.security_all_ports') }}
        </td>
        <td class="px-2">
            <div class="flex items-center gap-1.5">
                @if ($preFilterRow['source_kind'] === 'static')
                    <span class="font-mono text-xs text-base-content/70">{{ $preFilterRow['source_static'] }}</span>
                @elseif ($preFilterRow['source_kind'] === 'anywhere')
                    <span class="font-mono text-xs text-base-content/70">{{ __('admin.security_source_anywhere') }}</span>
                @else
                    <span class="font-mono text-xs {{ ! $rowActive ? 'text-base-content/50' : $preFilterRow['count_class'] }}">
                        {{ number_format($preFilterRow['count']) }} {{ trans_choice($preFilterRow['count_choice'], $preFilterRow['count']) }}
                    </span>
                @endif
                @if (! $rowActive && ! empty($preFilterRow['downloaded_count']))
                    <span class="text-xs text-base-content/50">({{ __('admin.security_in_list_note', ['count' => number_format($preFilterRow['downloaded_count'])]) }})</span>
                @endif
            </div>
        </td>
        <td class="text-center px-1.5">
            @if (! $rowActive)
                <span class="badge badge-neutral badge-sm font-semibold opacity-75 text-center inline-block max-w-full">{{ __('admin.security_threat_feed_status_disabled') }}</span>
            @elseif ($preFilterRow['action'] === 'allow')
                <span class="badge badge-success badge-sm font-semibold text-center inline-block max-w-full">{{ __('admin.security_action_allow') }}</span>
            @else
                <span class="badge badge-error badge-sm font-semibold text-center inline-block max-w-full">{{ __('admin.security_action_drop') }}</span>
            @endif
        </td>
        {{-- Reorder, primary action, and delete each get their own column so
             they line up with every other section of the table. --}}
        <td class="text-center px-1 w-10">
            <div class="flex flex-col items-center gap-0.5">
                @if (! $preFilterRow['pinned'])
                    <button wire:click="movePreFilterUp('{{ $preFilterRow['key'] }}')" type="button"
                            @disabled(! $prefilterEnabled)
                            aria-label="{{ __('admin.security_move_up') }}"
                            class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content disabled:opacity-30">
                        <x-heroicon-s-chevron-up class="w-3.5 h-3.5" />
                    </button>
                    <button wire:click="movePreFilterDown('{{ $preFilterRow['key'] }}')" type="button"
                            @disabled(! $prefilterEnabled)
                            aria-label="{{ __('admin.security_move_down') }}"
                            class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content disabled:opacity-30">
                        <x-heroicon-s-chevron-down class="w-3.5 h-3.5" />
                    </button>
                @else
                    {{-- Pinned first: localhost IPC can never be filtered. --}}
                    <x-tooltip :tip="__('admin.security_prefilter_locked_tooltip')" align="end" position="left">
                        <span class="inline-flex items-center justify-center h-4 text-base-content/40">
                            <x-heroicon-o-lock-closed class="w-4 h-4" />
                        </span>
                    </x-tooltip>
                @endif
            </div>
        </td>
        <td class="text-center px-1 w-10">
            @if ($preFilterRow['manage'])
                {{-- Row actions stay neutral-themed; the status dot and action
                     badge in this row already carry the semantic colour. --}}
                <x-tooltip :tip="$preFilterRow['manage']['label']" position="left">
                    <button wire:click="$set('activeTab', '{{ $preFilterRow['manage']['tab'] }}')" type="button"
                            @disabled(! $prefilterEnabled)
                            aria-label="{{ $preFilterRow['manage']['label'] }}"
                            class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content {{ ! $prefilterEnabled ? 'opacity-40 pointer-events-none' : '' }}">
                        <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                    </button>
                </x-tooltip>
            @else
                {{-- Not all stages open a management tab: keep the lane uniform. --}}
                <span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span>
            @endif
        </td>
        {{-- Built-in pre-filter stages cannot be deleted: reserve the empty
             delete column to keep the shared grid aligned. --}}
        <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
    </tr>
@endforeach

{{-- Dedicated Geo-Blocking Row --}}
@php
    $geoRuleModel = $this->geoRule;
    $geoEnabled = (bool) $geoRuleModel->enabled;
    $hasCountries = ! empty($geoCountries);
    $geoActive = $geoEnabled && $hasCountries;
    $geoStatus = match (true) {
        ! $geoEnabled => 'disabled',
        ! $hasCountries => 'idle',
        default => 'active',
    };
@endphp
<tr class="hover transition-opacity duration-200 {{ $geoStatus === 'disabled' ? 'opacity-50 bg-base-200/20' : ($geoStatus === 'idle' ? 'bg-base-200/10' : '') }}"
    wire:key="geo-blocking-cockpit-row"
    x-show="showSystemPreFilters" x-cloak>
    <td class="text-center px-1">
        @if ($geoStatus === 'disabled')
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-base-content/25"
                  title="{{ __('admin.security_geo_status_disabled') }}"></span>
        @elseif ($geoStatus === 'idle')
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-warning"
                  title="{{ __('admin.security_geo_no_countries') }}"></span>
        @else
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-error"
                  title="{{ __('admin.security_geo_status_active') }}"></span>
        @endif
    </td>
    <td class="px-3">
        <div class="flex items-center gap-1.5 font-medium text-base-content">
            <span class="{{ $geoStatus === 'disabled' ? 'text-base-content/70' : '' }}">{{ __('admin.security_geo_blocking_title') }}</span>
            <x-tooltip :tip="__('admin.security_geo_blocking_tooltip')" align="start" position="right">
                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
            </x-tooltip>
        </div>
        <div class="mt-0.5 flex items-center gap-1.5 flex-wrap">
            <span class="badge badge-ghost badge-xs font-mono opacity-75 text-center inline-block" title="{{ $geoMode === 'whitelist' ? '@geo_allowed_ips & @geo_allowed_ips6' : '@geo_blocked_ips & @geo_blocked_ips6' }}">
                {{ $geoMode === 'whitelist' ? '@geo_allowed_ips' : '@geo_blocked_ips' }}
            </span>
            <span class="badge badge-neutral badge-xs font-mono text-center inline-block max-w-full">
                {{ $geoMode === 'whitelist' ? __('admin.security_geo_mode_whitelist_badge') : __('admin.security_geo_mode_blacklist_badge') }}
            </span>
        </div>
    </td>
    <td class="text-center text-xs font-mono font-semibold text-base-content/70 px-1.5">
        ALL
    </td>
    <td class="text-xs text-base-content/60 px-2">
        {{ __('admin.security_all_ports') }}
    </td>
    <td class="px-2">
        @if (! empty($geoCountries))
            <div class="font-mono text-xs {{ ! $geoActive ? 'text-base-content/50' : 'text-base-content/70' }}">
                @if ($geoMode === 'whitelist')
                    {{ __('admin.security_geo_target_all_except', ['countries' => implode(', ', array_slice($geoCountries, 0, 3)).(count($geoCountries) > 3 ? '...' : '')]) }}
                @else
                    {{ count($geoCountries) }} {{ count($geoCountries) === 1 ? __('admin.security_geo_country_single') : __('admin.security_geo_country_plural') }}
                @endif
            </div>
            <div class="text-xs text-base-content/50 font-mono truncate max-w-[140px]" title="{{ implode(', ', $geoCountries) }}">
                @if ($geoMode === 'whitelist')
                    {{ count($geoCountries) }} {{ count($geoCountries) === 1 ? __('admin.security_geo_country_exempt_single') : __('admin.security_geo_country_exempt_plural') }}
                @else
                    {{ implode(', ', array_slice($geoCountries, 0, 4)) }}{{ count($geoCountries) > 4 ? '...' : '' }}
                @endif
            </div>
        @else
            <span class="font-mono text-xs text-base-content/50">0 {{ __('admin.security_geo_country_plural') }}</span>
        @endif
    </td>
    <td class="text-center px-1.5">
        @if ($geoStatus === 'disabled')
            <span class="badge badge-neutral badge-sm font-semibold opacity-75 text-center inline-block max-w-full">{{ __('admin.security_geo_status_disabled') }}</span>
        @elseif ($geoStatus === 'idle')
            <span class="badge badge-warning badge-sm font-semibold text-center inline-block max-w-full" title="{{ __('admin.security_geo_no_countries') }}">
                {{ __('admin.security_geo_status_idle') }}
            </span>
        @else
            <span class="badge badge-error badge-sm font-semibold text-center inline-block max-w-full">{{ __('admin.security_action_drop') }}</span>
        @endif
    </td>
    {{-- Reorder and delete columns are reserved empty so the manage link
         lines up with the other sections in the shared grid. --}}
    <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
    <td class="text-center px-1 w-10">
        <x-tooltip :tip="__('admin.security_manage_geo_blocking')" position="left">
            <button wire:click="$set('activeTab', 'geo-blocking')" type="button"
                    aria-label="{{ __('admin.security_manage_geo_blocking') }}"
                    class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content">
                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
            </button>
        </x-tooltip>
    </td>
    <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
</tr>

