@props([
    'tabs' => [],
    'active' => null,
    'wireMethod' => 'setTab',
])

<div class="overflow-x-auto">
    <div role="tablist" {{ $attributes->merge(['class' => 'tabs tabs-lift tabs-sm']) }}>
        @foreach ($tabs as $key => $tab)
            @php
                $tabKey = is_string($key) ? $key : ($tab['key'] ?? (string) $key);
                $label = is_array($tab) ? ($tab['label'] ?? $tabKey) : $tab;
                $icon = is_array($tab) ? ($tab['icon'] ?? null) : null;
                $badge = is_array($tab) ? ($tab['badge'] ?? null) : null;
                $badgeClass = is_array($tab) ? ($tab['badgeClass'] ?? 'badge-neutral') : 'badge-neutral';
                $tooltip = is_array($tab) ? ($tab['tooltip'] ?? null) : null;
                $isActive = (string) $active === (string) $tabKey;

                if (str_contains($wireMethod, ':key')) {
                    $clickAction = str_replace(':key', (string) $tabKey, $wireMethod);
                } elseif (str_contains($wireMethod, '%s')) {
                    $clickAction = sprintf($wireMethod, (string) $tabKey);
                } else {
                    $clickAction = "{$wireMethod}('{$tabKey}')";
                }
            @endphp
            <button
                role="tab"
                type="button"
                id="tab-{{ $tabKey }}"
                wire:click="{{ $clickAction }}"
                class="tab gap-1.5 {{ $isActive ? 'tab-active' : '' }}"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
            >
                @if ($icon)
                    <x-dynamic-component :component="$icon" class="w-4 h-4" />
                @endif
                <span>{{ $label }}</span>
                @if ($badge !== null)
                    @if ($tooltip)
                        <x-tooltip :tip="$tooltip" position="bottom">
                            <span class="badge {{ $badgeClass }} badge-xs font-semibold">{{ $badge }}</span>
                        </x-tooltip>
                    @else
                        <span class="badge {{ $badgeClass }} badge-xs font-semibold">{{ $badge }}</span>
                    @endif
                @endif
            </button>
        @endforeach
    </div>
</div>
