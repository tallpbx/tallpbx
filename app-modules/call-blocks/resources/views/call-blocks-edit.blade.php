<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.call_block') }}</h1>

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('client.select_tenant') }}</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                </label>
                <input
                    wire:model="name" type="text" id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Block Telemarketers"
                />
                @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Caller ID Number --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="callerIdNumber">
                    <span class="label-text font-medium">{{ __('admin.caller_id_pattern') }}</span>
                </label>
                <input
                    wire:model="callerIdNumber" type="text" id="callerIdNumber"
                    class="input input-bordered w-full"
                    placeholder="e.g., 1234567890, *SPAM*, +1*"
                />
                @error('callerIdNumber') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.call_block') }}
                </button>
                <a href="{{ route('panel.call-blocks.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
