<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.number_translation')" />
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
                <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.number_translation_name') }}</span></label>
                <input wire:model="name" id="name" type="text" class="input input-bordered w-full" />
                <x-form-error name="name" />
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="order"><span class="label-text font-medium">{{ __('admin.routing_translation_order') }}</span></label>
                <input id="order" wire:model="order" type="number" min="0" class="input input-bordered w-full" />
                <x-form-error name="order" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="matchPattern"><span class="label-text font-medium">{{ __('admin.number_translation_match') }}</span></label>
                    <input wire:model="matchPattern" id="matchPattern" type="text" class="input input-bordered w-full font-mono" placeholder="^011(\d+)$" />
                    <x-form-error name="matchPattern" />
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="replacePattern"><span class="label-text font-medium">{{ __('admin.number_translation_replace') }}</span></label>
                    <input wire:model="replacePattern" id="replacePattern" type="text" class="input input-bordered w-full font-mono" placeholder="$1" />
                    <x-form-error name="replacePattern" />
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="direction"><span class="label-text font-medium">{{ __('admin.number_translation_direction') }}</span></label>
                <select wire:model="direction" id="direction" class="select select-bordered w-full">
                    <option value="inbound">{{ __('admin.direction_inbound') }}</option>
                    <option value="outbound">{{ __('admin.direction_outbound') }}</option>
                    <option value="both">{{ __('admin.direction_both') }}</option>
                </select>
                <x-form-error name="direction" />
            </div>
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.number_translation_enabled') }}</span>
                </label>
            </div>
            <div class="flex gap-3">
                <x-form-submit-button>{{ __('client.save') }}</x-form-submit-button>
                <a href="{{ route('panel.number-translations.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
