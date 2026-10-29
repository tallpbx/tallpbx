@props([
    'title',
    'tooltip' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6']) }}>
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ $title }}</h1>
            @if ($tooltip)
                <x-tooltip :tip="$tooltip" align="start" position="right">
                    <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
                </x-tooltip>
            @endif
        </div>
        @if ($subtitle)
            <p class="text-xs text-base-content/60 mt-1">{{ $subtitle }}</p>
        @endif
    </div>

    @if (isset($actions) && $actions->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @elseif ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
