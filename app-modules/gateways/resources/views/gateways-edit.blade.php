<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Gateway' : 'Create Gateway' }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Tenant</span></label>
                    <select wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Name</span>
                        <x-tooltip :tip="__('admin.gateway_name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="name"
                           class="input input-bordered w-full @error('name') input-error @enderror" />
                    @error('name') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="form-control w-full md:col-span-2">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Host</span>
                            <x-tooltip :tip="__('admin.gateway_host_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="host"
                               class="input input-bordered w-full @error('host') input-error @enderror"
                               placeholder="sip.provider.com" />
                        @error('host') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full md:col-span-1">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Port</span></label>
                        <input type="number" wire:model="port"
                               class="input input-bordered w-full @error('port') input-error @enderror" />
                        @error('port') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Username</span></label>
                        <input type="text" wire:model="username"
                               class="input input-bordered w-full @error('username') input-error @enderror" />
                        @error('username') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Password</span></label>
                        <input type="password" wire:model="password"
                               class="input input-bordered w-full @error('password') input-error @enderror" />
                        @error('password') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label for="profile" class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Profile</span></label>
                    <input id="profile" name="profile" type="text" wire:model="profile"
                           class="input input-bordered w-full @error('profile') input-error @enderror"
                           placeholder="external" />
                    <span class="label-text-alt text-base-content/50 mt-1">Sofia profile name (default: external)</span>
                    @error('profile') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="pt-2 flex flex-col sm:flex-row gap-6">
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="register" class="checkbox checkbox-info" />
                            <span class="label-text font-medium">Register with provider</span>
                            <x-tooltip :tip="__('admin.gateway_register_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
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
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.gateways.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
