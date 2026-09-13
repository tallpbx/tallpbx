<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.speech_config') }}</h1>
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
                    @foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach
                </select>
                @error('tenantId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="engine"><span class="label-text font-medium">{{ __('admin.speech_engine') }}</span></label>
                <input wire:model="engine" id="engine" type="text" class="input input-bordered w-full" />
                @error('engine')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="voice"><span class="label-text font-medium">{{ __('admin.speech_voice') }}</span></label>
                    <input wire:model="voice" id="voice" type="text" class="input input-bordered w-full font-mono" />
                    @error('voice')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="language"><span class="label-text font-medium">{{ __('admin.speech_language') }}</span></label>
                    <input wire:model="language" id="language" type="text" class="input input-bordered w-full" placeholder="en-US" />
                    @error('language')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="rate"><span class="label-text font-medium">{{ __('admin.speech_rate') }}</span></label>
                <input wire:model="rate" id="rate" type="text" class="input input-bordered w-full" placeholder="1.00" />
                @error('rate')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.speech_enabled') }}</span>
                </label>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
                <a href="{{ route('panel.speech.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
