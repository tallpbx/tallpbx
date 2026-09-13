@props(['type' => 'info', 'title' => null])

@php
    $icons = [
        'success' => 'heroicon-o-check-circle',
        'warning' => 'heroicon-o-exclamation-triangle',
        'error' => 'heroicon-o-x-circle',
        'info' => 'heroicon-o-information-circle',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'alert alert-'.$type]) }} role="alert">
    <x-dynamic-component :component="$icons[$type] ?? $icons['info']" class="h-5 w-5 shrink-0" />
    <div>
        @if ($title !== null)
            <h2 class="font-semibold">{{ $title }}</h2>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
