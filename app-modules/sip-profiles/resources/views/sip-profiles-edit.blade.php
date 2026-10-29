<div>
    <x-page-header :title="$this->isEdit ? 'Edit SIP Profile' : 'Create SIP Profile'" :tooltip="__('admin.sip_profile_header_tooltip')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                {{-- Tenant --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                {{-- Name --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">{{ __('admin.profile_name') }}</span>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}"
                           placeholder="internal" />
                    <x-form-error name="name" />
                </div>

                {{-- Description --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="description">
                        <span class="label-text font-medium">{{ __('admin.description') }}</span>
                    </label>
                    <textarea id="description" wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full {{ $errors->has('description') ? 'textarea-error' : '' }}"
                              placeholder="Optional description"></textarea>
                    <x-form-error name="description" />
                </div>

                {{-- SIP IP & Port Grid --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="sipIp">
                            <span class="label-text font-medium">{{ __('admin.profile_ip') }}</span>
                        </label>
                        <input type="text" id="sipIp" wire:model="sipIp"
                               class="input input-bordered w-full {{ $errors->has('sipIp') ? 'input-error' : '' }}"
                               placeholder="$${local_ip_v4}" />
                        <x-form-error name="sipIp" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="sipPort">
                            <span class="label-text font-medium">{{ __('admin.profile_port') }}</span>
                        </label>
                        <input type="number" id="sipPort" wire:model="sipPort"
                               class="input input-bordered w-full {{ $errors->has('sipPort') ? 'input-error' : '' }}" />
                        <x-form-error name="sipPort" />
                    </div>
                </div>

                {{-- Enabled --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled"
                               wire:loading.attr="disabled"
                               class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                {{-- Actions --}}
                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.sip-profiles.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
