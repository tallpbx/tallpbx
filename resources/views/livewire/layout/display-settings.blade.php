<div class="dropdown dropdown-end">
    <x-tooltip :tip="__('admin.display_settings')" position="bottom">
        <button
            type="button"
            tabindex="0"
            class="btn btn-ghost btn-sm btn-square"
            aria-label="{{ __('admin.display_settings') }}"
        >
            <x-heroicon-o-adjustments-horizontal class="w-5 h-5" />
        </button>
    </x-tooltip>

    <div tabindex="0" class="dropdown-content z-50 menu mt-2 w-72 rounded-xl border border-base-300 bg-base-100 p-4 shadow-xl text-xs font-medium">
        <div class="flex flex-col gap-4">
            {{-- Layout Style Selector --}}
            <div class="flex flex-col gap-1.5">
                <span class="text-base-content/60 uppercase tracking-wider text-[11px] font-semibold flex items-center gap-1.5">
                    <x-heroicon-o-view-columns class="w-3.5 h-3.5" />
                    {{ __('admin.layout_style') }}
                </span>
                <div class="join w-full grid grid-cols-2">
                    <button
                        type="button"
                        wire:click="setLayoutMode('sidebar')"
                        class="btn btn-xs join-item gap-1.5 {{ $layoutMode === 'sidebar' ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                    >
                        <x-heroicon-o-view-columns class="w-3.5 h-3.5" />
                        {{ __('admin.layout_sidebar') }}
                    </button>
                    <button
                        type="button"
                        wire:click="setLayoutMode('horizontal')"
                        class="btn btn-xs join-item gap-1.5 {{ $layoutMode === 'horizontal' ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                    >
                        <x-heroicon-o-bars-4 class="w-3.5 h-3.5" />
                        {{ __('admin.layout_horizontal') }}
                    </button>
                </div>
            </div>

            {{-- Sidebar Size (Only relevant when in sidebar mode) --}}
            @if ($layoutMode === 'sidebar')
                <div class="flex flex-col gap-1.5">
                    <span class="text-base-content/60 uppercase tracking-wider text-[11px] font-semibold flex items-center gap-1.5">
                        <x-heroicon-o-arrows-pointing-in class="w-3.5 h-3.5" />
                        {{ __('admin.sidebar_size') }}
                    </span>
                    <div class="join w-full grid grid-cols-2">
                        <button
                            type="button"
                            wire:click="setSidebarCollapsed(false)"
                            class="btn btn-xs join-item gap-1.5 {{ ! $sidebarCollapsed ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                        >
                            {{ __('admin.sidebar_expanded') }}
                        </button>
                        <button
                            type="button"
                            wire:click="setSidebarCollapsed(true)"
                            class="btn btn-xs join-item gap-1.5 {{ $sidebarCollapsed ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                        >
                            {{ __('admin.sidebar_collapsed') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- Color Theme Selector --}}
            <div class="flex flex-col gap-1.5">
                <span class="text-base-content/60 uppercase tracking-wider text-[11px] font-semibold flex items-center gap-1.5">
                    <x-heroicon-o-sun class="w-3.5 h-3.5" />
                    {{ __('admin.theme') ?? 'Theme' }}
                </span>
                <div class="join w-full grid grid-cols-3">
                    <button
                        type="button"
                        wire:click="setTheme('light')"
                        class="btn btn-xs join-item gap-1 {{ $theme === 'light' ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                        title="{{ __('admin.light') }}"
                    >
                        <x-heroicon-o-sun class="w-3.5 h-3.5" />
                        {{ __('admin.light') }}
                    </button>
                    <button
                        type="button"
                        wire:click="setTheme('dark')"
                        class="btn btn-xs join-item gap-1 {{ $theme === 'dark' ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                        title="{{ __('admin.dark') }}"
                    >
                        <x-heroicon-o-moon class="w-3.5 h-3.5" />
                        {{ __('admin.dark') }}
                    </button>
                    <button
                        type="button"
                        wire:click="setTheme('system')"
                        class="btn btn-xs join-item gap-1 {{ $theme === 'system' ? 'btn-active btn-primary' : 'btn-ghost border-base-300' }}"
                        title="{{ __('admin.system') }}"
                    >
                        <x-heroicon-o-computer-desktop class="w-3.5 h-3.5" />
                        {{ __('admin.system') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
