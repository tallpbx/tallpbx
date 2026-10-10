{{-- Zone 4: Sequential Firewall Rules (full evaluation pipeline) --}}
<div class="card bg-base-100 shadow-sm border border-base-200"
     x-data="{
         showSystemPreFilters: false,
         canScrollLeft: false,
         canScrollRight: false,
         hasOverflow: false,
         arrowTop: 40,
         tableInViewport: true,
         scrollHandler: null,
         init() {
             this.$nextTick(() => {
                 this.checkScroll();
                 this.updateArrowPosition();
             });
             this.observer = new ResizeObserver(() => {
                 this.checkScroll();
                 this.updateArrowPosition();
             });
             const el = this.getContainer();
             if (el) {
                 this.observer.observe(el);
                 const table = el.querySelector('table');
                 if (table) this.observer.observe(table);
             }
             this.$watch('showSystemPreFilters', () => {
                 this.$nextTick(() => {
                     this.checkScroll();
                     this.updateArrowPosition();
                 });
                 setTimeout(() => {
                     this.checkScroll();
                     this.updateArrowPosition();
                 }, 100);
                 setTimeout(() => {
                     this.checkScroll();
                     this.updateArrowPosition();
                 }, 300);
             });
             const scrollParent = this.$el.closest('main') || window;
             this.scrollHandler = () => this.updateArrowPosition();
             scrollParent.addEventListener('scroll', this.scrollHandler, { passive: true });
             window.addEventListener('scroll', this.scrollHandler, { passive: true });
             window.addEventListener('resize', this.scrollHandler, { passive: true });
         },
         destroy() {
             if (this.observer) this.observer.disconnect();
             const scrollParent = this.$el.closest('main') || window;
             if (scrollParent && this.scrollHandler) {
                 scrollParent.removeEventListener('scroll', this.scrollHandler);
             }
             if (this.scrollHandler) {
                 window.removeEventListener('scroll', this.scrollHandler);
                 window.removeEventListener('resize', this.scrollHandler);
             }
         },
         getContainer() {
             return this.$refs.tableContainer || this.$el.querySelector('[data-table-container]');
         },
         updateArrowPosition() {
             const el = this.getContainer();
             if (!el) return;
             const rect = el.getBoundingClientRect();
             const vh = window.innerHeight || document.documentElement.clientHeight;
             const targetY = vh / 2;
             const padding = 28;
             const computedTop = targetY - rect.top;
             this.arrowTop = Math.max(padding, Math.min(rect.height - padding, computedTop));
             this.tableInViewport = (rect.bottom > 60 && rect.top < vh - 60);
         },
         checkScroll() {
             const el = this.getContainer();
             if (!el) return;
             this.hasOverflow = el.scrollWidth > (el.clientWidth + 8);
             this.canScrollLeft = el.scrollLeft > 6;
             this.canScrollRight = el.scrollLeft + el.clientWidth < (el.scrollWidth - 6);
         },
         scrollLeft() {
             const el = this.getContainer();
             if (!el) return;
             if (typeof el.scrollBy === 'function') {
                 el.scrollBy({ left: -360, behavior: 'smooth' });
             } else {
                 el.scrollLeft = Math.max(0, el.scrollLeft - 360);
             }
             setTimeout(() => this.checkScroll(), 350);
         },
         scrollRight() {
             const el = this.getContainer();
             if (!el) return;
             if (typeof el.scrollBy === 'function') {
                 el.scrollBy({ left: 360, behavior: 'smooth' });
             } else {
                 el.scrollLeft = Math.min(el.scrollWidth - el.clientWidth, el.scrollLeft + 360);
             }
             setTimeout(() => this.checkScroll(), 350);
         }
     }"
     @resize.window.debounce.100ms="checkScroll()">
    <div class="card-body p-4 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-base-content">{{ __('admin.security_firewall_rules') }}</h2>
                    <x-tooltip :tip="__('admin.security_firewall_rules_tooltip')" align="start" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </div>
                <p class="text-xs text-base-content/60 mt-0.5">{{ __('admin.security_firewall_rules_desc') }}</p>
                <p class="text-xs text-base-content/60 mt-1 flex items-center gap-1.5 flex-wrap">
                    <span>{{ __('admin.security_firewall_rules_cli_hint') }}</span>
                    <code class="px-1.5 py-0.5 rounded bg-base-200 text-xs font-mono select-all text-base-content">php artisan security:status</code>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Companion Header Quick-Scroll Controls --}}
                <div x-show="hasOverflow" x-cloak class="join border border-base-300 rounded-lg shadow-2xs bg-base-100">
                    <x-tooltip :tip="__('admin.security_table_scroll_left')" position="bottom">
                        <button id="tableScrollHeaderLeftBtn"
                                type="button"
                                @click.stop="scrollLeft()"
                                :disabled="!canScrollLeft"
                                aria-label="{{ __('admin.security_table_scroll_left') }}"
                                class="join-item btn btn-ghost btn-xs h-8 px-2 text-base-content/70 hover:text-base-content disabled:opacity-30 disabled:pointer-events-none">
                            <x-heroicon-s-chevron-left class="w-4 h-4" />
                        </button>
                    </x-tooltip>
                    <x-tooltip :tip="__('admin.security_table_scroll_right')" position="bottom">
                        <button id="tableScrollHeaderRightBtn"
                                type="button"
                                @click.stop="scrollRight()"
                                :disabled="!canScrollRight"
                                aria-label="{{ __('admin.security_table_scroll_right') }}"
                                class="join-item btn btn-ghost btn-xs h-8 px-2 text-base-content/70 hover:text-base-content disabled:opacity-30 disabled:pointer-events-none">
                            <x-heroicon-s-chevron-right class="w-4 h-4" />
                        </button>
                    </x-tooltip>
                </div>

                {{-- Add Custom Rule --}}
                <button wire:click="openCustomRuleModal" type="button" class="btn btn-primary btn-sm gap-1 shadow-xs">
                    <x-heroicon-o-plus class="w-4 h-4" />
                    <span>{{ __('admin.security_add_rule') }}</span>
                </button>
            </div>
        </div>

        {{-- Table Scroller Area with Dynamic Floating Side Arrows --}}
        <div class="relative group/scroller" :style="`--arrow-top: ${arrowTop}px`">
            {{-- Left Scroll Arrow (Floats dynamically at viewport center) --}}
            <div x-show="hasOverflow && canScrollLeft && tableInViewport"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-x-2"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 -translate-x-2"
                 x-cloak
                 style="top: var(--arrow-top, 40px);"
                 class="absolute left-2 z-20 -translate-y-1/2 pointer-events-auto">
                <x-tooltip :tip="__('admin.security_table_scroll_left')" position="right" align="start">
                    <button id="tableScrollSideLeftBtn"
                            type="button"
                            @click.stop="scrollLeft()"
                            aria-label="{{ __('admin.security_table_scroll_left') }}"
                            class="btn btn-circle btn-sm bg-base-100/95 hover:bg-base-100 backdrop-blur-md border border-base-300 shadow-xl text-base-content hover:scale-110 active:scale-95 transition-transform">
                        <x-heroicon-s-chevron-left class="w-4 h-4" />
                    </button>
                </x-tooltip>
            </div>

            {{-- Right Scroll Arrow (Floats dynamically at viewport center) --}}
            <div x-show="hasOverflow && canScrollRight && tableInViewport"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-x-2"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-2"
                 x-cloak
                 style="top: var(--arrow-top, 40px);"
                 class="absolute right-2 z-20 -translate-y-1/2 pointer-events-auto">
                <x-tooltip :tip="__('admin.security_table_scroll_right')" position="left" align="start">
                    <button id="tableScrollSideRightBtn"
                            type="button"
                            @click.stop="scrollRight()"
                            aria-label="{{ __('admin.security_table_scroll_right') }}"
                            class="btn btn-circle btn-sm bg-base-100/95 hover:bg-base-100 backdrop-blur-md border border-base-300 shadow-xl text-base-content hover:scale-110 active:scale-95 transition-transform">
                        <x-heroicon-s-chevron-right class="w-4 h-4" />
                    </button>
                </x-tooltip>
            </div>

            {{-- Unified Firewall Rules Table --}}
            <div x-ref="tableContainer"
                 data-table-container
                 @scroll.passive="checkScroll()"
                 class="overflow-x-auto border border-base-200 rounded-box">
            <table class="table table-xs md:table-sm w-full">
                <thead>
                    <tr class="bg-base-200/40 text-base-content/70">
                        <th class="w-10 text-center px-1">{{ __('client.status') }}</th>
                        <th class="px-3">{{ __('admin.security_rule_name') }}</th>
                        <th class="w-16 text-center px-1">{{ __('admin.security_protocol') }}</th>
                        <th class="w-24 px-2">{{ __('admin.security_port') }}</th>
                        <th class="w-28 px-2">{{ __('admin.security_source_ip') }}</th>
                        <th class="w-16 text-center px-1">{{ __('admin.security_action') }}</th>
                        <th class="w-20 text-right px-2">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    {{-- Ingress Pre-Filters Collapsible Header --}}
                    <tr x-data="{ suppressTitle: false }"
                        class="bg-base-200/40 text-xs font-semibold text-base-content/80 cursor-pointer hover:bg-base-200/70 transition-colors select-none"
                        @click="showSystemPreFilters = !showSystemPreFilters"
                        :title="suppressTitle ? null : '{{ __('admin.security_toggle_invariants_tooltip') }}'">
                        <td colspan="7" class="py-2.5 px-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    {{-- Pre-filter switch on the left with no other text --}}
                                    <label class="flex items-center cursor-pointer"
                                           @click.stop
                                           @mouseenter="suppressTitle = true"
                                           @mouseleave="suppressTitle = false"
                                           wire:loading.class="opacity-70 pointer-events-none"
                                           wire:target="setPrefilterEnabled, confirmDisablePrefilter, executeDisablePrefilter">
                                        <input @if ($prefilterEnabled) wire:click="confirmDisablePrefilter" @else wire:click="setPrefilterEnabled(true)" @endif
                                               wire:loading.attr="disabled"
                                               wire:target="setPrefilterEnabled, confirmDisablePrefilter, executeDisablePrefilter"
                                               type="checkbox" class="toggle toggle-primary toggle-xs" @checked($prefilterEnabled) />
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

                                    <span class="badge badge-ghost badge-sm text-xs font-normal {{ ! $prefilterEnabled ? 'opacity-50' : '' }}">
                                        {{ __('admin.security_invariants_rules_count', ['count' => count($preFilterRows)]) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    {{-- The reset escape hatch always restores a known-safe evaluation order. --}}
                                    <button wire:click.stop="resetPreFilterOrder" type="button"
                                            @mouseenter="suppressTitle = true"
                                            @mouseleave="suppressTitle = false"
                                            wire:confirm="{{ __('admin.security_prefilter_reset_confirm') }}"
                                            @disabled(! $prefilterEnabled)
                                            class="btn btn-ghost btn-xs gap-1 text-base-content/70 normal-case font-medium">
                                        <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                                        <span>{{ __('admin.security_prefilter_reset') }}</span>
                                    </button>
                                    <div class="flex items-center gap-1.5 text-xs font-medium text-primary">
                                        <span x-text="showSystemPreFilters ? '{{ __('admin.security_hide_rules') }}' : '{{ __('admin.security_show_rules') }}'"></span>
                                        <x-heroicon-s-chevron-down class="w-4 h-4 transition-transform duration-200" ::class="showSystemPreFilters ? 'rotate-180' : ''" />
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    {{-- Pre-Filter Rows (rendered in the stored evaluation order) --}}
                    @foreach ($preFilterRows as $preFilterRow)
                        <tr class="hover transition-opacity duration-200 {{ ! $prefilterEnabled ? 'opacity-40 bg-base-200/20' : ($preFilterRow['invariant'] ? 'bg-base-200/5' : '') }}"
                            x-show="showSystemPreFilters" x-cloak>
                            <td class="text-center px-1">
                                <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full {{ ! $prefilterEnabled ? 'bg-base-content/25' : $preFilterRow['status_class'] }} {{ ($prefilterEnabled && $preFilterRow['count_pulse']) ? 'animate-pulse' : '' }}" title="{{ $prefilterEnabled ? 'Active' : 'Disabled' }}"></span>
                            </td>
                            <td class="px-3">
                                <div class="flex items-center gap-1.5 font-medium text-base-content">
                                    <span class="{{ ! $prefilterEnabled ? 'text-base-content/70' : '' }}">{{ $preFilterRow['label'] }}</span>
                                    @if ($preFilterRow['tooltip'])
                                        <x-tooltip :tip="$preFilterRow['tooltip']" align="start" position="right">
                                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                                        </x-tooltip>
                                    @endif
                                </div>
                                <div class="mt-0.5">
                                    <span class="badge badge-ghost badge-xs font-mono text-[11px] opacity-75" title="{{ $preFilterRow['badge'] }}">{{ $preFilterRow['badge'] }}</span>
                                </div>
                            </td>
                            <td class="text-center text-xs font-mono font-semibold text-base-content/70 px-1.5">
                                ALL
                            </td>
                            <td class="text-xs text-base-content/60 px-2">
                                {{ __('admin.security_all_ports') }}
                            </td>
                            <td class="px-2">
                                @if ($preFilterRow['source_kind'] === 'static')
                                    <div class="font-mono text-xs text-base-content/70">{{ $preFilterRow['source_static'] }}</div>
                                    <span class="badge badge-ghost badge-xs font-mono mt-0.5">{{ __('admin.security_dual_stack_badge') }}</span>
                                @elseif ($preFilterRow['source_kind'] === 'anywhere')
                                    <span class="font-mono text-xs text-base-content/70">{{ __('admin.security_source_anywhere') }}</span>
                                    <span class="badge badge-ghost badge-xs font-mono ml-1">{{ __('admin.security_dual_stack_badge') }}</span>
                                @else
                                    <span class="font-mono text-xs {{ ! $prefilterEnabled ? 'text-base-content/50' : $preFilterRow['count_class'] }}">
                                        {{ $preFilterRow['count'] }} {{ trans_choice($preFilterRow['count_choice'], $preFilterRow['count']) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-center px-1.5">
                                @if ($preFilterRow['action'] === 'allow')
                                    <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                                @else
                                    <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap px-2">
                                <div class="inline-flex items-center gap-1 justify-end">
                                    @if ($preFilterRow['pinned'])
                                        {{-- Pinned first: localhost IPC can never be filtered. --}}
                                        <x-tooltip :tip="__('admin.security_prefilter_locked_tooltip')" align="end" position="left">
                                            <span class="inline-flex items-center text-base-content/40 px-1">
                                                <x-heroicon-o-lock-closed class="w-3.5 h-3.5" />
                                            </span>
                                        </x-tooltip>
                                        <span class="badge badge-ghost badge-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                                    @else
                                        <span class="flex flex-col">
                                            <button wire:click="movePreFilterUp('{{ $preFilterRow['key'] }}')" type="button"
                                                    @disabled(! $prefilterEnabled)
                                                    class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content disabled:opacity-30">
                                                <x-heroicon-s-chevron-up class="w-3 h-3" />
                                            </button>
                                            <button wire:click="movePreFilterDown('{{ $preFilterRow['key'] }}')" type="button"
                                                    @disabled(! $prefilterEnabled)
                                                    class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content disabled:opacity-30">
                                                <x-heroicon-s-chevron-down class="w-3 h-3" />
                                            </button>
                                        </span>
                                        @if ($preFilterRow['invariant'])
                                            <span class="badge badge-ghost badge-xs opacity-75 font-mono">{{ __('admin.security_kernel_invariant') }}</span>
                                        @endif
                                        @if ($preFilterRow['manage'])
                                            <x-tooltip :tip="$preFilterRow['manage']['label']" position="left">
                                                <button wire:click="$set('activeTab', '{{ $preFilterRow['manage']['tab'] }}')" type="button"
                                                        @disabled(! $prefilterEnabled)
                                                        aria-label="{{ $preFilterRow['manage']['label'] }}"
                                                        class="btn btn-ghost btn-xs btn-square {{ ! $prefilterEnabled ? 'opacity-40 pointer-events-none' : $preFilterRow['manage']['class'] }}">
                                                    <x-heroicon-o-arrow-top-right-on-square class="w-3.5 h-3.5" />
                                                </button>
                                            </x-tooltip>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    {{-- Standard Services Section Header --}}
                    <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                        <td colspan="7" class="py-2 px-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="uppercase tracking-wider text-xs">{{ __('admin.security_core_services_title') }}</span>
                                </div>
                                <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_core_services_note') }}</span>
                            </div>
                        </td>
                    </tr>

                    {{-- Core PBX Services Rows --}}
                    @foreach ($catalogServices as $service)
                        <tr class="hover {{ ! $service->enabled ? 'opacity-50' : '' }}"
                            wire:key="sys-service-{{ $service->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="toggleSystemService({{ $service->id }}), openEditSystemServiceModal({{ $service->id }})">
                            <td class="text-center px-1">
                                <input wire:click="toggleSystemService({{ $service->id }})" type="checkbox"
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
                                                    class="badge badge-warning badge-xs gap-1 cursor-pointer">
                                                <x-heroicon-o-shield-check class="w-3 h-3" />
                                                <span>{{ __('admin.security_tftp_defense_label') }}</span>
                                                <x-heroicon-s-chevron-down class="w-2.5 h-2.5 transition-transform" x-bind:class="tftpCountersOpen ? 'rotate-180' : ''" />
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
                                                       type="checkbox" class="toggle toggle-warning toggle-xs" @checked($tftpDefense['enabled']) />
                                                <span wire:loading.remove wire:target="setTftpDefense" class="label-text text-[11px]">
                                                    {{ $tftpDefense['enabled'] ? __('client.enabled') : __('client.disabled') }}
                                                </span>
                                                <span wire:loading wire:target="setTftpDefense" class="inline-flex items-center gap-1 text-[11px] text-warning">
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
                                            <div class="font-mono text-[11px] text-base-content/60">
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
                                    <span class="badge badge-ghost badge-xs">{{ __('admin.security_source_anywhere') }}</span>
                                @else
                                    <span class="font-mono text-xs text-primary font-medium">{{ $service->source_ip }}</span>
                                @endif
                            </td>
                            <td class="text-center px-1.5">
                                @if ($service->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.security_action_allow') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('client.disabled') }}</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap px-2">
                                <x-icon-button icon="heroicon-o-pencil-square" :label="__('admin.security_edit_service')" wire:click="openEditSystemServiceModal({{ $service->id }})" class="text-primary" />
                            </td>
                        </tr>
                    @endforeach

                    {{-- Custom Rules Section Header --}}
                    <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                        <td colspan="7" class="py-2 px-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="uppercase tracking-wider text-xs">{{ __('admin.security_custom_rules_title') }}</span>
                                </div>
                                <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step4_desc') }}</span>
                            </div>
                        </td>
                    </tr>

                    {{-- Custom Sequential Rules Rows --}}
                    @forelse ($firewallRules as $rule)
                        <tr class="hover {{ ! $rule->enabled ? 'opacity-50' : '' }}"
                            wire:key="firewall-rule-{{ $rule->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="toggleRule({{ $rule->id }}), moveRuleUp({{ $rule->id }}), moveRuleDown({{ $rule->id }}), openCustomRuleModal({{ $rule->id }}), deleteRule({{ $rule->id }})">
                            {{-- Status Toggle --}}
                            <td class="text-center px-1">
                                <input wire:click="toggleRule({{ $rule->id }})" type="checkbox"
                                       class="toggle toggle-primary toggle-sm"
                                       @checked($rule->enabled) />
                            </td>

                            {{-- Rule Name / Description --}}
                            <td class="px-3">
                                <div class="font-medium text-base-content">{{ $rule->description }}</div>
                                @if ($rule->service)
                                    <div class="text-xs text-base-content/60">{{ $rule->service->name }}</div>
                                @endif
                            </td>

                            {{-- Protocol --}}
                            <td class="text-center font-mono text-xs font-semibold text-base-content/80 px-1.5">
                                @if ($rule->service)
                                    {{ $rule->service->protocol === 'both' ? 'TCP/UDP' : strtoupper($rule->service->protocol) }}
                                @else
                                    {{ match (strtolower((string) $rule->custom_protocol)) {
                                        'all' => 'ALL',
                                        default => strtoupper((string) $rule->custom_protocol),
                                    } }}
                                @endif
                            </td>

                            {{-- Port --}}
                            <td class="px-2">
                                @if ($rule->service)
                                    <span class="font-mono text-sm text-base-content/70 whitespace-nowrap">{{ $rule->service->port_range }}</span>
                                @else
                                    <span class="font-mono text-sm">{{ $rule->custom_port ?? '—' }}</span>
                                @endif
                            </td>

                            {{-- Source Network --}}
                            <td class="px-2">
                                @if ($rule->source_ip === 'any' || $rule->source_ip === '0.0.0.0/0')
                                    <span class="badge badge-ghost badge-xs">{{ __('admin.security_source_anywhere') }}</span>
                                @else
                                    <span class="font-mono text-xs">{{ $rule->source_ip }}</span>
                                @endif
                            </td>

                            {{-- Action Badge --}}
                            <td class="text-center px-1.5">
                                @if ($rule->action === 'accept')
                                    <span class="badge badge-success badge-sm">{{ __('admin.security_action_allow') }}</span>
                                @else
                                    <span class="badge badge-error badge-sm">{{ __('admin.security_action_block') }}</span>
                                @endif
                            </td>

                            {{-- Reorder Arrows & Row Actions --}}
                            <td class="text-right whitespace-nowrap px-2">
                                <div class="inline-flex items-center gap-1 justify-end">
                                    <span class="flex flex-col">
                                        <button wire:click="moveRuleUp({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                            <x-heroicon-s-chevron-up class="w-3 h-3" />
                                        </button>
                                        <button wire:click="moveRuleDown({{ $rule->id }})" type="button" class="btn btn-ghost btn-xs p-0 h-4 min-h-0 text-base-content/60 hover:text-base-content">
                                            <x-heroicon-s-chevron-down class="w-3 h-3" />
                                        </button>
                                    </span>
                                    <x-icon-button icon="heroicon-o-pencil-square" :label="__('client.edit').' '.$rule->source_ip" wire:click="openCustomRuleModal({{ $rule->id }})" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$rule->source_ip" wire:click="deleteRule({{ $rule->id }})" class="text-error" />
                                </div>
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

                    {{-- Default Inbound Policy Section Header --}}
                    <tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
                        <td colspan="7" class="py-2 px-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="uppercase tracking-wider text-xs">{{ __('admin.security_default_policy') }}</span>
                                </div>
                                <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step5_desc') }}</span>
                            </div>
                        </td>
                    </tr>
                    {{-- Default Inbound Fallback Policy Row --}}
                    <tr class="hover"
                        wire:loading.class="opacity-40 pointer-events-none"
                        wire:target="saveDefaultPolicy">
                        <td class="text-center px-1">
                            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-base-content/40" title="Active"></span>
                        </td>
                        <td class="px-3">
                            <div class="font-medium text-base-content">{{ __('admin.security_default_policy') }}</div>
                            <div class="text-xs text-base-content/60">{{ __('admin.security_unmatched_traffic') }}</div>
                        </td>
                        <td class="text-center text-xs font-mono text-base-content/70 px-1.5">
                            ALL
                        </td>
                        <td class="text-xs text-base-content/60 px-2">
                            <span title="{{ __('admin.security_all_remaining_traffic') }}">{{ __('admin.security_all_ports') }}</span>
                        </td>
                        <td class="px-2">
                            <span class="badge badge-ghost badge-xs">{{ __('admin.security_source_anywhere') }}</span>
                        </td>
                        <td class="text-center px-1.5">
                            <span wire:loading.remove wire:target="saveDefaultPolicy">
                                @if ($firewallDefaultPolicy === 'drop')
                                    <span class="badge badge-error badge-sm font-semibold">{{ __('admin.security_action_drop') }}</span>
                                @else
                                    <span class="badge badge-success badge-sm font-semibold">{{ __('admin.security_action_allow') }}</span>
                                @endif
                            </span>
                            <span wire:loading wire:target="saveDefaultPolicy" class="inline-flex items-center gap-1.5">
                                <span class="loading loading-spinner loading-xs text-primary"></span>
                                <span class="badge badge-ghost badge-sm font-semibold text-base-content/60">{{ __('admin.saving') }}</span>
                            </span>
                        </td>
                        <td class="text-right whitespace-nowrap px-2">
                            <button wire:click="openDefaultPolicyForm"
                                    wire:loading.attr="disabled"
                                    wire:target="saveDefaultPolicy"
                                    type="button" class="btn btn-ghost btn-xs gap-1 text-base-content/70">
                                <x-heroicon-o-cog-6-tooth class="w-3.5 h-3.5" wire:loading.remove wire:target="saveDefaultPolicy" />
                                <span wire:loading wire:target="saveDefaultPolicy" class="loading loading-spinner loading-xs text-primary"></span>
                                <span>{{ __('admin.security_configure') }}</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
