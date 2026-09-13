<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.bridge') }}</h1>

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full" @if(! $this->isAdminGuard()) hidden @endif>
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('client.select_tenant') }}</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Bridge Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="bridgeName">
                    <span class="label-text font-medium">{{ __('admin.bridge_name') }}</span>
                </label>
                <input
                    wire:model="bridgeName" type="text" id="bridgeName"
                    class="input input-bordered w-full"
                    placeholder="e.g., Conference Room A"
                />
                @error('bridgeName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Destination Number --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="destinationNumber">
                    <span class="label-text font-medium">{{ __('admin.destination_number') }}</span>
                    <x-tooltip :tip="__('admin.bridge_destination_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="destinationNumber" type="text" id="destinationNumber"
                    class="input input-bordered w-full"
                    placeholder="e.g., 8000"
                />
                @error('destinationNumber') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- PIN Number --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="pinNumber">
                    <span class="label-text font-medium">{{ __('admin.pin_number') }} ({{ __('client.optional') }})</span>
                    <x-tooltip :tip="__('admin.bridge_pin_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="pinNumber" type="text" id="pinNumber"
                    class="input input-bordered w-full"
                    placeholder="e.g., 1234"
                />
                @error('pinNumber') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Description --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">{{ __('admin.description') }}</span>
                </label>
                <textarea
                    wire:model="description" id="description"
                    class="textarea textarea-bordered w-full" rows="2"
                    placeholder="{{ __('admin.optional_description') }}"
                ></textarea>
                @error('description') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.bridge') }}
                </button>
                <a href="{{ route('panel.bridges.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
