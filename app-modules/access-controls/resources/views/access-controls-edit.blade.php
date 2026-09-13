<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Access Control' : 'Create Access Control' }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">Name</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full @error('name') input-error @enderror" />
                    @error('name') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="description">
                        <span class="label-text font-medium">Description</span>
                    </label>
                    <textarea id="description" wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"></textarea>
                    @error('description') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="action">
                        <span class="label-text font-medium">Action</span>
                        <x-tooltip :tip="__('admin.route_destination_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="action" wire:model="action" class="select select-bordered w-full @error('action') select-error @enderror">
                        <option value="allow">Allow</option>
                        <option value="deny">Deny</option>
                    </select>
                    @error('action') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                        <span class="label-text font-medium">Enabled</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.access-controls.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
