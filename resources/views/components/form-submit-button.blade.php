{{-- resources/views/components/form-submit-button.blade.php --}}
@props([
    'target' => 'save',
    'busyText' => null,
    'icon' => null,
    'loading' => true,
])

@php
    $busyLabel = $busyText ?? __('admin.saving');
    $effectiveTarget = ($target !== false && $target !== '' && $target !== null) ? $target : null;
@endphp

<button
    {{ $attributes->merge(['class' => 'btn btn-primary btn-sm', 'type' => 'submit']) }}
    @if($loading)
        wire:loading.attr="disabled"
        @if($effectiveTarget) wire:target="{{ $effectiveTarget }}" @endif
    @endif
>
    <span
        @if($loading)
            @if($effectiveTarget) wire:target="{{ $effectiveTarget }}" @endif
            wire:loading.remove
        @endif
        class="inline-flex items-center gap-1.5"
    >
        @if($icon)
            <x-dynamic-component :component="$icon" class="w-4 h-4" />
        @endif
        {{ $slot->isEmpty() ? __('admin.save') : $slot }}
    </span>
    @if($loading)
        <span
            @if($effectiveTarget) wire:target="{{ $effectiveTarget }}" @endif
            wire:loading
            class="inline-flex items-center gap-1.5"
        >
            <span class="loading loading-spinner loading-xs"></span>
            <span>{{ $busyLabel }}</span>
        </span>
    @endif
</button>
