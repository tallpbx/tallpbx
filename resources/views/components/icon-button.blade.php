{{-- resources/views/components/icon-button.blade.php --}}
@props([
    'icon',
    'label' => null,
    'href' => null,
])

@php
    // Fail loudly instead of shipping an unlabeled icon-only control: the
    // label is the accessible name screen readers announce.
    if (blank($label)) {
        throw new \InvalidArgumentException('The x-icon-button component requires a non-empty label.');
    }

    // Render a link for navigation actions and a real button otherwise.
    $tag = $href !== null ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" wire:navigate @endif
    @if ($tag === 'button') type="button" @endif
    aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'btn btn-ghost btn-xs']) }}
>
    <x-dynamic-component :component="$icon" class="w-4 h-4" />
</{{ $tag }}>
