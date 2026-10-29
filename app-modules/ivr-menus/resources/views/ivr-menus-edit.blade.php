<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.ivr_menu')" />

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
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

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                    <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="name"
                    type="text"
                    id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Main IVR, Support Menu"
                />
                <x-form-error name="name" />
            </div>

            {{-- Greeting --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="greetingUpload">
                    <span class="label-text font-medium">{{ __('admin.ivr_menu_greeting') }}</span>
                    <x-tooltip :tip="__('admin.ivr_menu_greeting_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <input
                    wire:model="greetingUpload"
                    type="file"
                    id="greetingUpload"
                    accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg"
                    class="file-input file-input-bordered w-full"
                />
                @if ($this->isEdit && $greeting)
                    <span class="label-text-alt mt-1">{{ __('admin.leave_blank_keep_existing_greeting') }}</span>
                @endif
                <x-form-error name="greetingUpload" />
            </div>

            {{-- Description --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">{{ __('admin.description') }}</span>
                </label>
                <textarea
                    wire:model="description"
                    id="description"
                    class="textarea textarea-bordered w-full"
                    rows="2"
                    placeholder="Optional description of this IVR menu"
                ></textarea>
                <x-form-error name="description" />
            </div>

            {{-- Settings Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- Timeout --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="timeout">
                        <span class="label-text font-medium">{{ __('admin.ivr_menu_timeout') }}</span>
                    </label>
                    <input
                        wire:model="timeout"
                        type="number"
                        id="timeout"
                        class="input input-bordered w-full"
                        min="1"
                        max="300"
                    />
                    <x-form-error name="timeout" />
                </div>

                {{-- Max Failures --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="maxFailures">
                        <span class="label-text font-medium">{{ __('admin.ivr_menu_max_failures') }}</span>
                    </label>
                    <input
                        wire:model="maxFailures"
                        type="number"
                        id="maxFailures"
                        class="input input-bordered w-full"
                        min="1"
                        max="100"
                    />
                    <x-form-error name="maxFailures" />
                </div>

                {{-- Digit Length --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="digitLength">
                        <span class="label-text font-medium">{{ __('admin.ivr_menu_digit_length_hint') }}</span>
                    </label>
                    <input
                        wire:model="digitLength"
                        type="number"
                        id="digitLength"
                        class="input input-bordered w-full"
                        min="0"
                        max="20"
                    />
                    <x-form-error name="digitLength" />
                </div>
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                    <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }} IVR Menu
                </x-form-submit-button>
                <a href="{{ route('panel.ivr-menus.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
