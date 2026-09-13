@props([
    'tip' => null,
    'position' => 'top',
    'icon' => null,
    'align' => null,
])

@php
    $positionClass = $position !== 'top' ? 'tooltip-' . $position : '';
    $alignClass = $align !== null ? 'tooltip-' . $align : '';
    $hasContent = isset($content) && $content->isNotEmpty();
    $hasTrigger = isset($trigger) && $trigger->isNotEmpty();
    $hasDefaultSlot = $slot->isNotEmpty();
    $useDataTip = ! empty($tip) && ! $hasContent;
@endphp

<div {{ $attributes->merge(['class' => trim('tooltip ' . $positionClass . ' ' . $alignClass)]) }}
     @if ($useDataTip) data-tip="{{ $tip }}" @endif>
    @if ($hasTrigger)
        {{ $trigger }}
    @elseif ($hasDefaultSlot)
        {{ $slot }}
    @elseif (! empty($icon))
        <x-dynamic-component :component="$icon" class="w-5 h-5 cursor-help opacity-70 hover:opacity-100" />
    @endif

    @if ($hasContent)
        <div class="tooltip-content">{{ $content }}</div>
    @endif
</div>
