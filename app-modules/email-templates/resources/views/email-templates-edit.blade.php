<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ $this->isEdit ? __('client.edit') : __('client.create') }} {{ __('admin.email_template') }}</h1>
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
                @error('tenantId')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.email_template_name') }}</span></label>
                <input wire:model="name" id="name" type="text" class="input input-bordered w-full" />
                @error('name')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="subject"><span class="label-text font-medium">{{ __('admin.email_template_subject') }}</span></label>
                <input wire:model="subject" id="subject" type="text" class="input input-bordered w-full" />
                @error('subject')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label justify-start gap-2 pb-1" for="body"><span class="label-text font-medium">{{ __('admin.email_template_body') }}</span></label>
                <textarea wire:model="body" id="body" rows="10" class="textarea textarea-bordered w-full font-mono text-sm"></textarea>
                @error('body')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('client.save') }}</button>
                <a href="{{ route('panel.email-templates.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
