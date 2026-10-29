<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('admin.edit') : __('admin.create')) . ' ' . __('admin.hot_desk_session')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant (Admin Only) --}}
            @if ($this->isAdminGuard())
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select wire:model.live="tenantId" id="tenantId" class="select select-bordered w-full">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach ($tenants as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>
            @endif

            {{-- Visiting User Extension --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extensionId">
                    <span class="label-text font-medium">{{ __('admin.hot_desking_user_extension') }}</span>
                    <x-tooltip :tip="__('admin.hot_desking_user_extension_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="extensionId" id="extensionId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_extension') }}</option>
                    @foreach ($availableExtensions as $ext)
                        <option value="{{ $ext->id }}">
                            {{ $ext->extension_number }} @if($ext->display_name) - {{ $ext->display_name }} @endif
                        </option>
                    @endforeach
                </select>
                <x-form-error name="extensionId" />
            </div>

            {{-- Desk Phone Extension --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="deviceExtensionId">
                    <span class="label-text font-medium">{{ __('admin.hot_desking_desk_extension') }}</span>
                    <x-tooltip :tip="__('admin.hot_desking_desk_extension_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="deviceExtensionId" id="deviceExtensionId" class="select select-bordered w-full">
                    <option value="">{{ __('admin.select_extension') }}</option>
                    @foreach ($availableExtensions as $ext)
                        <option value="{{ $ext->id }}">
                            {{ $ext->extension_number }} @if($ext->display_name) - {{ $ext->display_name }} @endif
                        </option>
                    @endforeach
                </select>
                <x-form-error name="deviceExtensionId" />
            </div>

            {{-- Description / Notes --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">{{ __('admin.description') }}</span>
                </label>
                <input
                    wire:model="description"
                    type="text"
                    id="description"
                    class="input input-bordered w-full"
                    placeholder="e.g. Desk 12 - Sales Floor"
                />
                <x-form-error name="description" />
            </div>

            {{-- Active Status --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3" for="isActive">
                    <input
                        wire:model="isActive"
                        wire:loading.attr="disabled"
                        type="checkbox"
                        id="isActive"
                        class="toggle toggle-sm toggle-success"
                    />
                    <span class="label-text font-medium">{{ __('admin.active') }}</span>
                </label>
                <x-form-error name="isActive" />
            </div>

            {{-- Form Actions --}}
            <div class="flex justify-end gap-3 pt-4 border-t border-base-300">
                <a href="{{ route('panel.hot-desking.index') }}" wire:navigate class="btn btn-ghost btn-sm">
                    {{ __('admin.cancel') }}
                </a>
                <x-form-submit-button>
                    {{ __('admin.save') }}
                </x-form-submit-button>
            </div>
        </form>
    </div>
</div>
