<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Destination' : 'Create Destination' }}
        </h2>
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

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Name</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="name"
                           class="input input-bordered w-full @error('name') input-error @enderror" />
                    @error('name') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Type</span></label>
                        <select wire:model="type" class="select select-bordered w-full @error('type') select-error @enderror">
                            <option value="">Select type</option>
                            <option value="conference">Conference</option>
                            <option value="ivr">IVR</option>
                            <option value="voicemail">Voicemail</option>
                            <option value="ring_group">Ring Group</option>
                            <option value="call_flow">Call Flow</option>
                        </select>
                        @error('type') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Dial String</span></label>
                        <input type="text" wire:model="dialString"
                               class="input input-bordered w-full font-mono @error('dialString') input-error @enderror"
                               placeholder="3000" />
                        @error('dialString') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Description</span></label>
                    <textarea wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"></textarea>
                    @error('description') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
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
                    <a href="{{ route('panel.destinations.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
