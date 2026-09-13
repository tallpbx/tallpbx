<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.sip_trunk') }}</h1>
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.sip_trunk_name') }}</span></label>
                    <input wire:model="name" id="name" type="text" class="input input-bordered w-full" />
                    @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="host"><span class="label-text font-medium">{{ __('admin.sip_trunk_host') }}</span></label>
                    <input wire:model="host" id="host" type="text" class="input input-bordered w-full font-mono" />
                    @error('host')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="port"><span class="label-text font-medium">{{ __('admin.sip_trunk_port') }}</span></label>
                    <input wire:model="port" id="port" type="number" min="1" max="65535" class="input input-bordered w-full" />
                    @error('port')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="codecs"><span class="label-text font-medium">{{ __('admin.sip_trunk_codecs') }}</span></label>
                    <input wire:model="codecs" id="codecs" type="text" class="input input-bordered w-full font-mono" placeholder="PCMU,PCMA" />
                    @error('codecs')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="username"><span class="label-text font-medium">{{ __('admin.sip_trunk_username') }}</span></label>
                    <input wire:model="username" id="username" type="text" class="input input-bordered w-full" />
                    @error('username')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
                <div class="form-control w-full" x-data="{ show: false }">
                    <label class="label justify-start gap-2 pb-1" for="password"><span class="label-text font-medium">{{ __('admin.sip_trunk_password') }}</span></label>
                    <div class="relative w-full">
                        <input :type="show ? 'text' : 'password'" wire:model="password" id="password"
                               autocomplete="new-password"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               class="input input-bordered w-full pr-10" />
                        <button type="button" @click="show = !show" class="btn btn-ghost btn-xs btn-circle absolute right-2 top-1/2 -translate-y-1/2 text-base-content/60 hover:text-base-content" tabindex="-1">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" class="w-4 h-4" x-cloak />
                        </button>
                    </div>
                    @error('password')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="form-control w-full">
                <label class="label cursor-pointer justify-start gap-3">
                    <input wire:model="enabled" type="checkbox" class="checkbox checkbox-primary" />
                    <span class="label-text font-medium">{{ __('admin.sip_trunk_enabled') }}</span>
                </label>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
                <a href="{{ route('panel.sip-trunks.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
