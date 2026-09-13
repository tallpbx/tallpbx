<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">
            {{ $this->isEdit ? __('admin.edit') : __('admin.create') }} {{ __('admin.hot_desk_session') }}
        </h1>

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
                    @error('tenantId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                @error('extensionId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                @error('deviceExtensionId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                @error('description') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Active Status --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3" for="isActive">
                    <input
                        wire:model="isActive"
                        type="checkbox"
                        id="isActive"
                        class="checkbox checkbox-primary"
                    />
                    <span class="label-text font-medium">{{ __('admin.active') }}</span>
                </label>
                @error('isActive') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Form Actions --}}
            <div class="flex justify-end gap-3 pt-4 border-t border-base-300">
                <a href="{{ route('panel.hot-desking.index') }}" class="btn btn-ghost">
                    {{ __('admin.cancel') }}
                </a>
                <button type="submit" class="btn btn-primary">
                    {{ __('admin.save') }}
                </button>
            </div>
        </form>
    </div>
</div>
