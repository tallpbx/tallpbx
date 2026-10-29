<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.tenant_limit')" />
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_tenant') }}</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="resource"><span class="label-text font-medium">{{ __('admin.tenant_limit_resource') }}</span></label>
                <input wire:model="resource" id="resource" type="text" class="input input-bordered w-full" />
                <x-form-error name="resource" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="softLimit"><span class="label-text font-medium">{{ __('admin.tenant_limit_soft') }}</span></label>
                    <input wire:model="softLimit" id="softLimit" type="number" min="0" class="input input-bordered w-full" />
                    <x-form-error name="softLimit" />
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="hardLimit"><span class="label-text font-medium">{{ __('admin.tenant_limit_hard') }}</span></label>
                    <input wire:model="hardLimit" id="hardLimit" type="number" min="0" class="input input-bordered w-full" />
                    <x-form-error name="hardLimit" />
                </div>
            </div>
            <div class="flex gap-3">
                <x-form-submit-button>{{ __('client.save') }}</x-form-submit-button>
                <a href="{{ route('panel.tenant-limits.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
