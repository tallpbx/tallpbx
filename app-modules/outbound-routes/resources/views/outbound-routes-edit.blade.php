<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.outbound_routes_title') }}</h2>
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
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full" placeholder="Default Outbound" />
                    @error('name') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Dial Pattern --}}
                <div class="form-control w-full">
                    <label for="dialPattern" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Dial Pattern (regex)</span>
                    </label>
                    <input type="text" id="dialPattern" wire:model="dialPattern" class="input input-bordered w-full font-mono" placeholder="^(\d{10})$" />
                    @error('dialPattern') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Gateway --}}
                <div class="form-control w-full">
                    <label for="gatewayId" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Gateway</span>
                    </label>
                    <select id="gatewayId" wire:model="gatewayId" class="select select-bordered w-full">
                        <option value="">Use external profile fallback</option>
                        @foreach($availableGateways as $gatewayOption)
                            <option value="{{ $gatewayOption['id'] }}">
                                {{ $gatewayOption['name'] }} ({{ $gatewayOption['profile'] ?? 'external' }})
                            </option>
                        @endforeach
                    </select>
                    @error('gatewayId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Legacy Gateway --}}
                <div class="form-control w-full">
                    <label for="gateway" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Legacy Gateway Name</span>
                    </label>
                    <input type="text" id="gateway" wire:model="gateway" class="input input-bordered w-full" placeholder="optional legacy gateway name" />
                </div>

                {{-- Caller ID Grid --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label for="callerIdName" class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Caller ID Name</span>
                        </label>
                        <input type="text" id="callerIdName" wire:model="callerIdName" class="input input-bordered w-full" />
                    </div>

                    <div class="form-control w-full">
                        <label for="callerIdNumber" class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Caller ID Number</span>
                        </label>
                        <input type="text" id="callerIdNumber" wire:model="callerIdNumber" class="input input-bordered w-full" />
                    </div>
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
                    <a href="{{ route('panel.outbound-routes.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
