<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.follow_me') }}</h1>

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
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.follow_me_name') }}</span>
                </label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Work Forwarding" />
                @error('name')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Extension --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extension">
                    <span class="label-text font-medium">{{ __('admin.follow_me_extension') }}</span>
                </label>
                <input wire:model="extension" type="text" id="extension" class="input input-bordered w-full" placeholder="e.g., 1000" />
                @error('extension')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Destination --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="destination">
                    <span class="label-text font-medium">{{ __('admin.follow_me_destination') }}</span>
                </label>
                <input wire:model="destination" type="text" id="destination" class="input input-bordered w-full" placeholder="e.g., +15551234567" />
                @error('destination')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Ring Timeout --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="ringTimeout">
                    <span class="label-text font-medium">{{ __('admin.follow_me_ring_timeout') }}</span>
                </label>
                <input wire:model="ringTimeout" type="number" id="ringTimeout" class="input input-bordered w-full" min="1" max="300" />
                @error('ringTimeout')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
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
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.follow_me') }}
                </button>
                <a href="{{ route('panel.follow-me.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
