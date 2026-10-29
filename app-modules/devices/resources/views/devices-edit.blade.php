<div>
    <x-page-header :title="$this->isEdit ? 'Edit Device' : 'Create Device'" :tooltip="__('admin.device_header_tooltip')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <select wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_vendor') }}</span></label>
                        <input type="text" wire:model="vendor"
                               class="input input-bordered w-full {{ $errors->has('vendor') ? 'input-error' : '' }}"
                               placeholder="Polycom, Yealink, Cisco..." />
                        <x-form-error name="vendor" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_model') }}</span></label>
                        <input type="text" wire:model="model"
                               class="input input-bordered w-full {{ $errors->has('model') ? 'input-error' : '' }}"
                               placeholder="VVX 450, T46S..." />
                        <x-form-error name="model" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_mac') }}</span></label>
                        <input type="text" wire:model="macAddress"
                               class="input input-bordered w-full font-mono {{ $errors->has('macAddress') ? 'input-error' : '' }}"
                               placeholder="00:11:22:33:44:55" />
                        <x-form-error name="macAddress" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_template') }}</span></label>
                        <input type="text" wire:model="template"
                               class="input input-bordered w-full {{ $errors->has('template') ? 'input-error' : '' }}"
                               placeholder="polycom_vvx" />
                        <x-form-error name="template" />
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_sip_account') }}</span></label>
                    <select wire:model="sipAccountId" class="select select-bordered w-full">
                        <option value="">{{ __('admin.none') }}</option>
                        @foreach ($sipAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->auth_username }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.devices.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
