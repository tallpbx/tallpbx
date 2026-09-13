<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.call_forward') }}</h1>

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

            {{-- Extension --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extensionUuid">
                    <span class="label-text font-medium">{{ __('admin.extension') }}</span>
                </label>
                <input
                    wire:model="extensionUuid"
                    type="text"
                    id="extensionUuid"
                    class="input input-bordered w-full"
                    placeholder="Extension UUID"
                    @if($this->isEdit) readonly @endif
                />
                @error('extensionUuid')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Forward Type --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="forwardType">
                    <span class="label-text font-medium">{{ __('admin.forward_type') }}</span>
                </label>
                <select wire:model="forwardType" id="forwardType" class="select select-bordered w-full">
                    <option value="unconditional">{{ __('admin.unconditional') }}</option>
                    <option value="busy">{{ __('admin.busy') }}</option>
                    <option value="noanswer">{{ __('admin.no_answer') }}</option>
                    <option value="notfound">{{ __('admin.not_found') }}</option>
                </select>
                @error('forwardType')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Destination --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="destination">
                    <span class="label-text font-medium">{{ __('admin.destination') }}</span>
                </label>
                <input
                    wire:model="destination"
                    type="text"
                    id="destination"
                    class="input input-bordered w-full"
                    placeholder="e.g., 101, +1234567890, vm:101"
                />
                @error('destination')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            {{-- Ring Timeout --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="ringTimeout">
                    <span class="label-text font-medium">{{ __('admin.ring_timeout') }} ({{ __('admin.seconds') }})</span>
                </label>
                <input
                    wire:model="ringTimeout"
                    type="number"
                    id="ringTimeout"
                    class="input input-bordered w-full"
                    min="1"
                    max="300"
                />
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
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.call_forward') }}
                </button>
                <a href="{{ route('panel.call-forwards.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
