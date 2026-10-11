@props([
    'message' => null,
    'type' => null,
    'timeout' => 5000,
])

{{--
    Standard toast layer for transient action feedback.

    See the tallpbx-custom skill ("UI Alert & Feedback Patterns") for the
    project-wide convention. Fixed to the top-right at a z-index above open
    dialogs, with a close button that clears the message server-side via
    HasOperationalFeedback::dismissFeedback so no page refresh is needed.
    Also renders cross-redirect session flashes (status / error) through the
    same box so every page shows feedback in exactly one way.

    Auto-dismiss: Success and informational toasts automatically dismiss after
    5 seconds with pause-on-hover and an animated countdown progress bar,
    while warnings and errors remain persistent until manually dismissed or resolved.

    Usage: <x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />
--}}
@if ($message || session('status') || session('error'))
    @php
        $effectiveType = $message ? ($type ?? 'success') : (session('status') ? 'success' : 'error');
        $effectiveMessage = $message ?? session('status') ?? session('error');
        $isAutoDismiss = in_array($effectiveType, ['success', 'info'], true);
        $alertClass = $effectiveType === 'error' ? 'alert-error' : ($effectiveType === 'warning' ? 'alert-warning' : 'alert-success');
    @endphp

    @if ($isAutoDismiss)
        <style>
            @keyframes toast-progress {
                0% { width: 100%; }
                100% { width: 0%; }
            }
            .toast-paused {
                animation-play-state: paused !important;
            }
        </style>
    @endif

    <div
        wire:key="operational-toast-{{ md5($effectiveType . ':' . $effectiveMessage) }}"
        x-data="{
            show: true,
            timer: null,
            remaining: {{ (int) $timeout }},
            startTime: null,
            paused: false,
            init() {
                this.reset();
                if (typeof this.$wire !== 'undefined' && this.$wire) {
                    this.$watch('$wire.operationalMessage', (val) => {
                        if (val) this.reset();
                    });
                }
            },
            reset() {
                this.show = true;
                this.remaining = {{ (int) $timeout }};
                this.paused = false;
                if (@json($isAutoDismiss)) {
                    this.startTimer();
                }
            },
            startTimer() {
                this.clearTimer();
                this.startTime = Date.now();
                this.timer = setTimeout(() => {
                    this.dismiss();
                }, this.remaining);
            },
            pause() {
                if (!@json($isAutoDismiss) || this.paused || !this.startTime) return;
                this.clearTimer();
                this.remaining -= (Date.now() - this.startTime);
                this.paused = true;
            },
            resume() {
                if (!@json($isAutoDismiss) || !this.paused) return;
                this.paused = false;
                if (this.remaining > 0) {
                    this.startTimer();
                }
            },
            clearTimer() {
                if (this.timer) {
                    clearTimeout(this.timer);
                    this.timer = null;
                }
            },
            dismiss() {
                this.show = false;
                try {
                    if (typeof this.$wire !== 'undefined' && this.$wire) {
                        this.$wire.operationalMessage = null;
                        this.$wire.operationalMessageType = null;
                    }
                } catch (e) {}
            }
        }"
        x-show="show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
        @mouseenter="pause()"
        @mouseleave="resume()"
        class="toast toast-top toast-end z-[9999]"
        role="{{ $effectiveType === 'error' ? 'alert' : 'status' }}"
        aria-live="{{ $effectiveType === 'error' ? 'assertive' : 'polite' }}"
    >
        <div class="alert {{ $alertClass }} shadow-md relative overflow-hidden pb-3.5" role="alert">
            <span>{{ $effectiveMessage }}</span>
            <button
                wire:click="dismissFeedback"
                @click="dismiss()"
                type="button"
                class="btn btn-ghost btn-xs btn-circle"
                title="{{ __('client.close') }}"
            >
                <x-heroicon-o-x-mark class="w-4 h-4" />
            </button>

            @if ($isAutoDismiss)
                {{-- Visible countdown track and moving progress bar --}}
                <div class="absolute inset-x-0 bottom-0 h-0.5 bg-black/15 overflow-hidden pointer-events-none">
                    <div
                        class="h-full bg-current opacity-75"
                        :class="{ 'toast-paused': paused }"
                        style="animation: toast-progress {{ (int) $timeout }}ms linear forwards;"
                    ></div>
                </div>
            @endif
        </div>
    </div>
@endif
