<div class="max-w-2xl">
    <div class="flex items-center gap-2 mb-6">
        <h2 class="text-2xl font-semibold">{{ __('admin.backup_configuration') }}</h2>
        <x-tooltip :tip="__('admin.backup_config_tooltip')" position="right">
            <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
        </x-tooltip>
    </div>

    <form wire:submit="save" class="card bg-base-100 border border-base-300 p-6 space-y-5">
        {{-- Name --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="name">
                <span class="label-text font-medium">{{ __('admin.name') }}</span>
            </label>
            <input type="text" id="name" wire:model="name"
                class="input input-bordered w-full"
                placeholder="{{ __('admin.backup_name_placeholder') }}">
            @error('name') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- File Store destination --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="fileStoreId">
                <span class="label-text font-medium">File Store</span>
            </label>
            <select id="fileStoreId" wire:model="fileStoreId" class="select select-bordered w-full">
                <option value="">Select a destination</option>
                @foreach ($fileStores as $id => $fileStoreName)
                    <option value="{{ $id }}" wire:key="file-store-{{ $id }}">{{ $fileStoreName }}</option>
                @endforeach
            </select>
            @error('fileStoreId') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- Scopes (checkboxes) --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1">
                <span class="label-text font-medium">{{ __('admin.backup_scopes') }}</span>
                <x-tooltip :tip="__('admin.backup_scopes_tooltip')" position="right">
                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                </x-tooltip>
            </label>
            <div class="grid grid-cols-2 gap-2 mt-1">
                @foreach ($this->availableScopes() as $key => $label)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="scope" value="{{ $key }}"
                            class="checkbox checkbox-sm">
                        <span class="text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('scope') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- Retention count --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="retentionCount">
                <span class="label-text font-medium">{{ __('admin.backup_retention') }}</span>
                <x-tooltip :tip="__('admin.backup_retention_tooltip')" position="right">
                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                </x-tooltip>
            </label>
            <input type="number" id="retentionCount" wire:model="retentionCount"
                class="input input-bordered w-full" min="1" max="365">
            @error('retentionCount') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- Compression toggle --}}
        <div class="form-control w-full">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" wire:model="compression" class="toggle toggle-primary">
                <span class="text-sm font-medium">{{ __('admin.backup_compression') }}</span>
                <x-tooltip :tip="__('admin.backup_compression_tooltip')" position="right">
                    <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                </x-tooltip>
            </label>
        </div>

        {{-- Cron schedule --}}
        <div class="form-control w-full">
            <label class="label justify-start gap-2 pb-1" for="scheduleCron">
                <span class="label-text font-medium">{{ __('admin.backup_schedule') }}</span>
            </label>
            <input type="text" id="scheduleCron" wire:model="scheduleCron"
                class="input input-bordered w-full font-mono text-sm"
                placeholder="0 2 * * * (daily at 2 AM)">
            <label class="label">
                <span class="label-text-alt text-base-content/60">{{ __('admin.backup_schedule_hint') }}</span>
            </label>
        </div>

        {{-- Submit --}}
        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn btn-primary">
                {{ $backupId ? __('client.save') : __('admin.backup_create') }}
            </button>
            <a href="{{ route('panel.backups.index') }}" class="btn btn-ghost" wire:navigate>
                {{ __('client.cancel') }}
            </a>
        </div>
    </form>
</div>
