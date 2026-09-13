<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.inbound_routes_title') }}</h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                {{-- Tenant --}}
                <div class="form-control w-full">
                    <label for="tenantId" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full">
                        <option value="">Select a tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Name --}}
                <div class="form-control w-full">
                    <label for="name" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Route Name</span>
                    </label>
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full" placeholder="Main Inbound" />
                    @error('name') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Destination Number --}}
                <div class="form-control w-full">
                    <label for="destinationNumber" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Destination Number (DID)</span>
                        <x-tooltip :tip="__('admin.route_condition_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="destinationNumber" wire:model="destinationNumber" class="input input-bordered w-full" placeholder="+15551234567" />
                    @error('destinationNumber') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Action --}}
                <div class="form-control w-full">
                    <label for="action" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Action</span>
                        <x-tooltip :tip="__('admin.route_destination_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="action" wire:model="action" class="select select-bordered w-full">
                        <option value="">Select action</option>
                        <option value="transfer">Transfer</option>
                        <option value="ivr">IVR Menu</option>
                        <option value="voicemail">Voicemail</option>
                    </select>
                    @error('action') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Action Data --}}
                <div class="form-control w-full">
                    <label for="actionData" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Action Data</span>
                    </label>
                    <input type="text" id="actionData" wire:model="actionData" class="input input-bordered w-full" placeholder="Extension or menu ID" />
                </div>

                {{-- Priority --}}
                <div class="form-control w-full">
                    <label for="priority" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Priority</span>
                    </label>
                    <input type="number" id="priority" wire:model="priority" class="input input-bordered w-full" />
                    @error('priority') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                        <span class="label-text font-medium">Enabled</span>
                    </label>
                </div>

                <div class="flex gap-2 pt-4">
                    <button type="submit" class="btn btn-primary">{{ $this->isEdit ? __('client.update') : __('client.create') }}</button>
                    <a href="{{ route('panel.inbound-routes.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
