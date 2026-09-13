@php
    $isGroup = empty($item['route']) && ! empty($item['children']);
    $isLeaf = ! empty($item['route']);
    $routePattern = $item['route'] ?? null;
    $isActive = $routePattern && request()->routeIs($routePattern);
    $isActiveParent = $routePattern && request()->routeIs($routePattern.'.*');
    $hasFlatChildren = ! empty($item['flat_children']);
@endphp

@if ($isGroup && $hasFlatChildren)
    {{-- Top-level group wrapper with flat children (e.g. 'pbx') --}}
    <li class="menu-title mt-4 first:mt-0" x-show="!sidebarCollapsed">
        <span class="flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">
            @if (! empty($item['icon']))
                @php $iconComponent = $item['icon']; @endphp
                <x-dynamic-component :component="$iconComponent" class="w-4 h-4 shrink-0" />
            @endif
            {{ __($item['label']) }}
        </span>
    </li>
    <li class="my-1 border-t border-base-300" x-show="sidebarCollapsed"></li>
    @foreach ($item['children'] ?? [] as $child)
        @include('components.sidebar-menu-item', ['item' => $child, 'guard' => $guard, 'persistedNavigation' => $persistedNavigation ?? false])
    @endforeach

@elseif ($isGroup)
    {{-- Standard Group (e.g. pbx.accounts, pbx.routing) --}}
    {{-- 1. Expanded view: Section title + nested list --}}
    <li class="menu-title mt-4 first:mt-0" x-show="!sidebarCollapsed">
        <span class="flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">
            @if (! empty($item['icon']))
                @php $iconComponent = $item['icon']; @endphp
                <x-dynamic-component :component="$iconComponent" class="w-4 h-4 shrink-0" />
            @endif
            {{ __($item['label']) }}
        </span>
        <ul class="ml-0 mt-1">
            @foreach ($item['children'] ?? [] as $child)
                @include('components.sidebar-menu-item', ['item' => $child, 'guard' => $guard, 'persistedNavigation' => $persistedNavigation ?? false])
            @endforeach
        </ul>
    </li>

    {{-- 2. Mini Rail view: Icon button with hover/click flyout popover --}}
    <li class="relative my-0.5"
        x-show="sidebarCollapsed"
        x-data="{ flyoutOpen: false }"
        @mouseenter="flyoutOpen = true"
        @mouseleave="flyoutOpen = false">
        <button
            type="button"
            @click="flyoutOpen = !flyoutOpen"
            :title="@js(__($item['label']))"
            class="flex items-center justify-center w-full h-9 rounded-lg text-base-content/70 hover:text-base-content hover:bg-base-200 transition-colors"
            aria-label="{{ __($item['label']) }}"
        >
            @if (! empty($item['icon']))
                @php $iconComponent = $item['icon']; @endphp
                <x-dynamic-component :component="$iconComponent" class="w-5 h-5 shrink-0" />
            @else
                <x-heroicon-o-folder class="w-5 h-5 shrink-0" />
            @endif
        </button>

        {{-- Flyout Popover Menu --}}
        <div
            x-show="flyoutOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-x-1"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-1"
            class="absolute left-full top-0 ml-2 z-50 bg-base-100 border border-base-300 rounded-xl shadow-2xl p-2 w-60 flex flex-col gap-1 text-sm"
            style="display: none;"
        >
            <div class="px-3 py-1.5 font-bold uppercase tracking-wider text-xs text-base-content/50 border-b border-base-300 flex items-center gap-2">
                @if (! empty($item['icon']))
                    @php $iconComponent = $item['icon']; @endphp
                    <x-dynamic-component :component="$iconComponent" class="w-4 h-4" />
                @endif
                {{ __($item['label']) }}
            </div>
            <ul class="menu menu-sm p-0 gap-0.5">
                @foreach ($item['children'] ?? [] as $child)
                    @if (! empty($child['route']))
                        <li>
                            <a href="{{ route($child['route']) }}"
                               wire:navigate.preserve-scroll
                               wire:current="menu-active"
                               class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-normal text-base-content/80 hover:text-base-content hover:bg-base-200">
                                @if (! empty($child['icon']))
                                    @php $childIcon = $child['icon']; @endphp
                                    <x-dynamic-component :component="$childIcon" class="w-4 h-4 shrink-0" />
                                @endif
                                <span class="truncate">{{ __($child['label']) }}</span>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </li>

@elseif ($isLeaf)
    {{-- Leaf Route Link --}}
    <li>
        <a href="{{ route($item['route']) }}"
           wire:navigate.preserve-scroll
           wire:current="menu-active"
           :title="sidebarCollapsed ? @js(__($item['label'])) : ''"
           :class="sidebarCollapsed ? 'justify-center px-0 h-9' : 'gap-3 px-3'"
           class="flex items-center font-normal rounded-lg transition-colors data-current:menu-active {{ ! ($persistedNavigation ?? false) && ($isActive || $isActiveParent) ? 'menu-active' : '' }}">
            @if (! empty($item['icon']))
                @php $iconComponent = $item['icon']; @endphp
                <x-dynamic-component :component="$iconComponent" class="w-5 h-5 shrink-0" />
            @endif
            <span x-show="!sidebarCollapsed" class="truncate">{{ __($item['label']) }}</span>
        </a>
    </li>
@endif
