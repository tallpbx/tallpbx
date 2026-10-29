<div>
    <x-page-header :title="$this->isEdit ? __('admin.edit_acl') : __('admin.create_acl')" :tooltip="__('admin.acl_tooltip')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
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

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">{{ __('admin.name') }}</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}" />
                    <x-form-error name="name" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="description">
                        <span class="label-text font-medium">{{ __('admin.description') }}</span>
                    </label>
                    <textarea id="description" wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full {{ $errors->has('description') ? 'textarea-error' : '' }}"></textarea>
                    <x-form-error name="description" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="action">
                        <span class="label-text font-medium">{{ __('admin.acl_action') }}</span>
                        <x-tooltip :tip="__('admin.route_destination_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="action" wire:model="action" class="select select-bordered w-full {{ $errors->has('action') ? 'select-error' : '' }}">
                        <option value="allow">{{ __('admin.acl_action_allow') }}</option>
                        <option value="deny">{{ __('admin.acl_action_deny') }}</option>
                    </select>
                    <x-form-error name="action" />
                </div>

                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.acl.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
