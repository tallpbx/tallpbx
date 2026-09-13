<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <div class="flex items-center gap-2 mb-6">
            <h1 class="text-2xl font-bold">{{ __('admin.event_guard_title') }}</h1>
            <x-tooltip :tip="__('admin.event_guard_tooltip')" align="start" position="right">
                <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
            </x-tooltip>
        </div>
        <p class="text-base-content/60 mb-6">{{ __('admin.event_guard_description') }}</p>
        <form wire:submit="save" class="space-y-6 max-w-lg">
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_guard_max_events_tooltip')" align="start" position="right">
                    <label class="label" for="maxEventsPerMinute">
                        <span class="label-text">{{ __('admin.event_guard_max_events') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="maxEventsPerMinute" id="maxEventsPerMinute" type="number" min="1" class="input input-bordered w-full" />
                @error('maxEventsPerMinute')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_guard_burst_tooltip')" align="start" position="right">
                    <label class="label" for="burstLimit">
                        <span class="label-text">{{ __('admin.event_guard_burst') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="burstLimit" id="burstLimit" type="number" min="1" class="input input-bordered w-full" />
                @error('burstLimit')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_guard_block_duration_tooltip')" align="start" position="right">
                    <label class="label" for="blockDuration">
                        <span class="label-text">{{ __('admin.event_guard_block_duration') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="blockDuration" id="blockDuration" type="number" min="1" class="input input-bordered w-full" />
                @error('blockDuration')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
            </div>
        </form>
    </div>
</div>
