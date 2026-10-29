<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.conference_center')" />

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
                    <span class="label-text font-medium">{{ __('admin.conference_center_name') }}</span>
                </label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Main Conference Center" />
                <x-form-error name="name" />
            </div>

            {{-- Extension --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="extension">
                    <span class="label-text font-medium">{{ __('admin.conference_center_extension') }}</span>
                </label>
                <input wire:model="extension" type="text" id="extension" class="input input-bordered w-full" placeholder="e.g., 5000" />
                <x-form-error name="extension" />
            </div>

            {{-- PIN --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="pin">
                    <span class="label-text font-medium">{{ __('admin.conference_center_pin') }}</span>
                </label>
                <input wire:model="pin" type="text" id="pin" class="input input-bordered w-full" placeholder="Optional PIN" />
                <x-form-error name="pin" />
            </div>

            {{-- Greeting --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="greetingUpload">
                    <span class="label-text font-medium">{{ __('admin.conference_center_greeting') }}</span>
                </label>
                <input wire:model="greetingUpload" type="file" id="greetingUpload" accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg" class="file-input file-input-bordered w-full" />
                @if ($this->isEdit && $greeting)
                    <span class="label-text-alt mt-1">{{ __('admin.leave_blank_keep_existing_greeting') }}</span>
                @endif
                <x-form-error name="greetingUpload" />
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" wire:loading.attr="disabled" type="checkbox" class="toggle toggle-sm toggle-success" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.conference_center') }}
                </x-form-submit-button>
                <a href="{{ route('panel.conference-centers.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
