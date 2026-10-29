<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="($this->isEdit ? __('client.edit') : __('client.create')) . ' ' . __('admin.music_on_hold')" />

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
                    <option value="">{{ __('client.select_tenant') }}</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <x-form-error name="tenantId" />
            </div>

            {{-- Name --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                </label>
                <input
                    wire:model="name" type="text" id="name"
                    class="input input-bordered w-full"
                    placeholder="e.g., Soft Jazz"
                />
                <x-form-error name="name" />
            </div>

            {{-- Audio File --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="audioUpload">
                    <span class="label-text font-medium">{{ __('admin.audio_file') }}</span>
                </label>
                <input
                    wire:model="audioUpload" type="file" id="audioUpload"
                    accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg"
                    class="file-input file-input-bordered w-full"
                />
                @if ($this->isEdit && $audioFile)
                    <span class="label-text-alt mt-1">{{ __('admin.leave_blank_keep_existing_audio') }}</span>
                @endif
                <x-form-error name="audioUpload" />
            </div>

            {{-- Description --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="description">
                    <span class="label-text font-medium">{{ __('admin.description') }}</span>
                </label>
                <textarea
                    wire:model="description" id="description"
                    class="textarea textarea-bordered w-full" rows="2"
                    placeholder="{{ __('admin.optional_description') }}"
                ></textarea>
                <x-form-error name="description" />
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
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.music_on_hold') }}
                </x-form-submit-button>
                <a href="{{ route('panel.music-on-hold.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
