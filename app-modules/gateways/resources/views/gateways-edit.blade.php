<div>
    <x-page-header :title="$this->isEdit ? 'Edit Gateway' : 'Create Gateway'" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.tenant') }}</span></label>
                    <select wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.name') }}</span>
                        <x-tooltip :tip="__('admin.gateway_name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="name"
                           class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}" />
                    <x-form-error name="name" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="form-control w-full md:col-span-2">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.gateway_host') }}</span>
                            <x-tooltip :tip="__('admin.gateway_host_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="host"
                               class="input input-bordered w-full {{ $errors->has('host') ? 'input-error' : '' }}"
                               placeholder="sip.provider.com" />
                        <x-form-error name="host" />
                    </div>

                    <div class="form-control w-full md:col-span-1">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.gateway_port') }}</span></label>
                        <input type="number" wire:model="port"
                               class="input input-bordered w-full {{ $errors->has('port') ? 'input-error' : '' }}" />
                        <x-form-error name="port" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.gateway_username') }}</span></label>
                        <input type="text" wire:model="username"
                               class="input input-bordered w-full {{ $errors->has('username') ? 'input-error' : '' }}" />
                        <x-form-error name="username" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.password') }}</span></label>
                        <input type="password" wire:model="password"
                               class="input input-bordered w-full {{ $errors->has('password') ? 'input-error' : '' }}" />
                        <x-form-error name="password" />
                    </div>
                </div>

                <div class="form-control w-full">
                    <label for="profile" class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.gateway_profile') }}</span></label>
                    <input id="profile" name="profile" type="text" wire:model="profile"
                           class="input input-bordered w-full {{ $errors->has('profile') ? 'input-error' : '' }}"
                           placeholder="external" />
                    <span class="label-text-alt text-base-content/50 mt-1">{{ __('admin.gateway_profile_hint') }}</span>
                    <x-form-error name="profile" />
                </div>

                <div class="pt-2 flex flex-col sm:flex-row gap-6">
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="register" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                            <span class="label-text font-medium">{{ __('admin.gateway_register_provider') }}</span>
                            <x-tooltip :tip="__('admin.gateway_register_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
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
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.gateways.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
