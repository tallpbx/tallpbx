<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.recording') }}</h1>

        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId">
                    <span class="label-text font-medium">Tenant</span>
                    <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name">
                    <span class="label-text font-medium">{{ __('admin.recording_name') }}</span>
                </label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Welcome Greeting" />
                @error('name')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="type">
                    <span class="label-text font-medium">{{ __('admin.recording_type') }}</span>
                </label>
                <select wire:model="type" id="type" class="select select-bordered w-full">
                    <option value="moh">{{ __('admin.recording_type_moh') }}</option>
                    <option value="greeting">{{ __('admin.recording_type_greeting') }}</option>
                    <option value="ivr">{{ __('admin.recording_type_ivr') }}</option>
                    <option value="announcement">{{ __('admin.recording_type_announcement') }}</option>
                </select>
                @error('type')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="file">
                    <span class="label-text font-medium">Audio File</span>
                </label>
                <input wire:model="file" type="file" id="file" accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg" class="file-input file-input-bordered w-full" />
                @if ($this->isEdit && $filePath)
                    <span class="label-text-alt mt-1">Leave blank to keep the existing audio file.</span>
                @endif
                @error('file')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.recording') }}
                </button>
                <a href="{{ route('panel.recordings.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
