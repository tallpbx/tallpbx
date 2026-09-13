<div>
    <div class="flex items-center gap-2 mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Device' : 'Create Device' }}
        </h2>
        <x-tooltip :tip="__('admin.device_header_tooltip')" align="start" position="right">
            <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
        </x-tooltip>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <select wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Vendor</span></label>
                        <input type="text" wire:model="vendor"
                               class="input input-bordered w-full @error('vendor') input-error @enderror"
                               placeholder="Polycom, Yealink, Cisco..." />
                        @error('vendor') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Model</span></label>
                        <input type="text" wire:model="model"
                               class="input input-bordered w-full @error('model') input-error @enderror"
                               placeholder="VVX 450, T46S..." />
                        @error('model') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">MAC Address</span></label>
                        <input type="text" wire:model="macAddress"
                               class="input input-bordered w-full font-mono @error('macAddress') input-error @enderror"
                               placeholder="00:11:22:33:44:55" />
                        @error('macAddress') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Template</span></label>
                        <input type="text" wire:model="template"
                               class="input input-bordered w-full @error('template') input-error @enderror"
                               placeholder="polycom_vvx" />
                        @error('template') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.device_sip_account') }}</span></label>
                    <select wire:model="sipAccountId" class="select select-bordered w-full">
                        <option value="">—</option>
                        @foreach ($sipAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->auth_username }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                        <span class="label-text font-medium">Enabled</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.devices.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
