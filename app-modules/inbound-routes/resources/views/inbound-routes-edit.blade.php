<div>
    <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.inbound_routes_title')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                {{-- Tenant --}}
                <div class="form-control w-full">
                    <label for="tenantId" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                {{-- Name --}}
                <div class="form-control w-full">
                    <label for="name" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.inbound_route_name') }}</span>
                    </label>
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full" placeholder="Main Inbound" />
                    <x-form-error name="name" />
                </div>

                {{-- Destination Number --}}
                <div class="form-control w-full">
                    <label for="destinationNumber" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.inbound_route_destination_number') }}</span>
                        <x-tooltip :tip="__('admin.route_condition_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="destinationNumber" wire:model="destinationNumber" class="input input-bordered w-full" placeholder="+15551234567" />
                    <x-form-error name="destinationNumber" />
                </div>

                {{-- Action --}}
                <div class="form-control w-full">
                    <label for="action" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.inbound_route_action') }}</span>
                        <x-tooltip :tip="__('admin.route_destination_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="action" wire:model="action" class="select select-bordered w-full">
                        <option value="">{{ __('admin.inbound_route_select_action') }}</option>
                        <option value="transfer">Transfer</option>
                        <option value="ivr">IVR Menu</option>
                        <option value="voicemail">{{ __('admin.voicemail') }}</option>
                    </select>
                    <x-form-error name="action" />
                </div>

                {{-- Action Data --}}
                <div class="form-control w-full">
                    <label for="actionData" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.inbound_route_action_data') }}</span>
                    </label>
                    <input type="text" id="actionData" wire:model="actionData" class="input input-bordered w-full" placeholder="Extension or menu ID" />
                </div>

                {{-- Priority --}}
                <div class="form-control w-full">
                    <label for="priority" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.inbound_route_priority') }}</span>
                    </label>
                    <input type="number" id="priority" wire:model="priority" class="input input-bordered w-full" />
                    <x-form-error name="priority" />
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                    </label>
                </div>

                <div class="flex gap-2 pt-4">
                    <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }}
                </x-form-submit-button>
                    <a href="{{ route('panel.inbound-routes.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
