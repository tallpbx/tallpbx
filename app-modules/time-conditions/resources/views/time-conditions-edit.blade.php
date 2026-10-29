<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.time_condition')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_tenant') }}</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.time_condition_name') }}</span>
                </label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Business Hours" />
                <x-form-error name="name" />
            </div>

            {{-- Weekdays --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="weekdays">
                    <span class="label-text font-medium">{{ __('admin.time_condition_weekdays') }}</span>
                </label>
                <input wire:model="weekdays" type="text" id="weekdays" class="input input-bordered w-full" placeholder="e.g., mon,tue,wed,thu,fri" />
                <x-form-error name="weekdays" />
            </div>

            {{-- Time Window --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="startTime">
                        <span class="label-text font-medium">{{ __('admin.time_condition_start_time') }}</span>
                    </label>
                    <input wire:model="startTime" type="time" id="startTime" class="input input-bordered w-full" />
                    <x-form-error name="startTime" />
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="endTime">
                        <span class="label-text font-medium">{{ __('admin.time_condition_end_time') }}</span>
                    </label>
                    <input wire:model="endTime" type="time" id="endTime" class="input input-bordered w-full" />
                    <x-form-error name="endTime" />
                </div>
            </div>

            {{-- Match Destination --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="destinationOnMatch">
                    <span class="label-text font-medium">{{ __('admin.time_condition_match_destination') }}</span>
                </label>
                <input wire:model="destinationOnMatch" type="text" id="destinationOnMatch" class="input input-bordered w-full" placeholder="Route when condition matches" />
                <x-form-error name="destinationOnMatch" />
            </div>

            {{-- No Match Destination --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="destinationOnNoMatch">
                    <span class="label-text font-medium">{{ __('admin.time_condition_no_match_destination') }}</span>
                </label>
                <input wire:model="destinationOnNoMatch" type="text" id="destinationOnNoMatch" class="input input-bordered w-full" placeholder="Route when condition does not match" />
                <x-form-error name="destinationOnNoMatch" />
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.time_condition') }}
                </x-form-submit-button>
                <a href="{{ route('panel.time-conditions.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
