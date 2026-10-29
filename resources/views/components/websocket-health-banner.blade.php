<div x-data="{
        disconnected: false,
        init() {
            window.addEventListener('tallpbx:ws-disconnected', () => {
                this.disconnected = true;
            });
            window.addEventListener('tallpbx:ws-reconnected', () => {
                if (this.disconnected) {
                    this.disconnected = false;
                    if (window.Livewire) {
                        window.Livewire.dispatch('echo-reconnected');
                    }
                }
            });
        }
    }"
    x-show="disconnected"
    x-cloak
    role="status"
    aria-live="polite"
    {{ $attributes->merge(['class' => 'alert alert-warning text-xs mb-4 shadow-sm flex items-center justify-between py-2 px-3']) }}
>
    <div class="flex items-center gap-2">
        <span class="loading loading-spinner loading-xs text-warning"></span>
        <span>{{ __('admin.realtime_reconnecting') }}</span>
    </div>
</div>

