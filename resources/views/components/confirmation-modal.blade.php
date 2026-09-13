@props([
    'open' => false,
    'title' => 'Confirm action?',
    'message' => '',
    'confirmLabel' => 'Confirm',
    'confirmAction' => '',
    'cancelAction' => '',
    'error' => null,
    'errorTitle' => null,
    'requiredText' => '',
])
{{--
    Typed confirmation contract: when `required-text` is set, the host component
    must expose a public `confirmTypedInput` string property (bound below). The
    host re-checks the typed value server-side before performing the action.
--}}
@if ($open)
    {{-- The parent renders this modal conditionally, so Alpine state (`typed`)
         is recreated fresh on every open and needs no watch-based reset. --}}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="confirmation-modal-title" x-data="{ typed: '' }" x-on:keydown.escape.window="$wire.{{ $cancelAction }}()">
        <button type="button" class="absolute inset-0 cursor-default" aria-label="Close confirmation" wire:click="{{ $cancelAction }}"></button>
        <div class="relative w-full max-w-md rounded-box bg-base-100 p-6 text-center shadow-2xl">
            @if ($error !== null)
                <x-heroicon-o-x-circle class="mx-auto h-12 w-12 text-error" />
                <h2 id="confirmation-modal-title" class="mt-2 text-lg font-semibold">{{ $errorTitle ?? $title }}</h2>
                <x-inline-alert type="error" class="mt-4 text-left">{{ $error }}</x-inline-alert>
                <div class="mt-6 flex justify-center">
                    <button type="button" class="btn" wire:click="{{ $cancelAction }}">{{ __('admin.modal_close') }}</button>
                </div>
            @else
                <x-heroicon-o-exclamation-triangle class="mx-auto h-12 w-12 text-warning" />
                <h2 id="confirmation-modal-title" class="mt-2 text-lg font-semibold">{{ $title }}</h2>
                <p class="mt-3 text-sm text-base-content/70">{{ $message }}</p>
                @if ($slot->isNotEmpty())
                    <div class="mt-4 text-left">{{ $slot }}</div>
                @endif
                @if ($requiredText !== '')
                    {{-- Typed confirmation: the admin must type the exact text. The disabled
                         state is convenience only; the component action re-checks server-side. --}}
                    <input type="text" class="input input-bordered input-sm w-full mt-4 text-center"
                           x-model="typed" wire:model="confirmTypedInput"
                           placeholder="{{ __('admin.modal_typed_placeholder') }}" autocomplete="off" />
                @endif
                <div class="mt-6 flex justify-center gap-3">
                    <button type="button" class="btn" wire:click="{{ $cancelAction }}">{{ __('admin.modal_cancel') }}</button>
                    <button type="button" class="btn btn-error" wire:click="{{ $confirmAction }}"
                            @if ($requiredText !== '') :disabled="typed.trim() !== @js($requiredText)" @endif>{{ $confirmLabel }}</button>
                </div>
            @endif
        </div>
    </div>
@endif
