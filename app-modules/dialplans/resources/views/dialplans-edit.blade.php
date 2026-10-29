<div>
    <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.dialplan')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <select wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.name') }}</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="name"
                           class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}" />
                    <x-form-error name="name" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.dialplan_context') }}</span></label>
                        <input type="text" wire:model="context"
                               class="input input-bordered w-full font-mono {{ $errors->has('context') ? 'input-error' : '' }}"
                               placeholder="default" />
                        <x-form-error name="context" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.dialplan_order') }}</span></label>
                        <input type="number" wire:model="order"
                               class="input input-bordered w-full {{ $errors->has('order') ? 'input-error' : '' }}" />
                        <x-form-error name="order" />
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.description') }}</span></label>
                    <textarea wire:model="description" rows="2"
                              class="textarea textarea-bordered w-full {{ $errors->has('description') ? 'textarea-error' : '' }}"></textarea>
                    <x-form-error name="description" />
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                        <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.dialplans.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
