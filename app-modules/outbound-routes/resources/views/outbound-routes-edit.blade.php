<div>
    <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.outbound_routes_title')" />

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
                        <span class="label-text font-medium">{{ __('admin.outbound_route_name') }}</span>
                    </label>
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full" placeholder="Default Outbound" />
                    <x-form-error name="name" />
                </div>

                {{-- Dial Pattern --}}
                <div class="form-control w-full">
                    <label for="dialPattern" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.outbound_route_dial_pattern') }}</span>
                    </label>
                    <input type="text" id="dialPattern" wire:model="dialPattern" class="input input-bordered w-full font-mono" placeholder="^(\d{10})$" />
                    <x-form-error name="dialPattern" />
                </div>

                {{-- Gateway --}}
                <div class="form-control w-full">
                    <label for="gatewayId" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.outbound_route_gateway') }}</span>
                    </label>
                    <select id="gatewayId" wire:model="gatewayId" class="select select-bordered w-full">
                        <option value="">{{ __('admin.outbound_route_external_fallback') }}</option>
                        @foreach($availableGateways as $gatewayOption)
                            <option value="{{ $gatewayOption['id'] }}">
                                {{ $gatewayOption['name'] }} ({{ $gatewayOption['profile'] ?? 'external' }})
                            </option>
                        @endforeach
                    </select>
                    <x-form-error name="gatewayId" />
                </div>

                {{-- Legacy Gateway --}}
                <div class="form-control w-full">
                    <label for="gateway" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.outbound_route_legacy_gateway') }}</span>
                    </label>
                    <input type="text" id="gateway" wire:model="gateway" class="input input-bordered w-full" placeholder="optional legacy gateway name" />
                </div>

                {{-- Caller ID Grid --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label for="callerIdName" class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.outbound_route_caller_id_name') }}</span>
                        </label>
                        <input type="text" id="callerIdName" wire:model="callerIdName" class="input input-bordered w-full" />
                    </div>

                    <div class="form-control w-full">
                        <label for="callerIdNumber" class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.outbound_route_caller_id_number') }}</span>
                        </label>
                        <input type="text" id="callerIdNumber" wire:model="callerIdNumber" class="input input-bordered w-full" />
                    </div>
                </div>

                {{-- Priority --}}
                <div class="form-control w-full">
                    <label for="priority" class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.outbound_route_priority') }}</span>
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
                    <a href="{{ route('panel.outbound-routes.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
