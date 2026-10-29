<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.bridge')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full" @if(! $this->isAdminGuard()) hidden @endif>
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('client.select_tenant') }}</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
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
                <x-form-error name="bridgeName" />
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
                <x-form-error name="destinationNumber" />
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
                <x-form-error name="pinNumber" />
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
                <x-form-error name="description" />
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.bridge') }}
                </x-form-submit-button>
                <a href="{{ route('panel.bridges.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
