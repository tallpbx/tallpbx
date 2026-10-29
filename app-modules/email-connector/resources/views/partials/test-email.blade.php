{{-- Test email section --}}
<div class="card bg-base-100 border border-base-300 p-6 mt-6 space-y-4">
    <div class="flex items-center gap-2">
        <h3 class="text-lg font-semibold">{{ __('admin.smtp_test_email') }}</h3>
        <x-tooltip :tip="__('admin.smtp_test_email_tooltip')" position="left">
            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-40 hover:opacity-80" />
        </x-tooltip>
    </div>

    @if ($testResult !== null)
        <x-inline-alert :type="$testSuccess ? 'success' : 'error'" :title="null">
            {{ $testResult }}
        </x-inline-alert>
    @endif

    <div class="flex items-end gap-4">
        <div class="form-control flex-1">
            <label class="label" for="testEmailAddress">
                <span class="label-text">{{ __('admin.smtp_test_recipient') }}</span>
            </label>
            <input type="email" id="testEmailAddress" wire:model="testEmailAddress"
                   class="input input-bordered w-full" placeholder="admin@example.com" />
            <x-form-error name="testEmailAddress" />
        </div>
        <button type="button" wire:click="sendTest" class="btn btn-outline"
                :disabled="!$wire.isConfigured">
            <x-heroicon-o-paper-airplane class="w-4 h-4" />
            {{ __('admin.smtp_send_test') }}
        </button>
    </div>
</div>

