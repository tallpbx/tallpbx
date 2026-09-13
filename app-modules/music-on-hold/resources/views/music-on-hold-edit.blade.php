<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.music_on_hold') }}</h1>

        <form wire:submit="save" class="space-y-6">
            {{-- Tenant --}}
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
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
                @error('tenantId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                    <span class="label-text-alt mt-1">Leave blank to keep the existing audio file.</span>
                @endif
                @error('audioUpload') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
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
                @error('description') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.music_on_hold') }}
                </button>
                <a href="{{ route('panel.music-on-hold.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
