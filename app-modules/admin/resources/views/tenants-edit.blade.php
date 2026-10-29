<div>
    <x-page-header :title="$this->isEdit ? 'Edit Tenant' : 'Create Tenant'" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <form wire:submit="save" class="card-body">
            {{-- Name --}}
            <div class="form-control w-full">
                <label for="name" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                </label>
                <input type="text" id="name" wire:model="name"
                       class="input input-bordered w-full" placeholder="Tenant name" required />
                <x-form-error name="name" />
            </div>

            {{-- Slug --}}
            <div class="form-control w-full mt-4">
                <label for="slug" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.admin_slug') }}</span>
                </label>
                <input type="text" id="slug" wire:model="slug"
                       class="input input-bordered w-full" placeholder="tenant-slug" required />
                <x-form-error name="slug" />
            </div>

            {{-- Primary User --}}
            <div class="form-control w-full mt-4">
                <label for="primaryUserId" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.primary_user') }}</span>
                </label>
                <select id="primaryUserId" wire:model="primaryUserId"
                        class="select select-bordered w-full">
                    <option value="">{{ __('admin.none') }}</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                <x-form-error name="primaryUserId" />
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full mt-4">
                <label class="label cursor-pointer justify-start gap-4"
                       wire:loading.class="opacity-70 pointer-events-none"
                       wire:target="enabled">
                    <input type="checkbox" wire:model="enabled"
                           wire:loading.attr="disabled"
                           wire:target="enabled"
                           class="toggle toggle-sm toggle-primary" />
                    <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 mt-6">
                <x-form-submit-button icon="heroicon-o-check">
                    {{ $this->isEdit ? 'Update Tenant' : 'Create Tenant' }}
                </x-form-submit-button>
                <a href="{{ route('panel.tenants.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
