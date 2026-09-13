@props(['menuTree' => null])

@php
    $tree = $menuTree ?? app(\App\Services\MenuService::class)->getTree();
@endphp

<nav class="hidden lg:flex items-center w-full px-4 py-1 border-b border-base-300 bg-base-100/95 backdrop-blur-md overflow-visible relative" aria-label="{{ __('admin.layout_horizontal') }}">
    <ul class="menu menu-horizontal p-0 gap-1 text-sm font-medium">
        @foreach ($tree as $item)
            @include('components.horizontal-menu-item', ['item' => $item, 'depth' => 0])
        @endforeach
    </ul>
</nav>
