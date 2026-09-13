@php
    $isGroup = empty($item['route']) && ! empty($item['children']);
    $isLeaf = ! empty($item['route']);
    $routePattern = $item['route'] ?? null;
    $isActive = $routePattern && request()->routeIs($routePattern);
    $isActiveParent = $routePattern && request()->routeIs($routePattern.'.*');
    $depth = $depth ?? 0;
@endphp

@if ($isGroup && $depth === 0)
    {{-- Top-level dropdown --}}
    <li class="relative">
        <details class="dropdown">
            <summary class="flex items-center gap-2 px-3 py-2 rounded-lg font-medium text-sm text-base-content/80 hover:text-base-content hover:bg-base-200 transition-colors cursor-pointer select-none">
                @if (! empty($item['icon']))
                    <x-dynamic-component :component="$item['icon']" class="w-4 h-4" />
                @endif
                <span>{{ __($item['label']) }}</span>
            </summary>
            <ul class="dropdown-content z-50 menu p-2 shadow-xl bg-base-100 rounded-xl border border-base-300 min-w-56 mt-1 flex flex-col gap-0.5 text-sm font-normal">
                @foreach ($item['children'] ?? [] as $child)
                    @include('components.horizontal-menu-item', ['item' => $child, 'depth' => $depth + 1])
                @endforeach
            </ul>
        </details>
    </li>
@elseif ($isGroup && $depth > 0)
    {{-- Nested sub-group inside dropdown --}}
    @if (! empty($item['flat_children']))
        <li class="menu-title px-2 py-1.5 mt-1.5 first:mt-0 text-xs font-bold uppercase tracking-wider text-base-content/50">
            <span class="flex items-center gap-2">
                @if (! empty($item['icon']))
                    <x-dynamic-component :component="$item['icon']" class="w-4 h-4" />
                @endif
                {{ __($item['label']) }}
            </span>
        </li>
        @foreach ($item['children'] ?? [] as $child)
            @include('components.horizontal-menu-item', ['item' => $child, 'depth' => $depth + 1])
        @endforeach
    @else
        <li>
            <details>
                <summary class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-sm font-medium text-base-content/80 hover:text-base-content hover:bg-base-200">
                    <span class="flex items-center gap-2">
                        @if (! empty($item['icon']))
                            <x-dynamic-component :component="$item['icon']" class="w-4 h-4" />
                        @endif
                        <span>{{ __($item['label']) }}</span>
                    </span>
                </summary>
                <ul class="p-2 bg-base-100 rounded-xl border border-base-300 shadow-xl min-w-52 text-sm font-normal">
                    @foreach ($item['children'] ?? [] as $child)
                        @include('components.horizontal-menu-item', ['item' => $child, 'depth' => $depth + 1])
                    @endforeach
                </ul>
            </details>
        </li>
    @endif
@elseif ($isLeaf)
    {{-- Leaf link --}}
    <li>
        <a href="{{ route($item['route']) }}"
           wire:navigate.preserve-scroll
           wire:current="menu-active"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-normal text-sm text-base-content/80 hover:text-base-content hover:bg-base-200 transition-colors {{ ($isActive || $isActiveParent) ? 'menu-active text-primary bg-primary/10' : '' }}">
            @if (! empty($item['icon']))
                <x-dynamic-component :component="$item['icon']" class="w-4 h-4 shrink-0" />
            @endif
            <span class="truncate">{{ __($item['label']) }}</span>
        </a>
    </li>
@endif
