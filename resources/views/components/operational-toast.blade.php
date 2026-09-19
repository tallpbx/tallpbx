@props([
    'message' => null,
    'type' => null,
])

{{--
    Standard toast layer for transient action feedback.

    See the tallpbx-custom skill ("UI Alert & Feedback Patterns") for the
    project-wide convention. Fixed to the top-right at a z-index above open
    dialogs, with a close button that clears the message server-side via
    HasOperationalFeedback::dismissFeedback so no page refresh is needed.
    Also renders cross-redirect session flashes (status / error) through the
    same box so every page shows feedback in exactly one way.

    Usage: <x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />
--}}
@if ($message || session('status') || session('error'))
    <div class="toast toast-top toast-end z-[9999]">
        @if ($message)
            <div class="alert alert-{{ $type === 'error' ? 'error' : ($type === 'warning' ? 'warning' : 'success') }} shadow-sm" role="alert">
                <span>{{ $message }}</span>
                <button wire:click="dismissFeedback" type="button" class="btn btn-ghost btn-xs btn-circle" title="{{ __('client.close') }}">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>
        @elseif (session('status'))
            <div class="alert alert-success shadow-sm" role="alert">
                <span>{{ session('status') }}</span>
                <button wire:click="dismissFeedback" type="button" class="btn btn-ghost btn-xs btn-circle" title="{{ __('client.close') }}">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>
        @else
            <div class="alert alert-error shadow-sm" role="alert">
                <span>{{ session('error') }}</span>
                <button wire:click="dismissFeedback" type="button" class="btn btn-ghost btn-xs btn-circle" title="{{ __('client.close') }}">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>
        @endif
    </div>
@endif
