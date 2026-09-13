<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.template') }}</h1>
        <form wire:submit="save" class="space-y-6">
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="tenantId"><span class="label-text font-medium">Tenant</span></label>
                <select wire:model="tenantId" id="tenantId" class="select select-bordered w-full">
                    <option value="">Select a tenant...</option>
                    @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                </select>
                @error('tenantId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.template_name') }}</span></label>
                <input wire:model="name" type="text" id="name" class="input input-bordered w-full" placeholder="e.g., Grandstream Default" />
                @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="vendor"><span class="label-text font-medium">{{ __('admin.template_vendor') }}</span></label>
                    <input wire:model="vendor" type="text" id="vendor" class="input input-bordered w-full" placeholder="e.g., grandstream" />
                    @error('vendor')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="model"><span class="label-text font-medium">{{ __('admin.template_model') }}</span></label>
                    <input wire:model="model" type="text" id="model" class="input input-bordered w-full" placeholder="e.g., gxp2170 (optional)" />
                    @error('model')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="filePath"><span class="label-text font-medium">{{ __('admin.template_file_path') }}</span></label>
                <input wire:model="filePath" type="text" id="filePath" class="input input-bordered w-full" placeholder="e.g., grandstream/gxp2170/default.blade.php" />
                @error('filePath')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                    <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                        <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                    </x-tooltip>
                </label>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">{{ $this->isEdit ? __('client.update') : __('client.create') }} {{ __('admin.template') }}</button>
                <a href="{{ route('panel.provision.templates.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
