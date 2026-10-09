{{-- resources/views/components/icon-button.blade.php --}}
@props([
    'icon',
    'label' => null,
    'href' => null,
    'loadingTarget' => null,
    'loading' => true,
])

@php
    // Fail loudly instead of shipping an unlabeled icon-only control: the
    // label is the accessible name screen readers announce.
    if (blank($label)) {
        throw new \InvalidArgumentException('The x-icon-button component requires a non-empty label.');
    }

    // Render a link for navigation actions and a real button otherwise.
    $tag = $href !== null ? 'a' : 'button';
    $target = $loadingTarget ?: ($loading ? $attributes->get('wire:click') : null);
@endphp

<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" wire:navigate @endif
    @if ($tag === 'button') type="button" @endif
    @if ($target !== null)
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
    @endif
    aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'btn btn-ghost btn-xs']) }}
>
    @if ($target !== null)
        <x-dynamic-component :component="$icon" class="w-4 h-4" wire:loading.remove wire:target="{{ $target }}" />
        <span class="loading loading-spinner loading-xs" wire:loading wire:target="{{ $target }}"></span>
    @else
        <x-dynamic-component :component="$icon" class="w-4 h-4" />
    @endif
</{{ $tag }}>
