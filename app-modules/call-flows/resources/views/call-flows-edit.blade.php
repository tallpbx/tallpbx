<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.call_flow') }}</h1>

        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.call_flow_name') }}</span>
                </label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Sales DID" />
                @error('name')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extension">
                    <span class="label-text font-medium">{{ __('admin.call_flow_extension') }}</span>
                </label>
                <input wire:model="extension" type="text" id="extension" class="input input-bordered w-full" placeholder="e.g., 15551234567" />
                @error('extension')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="destinationType">
                        <span class="label-text font-medium">{{ __('admin.call_flow_destination_type') }}</span>
                    </label>
                    <input wire:model="destinationType" type="text" id="destinationType" class="input input-bordered w-full" placeholder="e.g., ring_group" />
                    @error('destinationType')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="destinationId">
                        <span class="label-text font-medium">{{ __('admin.call_flow_destination_id') }}</span>
                    </label>
                    <input wire:model="destinationId" type="text" id="destinationId" class="input input-bordered w-full" placeholder="Destination UUID" />
                    @error('destinationId')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.call_flow') }}
                </button>
                <a href="{{ route('panel.call-flows.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
