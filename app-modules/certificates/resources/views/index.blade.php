<div>
    {{-- List and edit components inheriting TallPBX base components receive these safe operational messages. --}}
    @if (($operationalMessage ?? null) !== null)
        <x-inline-alert :type="$operationalMessageType ?? 'info'">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    <p class="text-gray-600">{{ __('Module loaded successfully.') }}</p>
</div>