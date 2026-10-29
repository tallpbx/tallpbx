<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.extension_setting')" />
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full" wire:change="updatedTenantId">
                    <option value="">{{ __('admin.select_tenant') }}</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extensionId"><span class="label-text font-medium">{{ __('admin.extension_setting_extension') }}</span></label>
                <select wire:model="extensionId" id="extensionId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.extension_setting_select_extension') }}</option>
                    @foreach ($extensions as $ext)
                        <option value="{{ $ext->id }}">{{ $ext->extension_number }}</option>
                    @endforeach
                </select>
                <x-form-error name="extensionId" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="key"><span class="label-text font-medium">{{ __('admin.extension_setting_key') }}</span></label>
                <input wire:model="key" id="key" type="text" class="input input-bordered w-full font-mono" />
                <x-form-error name="key" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="value"><span class="label-text font-medium">{{ __('admin.extension_setting_value') }}</span></label>
                <input wire:model="value" id="value" type="text" class="input input-bordered w-full" />
                <x-form-error name="value" />
            </div>
            <div class="flex gap-3">
                <x-form-submit-button>{{ __('client.save') }}</x-form-submit-button>
                <a href="{{ route('panel.extension-settings.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
