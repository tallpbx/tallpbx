<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ __('admin.click_to_call_title') }}</h1>
        <p class="text-base-content/60 mb-6">{{ __('admin.click_to_call_description') }}</p>
        @if ($operationalMessage !== null)
            <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
                {{ $operationalMessage }}
            </x-inline-alert>
        @endif
        <form wire:submit="initiateCall" class="space-y-6 max-w-lg">
            <div class="form-control w-full">
                <label class="label" for="phoneNumber"><span class="label-text">{{ __('admin.click_to_call_number') }}</span></label>
                <input wire:model="phoneNumber" id="phoneNumber" type="text" class="input input-bordered w-full font-mono" placeholder="+15551234567" />
                @error('phoneNumber')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label" for="extension"><span class="label-text">{{ __('admin.click_to_call_extension') }}</span></label>
                <input wire:model="extension" id="extension" type="text" class="input input-bordered w-full" placeholder="1001" />
                @error('extension')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="initiateCall">
                    @if($calling)<span class="loading loading-spinner"></span>@endif
                    {{ __('admin.click_to_call_button') }}
                </button>
            </div>
        </form>
    </div>
</div>
