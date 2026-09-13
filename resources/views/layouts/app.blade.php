<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{ ...themeData(), ...layoutData() }"
      x-init="initTheme(); initLayout()"
      :data-theme="theme === 'dark' ? 'dark' : (theme === 'light' ? 'light' : (systemDark ? 'dark' : 'light'))"
      :data-layout-mode="layoutMode"
      :data-sidebar-collapsed="sidebarCollapsed ? 'true' : 'false'">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title')@else{{ $title ?? config('app.name', 'TallPBX') }}@endif</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @fonts
    @vite(['resources/css/app.css', 'resources/css/custom.css', 'resources/js/app.js'])
    @livewireStyles

    <script>
        const savedTheme = localStorage.getItem('theme') || 'system';
        let isDark = false;
        if (savedTheme === 'dark') {
            isDark = true;
        } else if (savedTheme === 'system') {
            isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');

        const savedLayout = localStorage.getItem('tallpbx:layout_mode') || 'sidebar';
        const savedCollapsed = localStorage.getItem('tallpbx:sidebar_collapsed') === 'true';
        document.documentElement.setAttribute('data-layout-mode', savedLayout);
        document.documentElement.setAttribute('data-sidebar-collapsed', savedCollapsed ? 'true' : 'false');
    </script>
</head>
<body class="panel-shell bg-base-200 text-base-content min-h-screen overflow-x-hidden antialiased">
    @php
        $impersonationService = app(\App\Services\ImpersonationServiceInterface::class);
        $panelUser = $impersonationService->isImpersonating()
            ? Auth::guard('web')->user()
            : (Auth::guard('admin')->check() ? Auth::guard('admin')->user() : Auth::guard('web')->user());
        $availableTenants = collect();
        $currentTenant = null;

        if (Auth::guard('web')->check()) {
            $availableTenants = Auth::guard('web')->user()
                ->tenants()
                ->where('tenants.enabled', true)
                ->orderBy('tenants.name')
                ->get();
            $currentTenant = app(\App\Services\TenantContext::class)->current();
        }
        $menuTree = app(\App\Services\MenuService::class)->getTree();
    @endphp
    @if($impersonationService->isImpersonating())
        @php $originalAdmin = $impersonationService->getOriginalAdmin(); @endphp
        <div class="bg-warning text-warning-content px-4 py-2 flex items-center justify-between text-sm">
            <span>
                {!! __('client.impersonating', ['name' => Auth::user()->name, 'admin' => $originalAdmin?->name]) !!}
            </span>
            <form method="POST" action="{{ route('panel.impersonation.stop') }}">
                @csrf
                <button type="submit" class="btn btn-warning btn-xs">{{ __('client.stop_impersonating') }}</button>
            </form>
        </div>
    @endif
    <div class="drawer w-full max-w-full overflow-x-hidden"
         :class="{ 'lg:drawer-open': layoutMode === 'sidebar' }">
        <input id="sidebar-drawer" type="checkbox" class="drawer-toggle" />
        <aside class="drawer-side z-50" data-panel-sidebar-scroll="drawer">
            <label for="sidebar-drawer" aria-label="close sidebar" class="drawer-overlay"></label>
            <div class="panel-sidebar bg-base-100 border-r border-base-300 flex flex-col min-h-full transition-all duration-200 ease-in-out"
                 :class="sidebarCollapsed ? 'w-16' : 'w-64'">
                <div class="flex items-center gap-3 h-16 border-b border-base-300 transition-all duration-200"
                     :class="sidebarCollapsed ? 'justify-center px-0' : 'px-5'">
                    <div class="panel-brand-mark shrink-0">
                        <x-heroicon-o-phone class="w-5 h-5" />
                    </div>
                    <span x-show="!sidebarCollapsed" x-transition.opacity class="text-base font-semibold tracking-tight truncate">{{ config('app.name', 'TallPBX') }}</span>
                </div>
                @persist('panel-sidebar')
                <nav class="flex-1 px-2 py-4 overflow-y-auto" wire:navigate:scroll data-panel-sidebar-scroll="nav">
                    <ul class="menu text-sm gap-1 p-0">
                        @foreach ($menuTree as $item)
                            @include('components.sidebar-menu-item', ['item' => $item, 'guard' => 'panel', 'persistedNavigation' => true])
                        @endforeach
                    </ul>
                </nav>
                @endpersist

                {{-- Mini Rail Collapse / Expand Toggle Button --}}
                <div class="p-2 border-t border-base-300 hidden lg:flex items-center transition-all duration-200"
                     :class="sidebarCollapsed ? 'justify-center' : 'justify-end'">
                    <div class="tooltip tooltip-right" :data-tip="sidebarCollapsed ? @js(__('admin.expand_sidebar')) : @js(__('admin.collapse_sidebar'))">
                        <button
                            type="button"
                            @click="toggleSidebar()"
                            class="btn btn-ghost btn-sm btn-square text-base-content/60 hover:text-base-content"
                            :aria-label="sidebarCollapsed ? @js(__('admin.expand_sidebar')) : @js(__('admin.collapse_sidebar'))"
                        >
                            <template x-if="sidebarCollapsed">
                                <x-heroicon-o-chevron-double-right class="w-4 h-4" />
                            </template>
                            <template x-if="!sidebarCollapsed">
                                <x-heroicon-o-chevron-double-left class="w-4 h-4" />
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        <div class="drawer-content flex flex-col min-h-screen min-w-0 max-w-full overflow-x-hidden">
            <header class="panel-header navbar bg-base-100 border-b border-base-300 h-16 relative z-40" style="--navbar-padding: 0px">
                <div class="flex-1 flex items-center pl-4 sm:pl-6 gap-3">
                    {{-- Mobile Drawer Toggle --}}
                    <x-tooltip :tip="__('admin.toggle_navigation')" position="right">
                        <label for="sidebar-drawer" class="btn btn-ghost btn-sm lg:hidden">
                            <x-heroicon-o-bars-3 class="w-5 h-5" />
                        </label>
                    </x-tooltip>

                    {{-- Desktop Sidebar Mini Toggle (when in sidebar mode) --}}
                    <template x-if="layoutMode === 'sidebar'">
                        <div class="tooltip tooltip-right hidden lg:inline-flex" :data-tip="sidebarCollapsed ? @js(__('admin.expand_sidebar')) : @js(__('admin.collapse_sidebar'))">
                            <button
                                type="button"
                                @click="toggleSidebar()"
                                class="btn btn-ghost btn-sm btn-square"
                                :aria-label="sidebarCollapsed ? @js(__('admin.expand_sidebar')) : @js(__('admin.collapse_sidebar'))"
                            >
                                <x-heroicon-o-bars-3 class="w-5 h-5" />
                            </button>
                        </div>
                    </template>

                    {{-- Horizontal Mode Brand Header on Desktop --}}
                    <div x-show="layoutMode === 'horizontal'" class="hidden lg:flex items-center gap-3">
                        <div class="panel-brand-mark shrink-0">
                            <x-heroicon-o-phone class="w-5 h-5" />
                        </div>
                        <span class="text-base font-semibold tracking-tight">{{ config('app.name', 'TallPBX') }}</span>
                    </div>
                </div>
                <div class="flex-none flex items-center gap-2 sm:gap-3 pr-3 sm:pr-5">
                    @if(Auth::guard('web')->check() && $availableTenants->count() > 1)
                        <div class="dropdown dropdown-end">
                            <button
                                type="button"
                                tabindex="0"
                                class="btn btn-ghost btn-sm gap-2"
                                aria-label="{{ __('admin.switch_tenant') }}"
                                title="{{ __('admin.switch_tenant') }}"
                            >
                                <x-heroicon-o-building-office-2 class="w-4 h-4" />
                                <span class="hidden md:inline max-w-40 truncate">{{ $currentTenant?->name ?? __('admin.no_tenant') }}</span>
                                <x-heroicon-o-chevron-down class="w-3 h-3" />
                            </button>
                            <ul tabindex="0" class="dropdown-content z-50 menu mt-2 w-64 rounded-xl border border-base-300 bg-base-100 p-2 shadow-lg">
                                @foreach($availableTenants as $tenant)
                                    <li>
                                        <form method="POST" action="{{ route('panel.tenant.switch') }}">
                                            @csrf
                                            <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                                            <button type="submit" class="flex w-full items-center gap-3">
                                                <x-heroicon-o-building-office-2 class="w-4 h-4" />
                                                <span class="flex-1 truncate text-left">{{ $tenant->name }}</span>
                                                @if((string) $currentTenant?->id === (string) $tenant->id)
                                                    <x-heroicon-o-check class="w-4 h-4 text-primary" />
                                                @endif
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <a href="{{ route('panel.profile') }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-base-300 bg-base-200/45 hover:bg-base-200/80 px-3 py-2 leading-tight transition-colors cursor-pointer group"
                       title="{{ __('admin.my_profile') }}">
                        <x-heroicon-o-user-circle class="w-5 h-5 text-base-content/50 group-hover:text-primary transition-colors" />
                        <div class="text-right">
                            <div class="text-sm font-medium text-base-content/80 group-hover:text-base-content">
                                {{ $panelUser?->name }}
                            </div>
                            <div class="text-xs text-base-content/50">
                                @if($currentTenant)
                                    {{ $currentTenant->name }}
                                @else
                                    {{ Auth::guard('admin')->check() ? __('admin.administrator') : '' }}
                                @endif
                            </div>
                        </div>
                    </a>
                    <x-tooltip :tip="__('admin.change_language')" position="bottom">
                        <x-common.language-switcher />
                    </x-tooltip>
                    @livewire('layout.display-settings')
                    <x-tooltip :tip="__('admin.sign_out')" position="left">
                        <form method="POST" action="{{ route('panel.logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost btn-sm btn-square" aria-label="{{ __('admin.sign_out') }}">
                                <x-heroicon-o-arrow-right-on-rectangle class="w-5 h-5" />
                            </button>
                        </form>
                    </x-tooltip>
                </div>
            </header>

            {{-- Horizontal Topbar Navigation --}}
            <div x-show="layoutMode === 'horizontal'" class="relative z-30">
                <x-horizontal-navbar :menu-tree="$menuTree" />
            </div>

            <main class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden flex flex-col justify-between" wire:navigate:scroll>
                <div class="flex-1 min-w-0 p-4 sm:p-6">
                    @hasSection('content')
                        @yield('content')
                    @else
                        {{ $slot ?? '' }}
                    @endif
                </div>
                <footer class="w-full max-w-full px-4 sm:px-6 py-4 pt-4 border-t border-base-300 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs text-base-content/60">
                    <div class="min-w-0">{!! __('admin.copyright', ['year' => date('Y')]) !!}</div>
                    <div class="flex flex-wrap items-center gap-4 sm:justify-end">
                        <x-tooltip :tip="__('admin.app_version_tooltip')" class="inline-flex shrink-0 whitespace-nowrap"><span>{{ __('admin.app_version', ['version' => app()->version()]) }}</span></x-tooltip>
                        <x-tooltip :tip="__('admin.php_version_tooltip')" class="inline-flex shrink-0 whitespace-nowrap"><span>{{ __('admin.php_version', ['version' => PHP_VERSION]) }}</span></x-tooltip>
                        <x-tooltip :tip="__('admin.freeswitch_tooltip')" class="inline-flex shrink-0 whitespace-nowrap"><span>{{ app(\App\Support\FreeSwitchRuntimeVersion::class)->label() }}</span></x-tooltip>
                    </div>
                </footer>
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
