{{--
    Intentional deviations from <x-confirmation-modal>:
    1. Driven client-side via Alpine `x-show` rather than server `@if ($open)` because
       this modal opens specifically when Livewire requests can no longer roundtrip (HTTP 419).
    2. Omits Escape-key and backdrop dismiss because an expired session requires a page
       reload / re-authentication before any panel action can succeed.
--}}
<div
    x-data="{ open: false }"
    x-on:tallpbx:session-expired.window="open = true"
    x-cloak
>
    <div
        x-show="open"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="session-expired-modal-title"
    >
        <div class="relative w-full max-w-md rounded-box bg-base-100 p-6 text-center shadow-2xl">
            <x-heroicon-o-clock class="mx-auto h-12 w-12 text-warning" />
            <h2 id="session-expired-modal-title" class="mt-2 text-lg font-semibold">
                {{ __('admin.session_expired_title') }}
            </h2>
            <p class="mt-3 text-sm text-base-content/70">
                {{ __('admin.session_expired_message') }}
            </p>
            <div class="mt-6 flex justify-center">
                <button
                    type="button"
                    @click="window.location.reload()"
                    class="btn btn-primary"
                >
                    {{ __('admin.session_expired_action') }}
                </button>
            </div>
        </div>
    </div>
</div>

