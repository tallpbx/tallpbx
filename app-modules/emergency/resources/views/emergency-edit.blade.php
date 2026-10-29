<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.emergency')" />
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_tenant') }}</option>
                    @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="callerId"><span class="label-text font-medium">{{ __('admin.emergency_caller') }}</span></label>
                <input wire:model="callerId" id="callerId" type="text" class="input input-bordered w-full font-mono" />
                <x-form-error name="callerId" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="address"><span class="label-text font-medium">{{ __('admin.emergency_address') }}</span></label>
                <textarea wire:model="address" id="address" rows="3" class="textarea textarea-bordered w-full"></textarea>
                <x-form-error name="address" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="latitude"><span class="label-text font-medium">{{ __('admin.emergency_latitude') }}</span></label>
                    <input wire:model="latitude" id="latitude" type="text" class="input input-bordered w-full font-mono" placeholder="39.7817" />
                    <x-form-error name="latitude" />
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="longitude"><span class="label-text font-medium">{{ __('admin.emergency_longitude') }}</span></label>
                    <input wire:model="longitude" id="longitude" type="text" class="input input-bordered w-full font-mono" placeholder="-89.6501" />
                    <x-form-error name="longitude" />
                </div>
            </div>
            <div class="flex gap-3">
                <x-form-submit-button>{{ __('client.save') }}</x-form-submit-button>
                <a href="{{ route('panel.emergency.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
