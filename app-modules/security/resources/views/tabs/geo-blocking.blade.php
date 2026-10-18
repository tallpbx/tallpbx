@php
    $geoRuleModel = $this->geoRule;
    $geoStatus = match (true) {
        ! $geoEnabled => 'disabled',
        $geoRuleModel->last_status === 'failed' => 'error',
        $geoRuleModel->last_sync_at === null => 'idle',
        default => 'active',
    };
    $geoBadgeClass = match ($geoStatus) {
        'active' => 'badge-success',
        'error' => 'badge-error',
        default => 'badge-neutral',
    };
    $countriesList = $this->availableCountries();
    $drops = $this->geoDropCounter();
@endphp
<div class="space-y-6">
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            {{-- Top Card Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-base-200 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_geo_blocking_title') }}</h2>
                        <span class="badge {{ $geoBadgeClass }} badge-sm font-semibold">
                            {{ __('admin.security_geo_status_'.$geoStatus) }}
                        </span>

                        <span class="badge badge-neutral badge-sm font-mono">
                            {{ number_format($geoRuleModel->totalCidrsCount()) }} {{ $geoMode === 'whitelist' ? __('admin.security_geo_ranges_exempt') : __('admin.security_geo_ranges_blocked') }}
                        </span>
                        <span class="badge badge-ghost badge-sm font-mono opacity-75">
                            {{ $geoMode === 'whitelist' ? __('admin.security_geo_rule_drop_except') : __('admin.security_geo_rule_drop_selected') }}
                        </span>

                        <x-tooltip :tip="__('admin.security_geo_blocking_tooltip')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </div>
                    <p class="text-xs text-base-content/60 mt-1 flex items-center gap-1.5 flex-wrap">
                        <span>{{ __('admin.security_geo_cli_hint') }}</span>
                        <code class="px-1.5 py-0.5 rounded bg-base-200 text-xs font-mono select-all text-base-content">
                            sudo nft list set inet tallpbx_filter {{ $geoMode === 'whitelist' ? 'geo_allowed_ips' : 'geo_blocked_ips' }}
                        </code>
                    </p>
                </div>

                {{-- Action Buttons & Toggle --}}
                <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
                    <label class="label cursor-pointer justify-start gap-2 p-0 mr-2"
                           wire:loading.class="opacity-70 pointer-events-none"
                           wire:target="setGeoEnabled">
                        <input wire:click="setGeoEnabled({{ $geoEnabled ? 'false' : 'true' }})"
                               wire:loading.attr="disabled"
                               wire:target="setGeoEnabled"
                               type="checkbox" class="toggle toggle-error toggle-sm" @checked($geoEnabled) />
                        <span wire:loading.remove wire:target="setGeoEnabled" class="label-text font-medium text-xs sm:text-sm">
                            {{ $geoEnabled ? __('client.disable') : __('client.enable') }}
                        </span>
                        <span wire:loading wire:target="setGeoEnabled" class="inline-flex items-center gap-1.5 label-text font-medium text-xs sm:text-sm text-error">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ $geoEnabled ? __('admin.security_geo_disabling') : __('admin.security_geo_enabling') }}</span>
                        </span>
                    </label>

                    <button wire:click="clearGeoRules"
                            wire:loading.attr="disabled"
                            wire:target="clearGeoRules"
                            wire:confirm="{{ __('admin.security_geo_clear_confirm') }}"
                            type="button" class="btn btn-ghost btn-sm text-base-content/70 hover:text-error gap-1">
                        <x-heroicon-o-x-circle class="w-4 h-4" wire:loading.remove wire:target="clearGeoRules" />
                        <span class="loading loading-spinner loading-xs" wire:loading wire:target="clearGeoRules"></span>
                        <span>{{ __('admin.security_geo_clear_rules') }}</span>
                    </button>
                </div>
            </div>

            {{-- Policy Mode Selector --}}
            <div class="border border-base-200 rounded-box p-3 space-y-2">
                <label class="text-xs font-semibold uppercase tracking-wider text-base-content/70">
                    {{ __('admin.security_geo_mode_label') }}
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <label class="cursor-pointer border rounded-lg p-2.5 flex items-start gap-2.5 transition-colors {{ $geoMode === 'blacklist' ? 'border-primary bg-primary/5' : 'border-base-200 hover:bg-base-200/40' }}">
                        <input type="radio" wire:model="geoMode" wire:change="saveGeoSettings" value="blacklist" class="radio radio-primary radio-sm mt-0.5" />
                        <div>
                            <div class="text-xs font-bold text-base-content">{{ __('admin.security_geo_mode_blacklist') }}</div>
                            <div class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_geo_mode_blacklist_desc') }}</div>
                        </div>
                    </label>
                    <label class="cursor-pointer border rounded-lg p-2.5 flex items-start gap-2.5 transition-colors {{ $geoMode === 'whitelist' ? 'border-primary bg-primary/5' : 'border-base-200 hover:bg-base-200/40' }}">
                        <input type="radio" wire:model="geoMode" wire:change="saveGeoSettings" value="whitelist" class="radio radio-primary radio-sm mt-0.5" />
                        <div>
                            <div class="text-xs font-bold text-base-content">{{ __('admin.security_geo_mode_whitelist') }}</div>
                            <div class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_geo_mode_whitelist_desc') }}</div>
                        </div>
                    </label>
                </div>
            </div>

            {{-- Country Multi-Selection Tag Picker --}}
            <div class="space-y-3" x-data="{
                search: '',
                pendingAdditions: [],
                countries: @js($countriesList),
                addPending(cc) {
                    if (!this.pendingAdditions.includes(cc)) {
                        this.pendingAdditions.push(cc);
                    }
                },
                isPending(cc) {
                    return this.pendingAdditions.includes(cc) && ! $wire.geoCountries.includes(cc);
                }
            }" x-init="$watch('$wire.geoCountries', () => { pendingAdditions = []; })">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <span class="text-xs font-semibold uppercase tracking-wider text-base-content/70">
                            {{ __('admin.security_geo_countries_selected', ['count' => count($geoCountries)]) }}
                        </span>
                        <p class="text-xs text-base-content/60 mt-0.5">
                            {{ __('admin.security_geo_countries_help') }}
                        </p>
                    </div>
                    <div class="w-full sm:w-64">
                        <input x-model="search" type="search"
                               placeholder="{{ __('admin.security_geo_search_countries') }}"
                               class="input input-xs input-bordered w-full" />
                    </div>
                </div>

                {{-- Selected Country Badges --}}
                <div x-show="$wire.geoCountries.length > 0 || pendingAdditions.length > 0"
                     class="flex flex-wrap gap-1.5 p-2.5 bg-base-200/30 rounded-box border border-base-200 min-h-[42px] items-center">
                    @foreach ($geoCountries as $cc)
                        <span wire:key="geo-badge-{{ $cc }}"
                              wire:loading.class="opacity-40 pointer-events-none"
                              wire:target="toggleGeoCountry('{{ $cc }}')"
                              class="badge badge-primary badge-sm gap-1 font-mono transition-opacity">
                            <span class="font-bold">{{ $cc }}</span>
                            <span class="font-sans font-normal opacity-90">{{ $countriesList[$cc] ?? $cc }}</span>
                            <button type="button"
                                    wire:click="toggleGeoCountry('{{ $cc }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleGeoCountry('{{ $cc }}')"
                                    class="hover:text-error ml-0.5 cursor-pointer inline-flex items-center"
                                    title="{{ __('client.delete') }}">
                                <x-heroicon-s-x-mark class="w-3 h-3" wire:loading.remove wire:target="toggleGeoCountry('{{ $cc }}')" />
                                <span class="loading loading-spinner loading-xs" wire:loading wire:target="toggleGeoCountry('{{ $cc }}')"></span>
                            </button>
                        </span>
                    @endforeach

                    {{-- Optimistic In-Progress Badges (Shown immediately upon clicking a checkbox) --}}
                    <template x-for="cc in pendingAdditions" :key="'pending-' + cc">
                        <span x-show="isPending(cc)" class="badge badge-primary badge-outline badge-sm gap-1.5 font-mono animate-pulse">
                            <span class="loading loading-spinner loading-xs text-primary"></span>
                            <span class="font-bold" x-text="cc"></span>
                            <span class="font-sans font-normal opacity-90" x-text="countries[cc] || cc"></span>
                        </span>
                    </template>
                </div>

                <div x-show="! $wire.geoCountries.length && ! pendingAdditions.length"
                     class="p-3 text-center text-xs text-base-content/60 bg-base-200/20 rounded-box border border-dashed border-base-300">
                    {{ __('admin.security_geo_no_countries_selected_warning') }}
                </div>

                {{-- Country Choice Chips --}}
                <div class="max-h-56 overflow-y-auto border border-base-200 rounded-box p-2 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-1.5 bg-base-100">
                    @foreach ($countriesList as $code => $countryName)
                        @php $isSelected = in_array($code, $geoCountries, true); @endphp
                        <label x-show="search === '' || (countries['{{ $code }}'] || '').toLowerCase().includes(search.toLowerCase()) || '{{ strtolower($code) }}'.includes(search.toLowerCase())"
                               wire:key="geo-chip-{{ $code }}"
                               wire:loading.class="opacity-60 pointer-events-none"
                               wire:target="toggleGeoCountry('{{ $code }}')"
                               @click="if (! $wire.geoCountries.includes('{{ $code }}')) addPending('{{ $code }}')"
                               class="flex items-center gap-2 p-1.5 rounded hover:bg-base-200/60 cursor-pointer select-none text-xs transition-colors {{ $isSelected ? 'bg-primary/10 text-primary font-semibold' : 'text-base-content/80' }}">
                            <span class="relative inline-flex items-center justify-center w-3.5 h-3.5 shrink-0">
                                <input type="checkbox"
                                       wire:click="toggleGeoCountry('{{ $code }}')"
                                       wire:loading.attr="disabled"
                                       wire:target="toggleGeoCountry('{{ $code }}')"
                                       @checked($isSelected)
                                       wire:loading.remove
                                       wire:target="toggleGeoCountry('{{ $code }}')"
                                       class="checkbox checkbox-xs checkbox-primary" />
                                <span class="loading loading-spinner loading-xs text-primary"
                                      wire:loading
                                      wire:target="toggleGeoCountry('{{ $code }}')"></span>
                            </span>
                            <span class="font-mono text-xs opacity-75">{{ $code }}</span>
                            <span class="truncate" title="{{ $countryName }}">{{ $countryName }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Operational Metrics Footer --}}
            <div class="pt-3 border-t border-base-200 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div>
                    <span class="text-base-content/60">{{ $geoMode === 'whitelist' ? __('admin.security_geo_metric_v4_allowed') : __('admin.security_geo_metric_v4_blocked') }}:</span>
                    <span class="font-mono font-semibold ml-1">{{ number_format($geoRuleModel->v4_cidrs_count) }}</span>
                    @if ($geoMode === 'whitelist')
                        <div class="text-xs text-base-content/50 mt-0.5">{{ __('admin.security_geo_metric_others_dropped') }}</div>
                    @endif
                </div>
                <div>
                    <span class="text-base-content/60">{{ $geoMode === 'whitelist' ? __('admin.security_geo_metric_v6_allowed') : __('admin.security_geo_metric_v6_blocked') }}:</span>
                    <span class="font-mono font-semibold ml-1">{{ number_format($geoRuleModel->v6_cidrs_count) }}</span>
                </div>
                <div>
                    <span class="text-base-content/60">{{ __('admin.security_geo_metric_last_synced') }}:</span>
                    <span class="ml-1">{{ $geoRuleModel->last_sync_at ? $geoRuleModel->last_sync_at->diffForHumans() : __('admin.never') }}</span>
                </div>
                <div>
                    <span class="text-base-content/60">{{ __('admin.security_geo_metric_dropped') }}:</span>
                    <span class="font-mono font-semibold ml-1 text-error">{{ $drops !== null ? number_format($drops) : '0' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
