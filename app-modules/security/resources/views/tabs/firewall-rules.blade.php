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
                <div class="text-xs text-base-content/60 mt-1 space-y-1">
                    <p>{{ __('admin.security_firewall_rules_cli_hint') }}</p>
                    <code class="px-1.5 py-0.5 rounded bg-base-200 font-mono select-all text-base-content inline-block">php artisan security:status</code>
                </div>
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
                        {{-- Every section shares this trailing column grid (reorder | primary
                             action | delete) so each icon lines up in one vertical column. --}}
                        <th colspan="3" class="text-center px-1">{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    {{-- Ingress Pre-Filters Partial --}}
                    @include('security::tabs.partials.firewall-prefilters')

                    {{-- Core PBX Services Partial --}}
                    @include('security::tabs.partials.firewall-services')

                    {{-- Custom Sequential Rules Partial --}}
                    @include('security::tabs.partials.firewall-custom-rules')

                    {{-- Default Inbound Policy Partial --}}
                    @include('security::tabs.partials.firewall-default-policy')
                </tbody>
            </table>
        </div>
    </div>
</div>
