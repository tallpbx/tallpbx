<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="__('admin.event_rate_limits_title')" :tooltip="__('admin.event_rate_limits_tooltip')" :subtitle="__('admin.event_rate_limits_description')" />
        <form wire:submit="save" class="space-y-6 max-w-lg">
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_rate_limits_max_events_tooltip')" align="start" position="right">
                    <label class="label" for="maxEventsPerMinute">
                        <span class="label-text">{{ __('admin.event_rate_limits_max_events') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="maxEventsPerMinute" id="maxEventsPerMinute" type="number" min="1" class="input input-bordered w-full" />
                <x-form-error name="maxEventsPerMinute" />
            </div>
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_rate_limits_burst_tooltip')" align="start" position="right">
                    <label class="label" for="burstLimit">
                        <span class="label-text">{{ __('admin.event_rate_limits_burst') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="burstLimit" id="burstLimit" type="number" min="1" class="input input-bordered w-full" />
                <x-form-error name="burstLimit" />
            </div>
            <div class="form-control w-full">
                <x-tooltip :tip="__('admin.event_rate_limits_block_duration_tooltip')" align="start" position="right">
                    <label class="label" for="blockDuration">
                        <span class="label-text">{{ __('admin.event_rate_limits_block_duration') }}</span>
                    </label>
                </x-tooltip>
                <input wire:model="blockDuration" id="blockDuration" type="number" min="1" class="input input-bordered w-full" />
                <x-form-error name="blockDuration" />
            </div>
            <div class="flex gap-3">
                <x-form-submit-button>{{ __('client.save') }}</x-form-submit-button>
            </div>
        </form>
    </div>
</div>
