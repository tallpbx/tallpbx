<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Tenant' : 'Create Tenant' }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <form wire:submit="save" class="card-body">
            {{-- Name --}}
            <div class="form-control w-full">
                <label for="name" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Name</span>
                </label>
                <input type="text" id="name" wire:model="name"
                       class="input input-bordered w-full" placeholder="Tenant name" required />
                @error('name')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Slug --}}
            <div class="form-control w-full mt-4">
                <label for="slug" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Slug</span>
                </label>
                <input type="text" id="slug" wire:model="slug"
                       class="input input-bordered w-full" placeholder="tenant-slug" required />
                @error('slug')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Primary User --}}
            <div class="form-control w-full mt-4">
                <label for="primaryUserId" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Primary User</span>
                </label>
                <select id="primaryUserId" wire:model="primaryUserId"
                        class="select select-bordered w-full">
                    <option value="">None</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                @error('primaryUserId')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Enabled --}}
            <div class="form-control w-full mt-4">
                <label class="label cursor-pointer justify-start gap-4">
                    <input type="checkbox" wire:model="enabled"
                           class="toggle toggle-primary" />
                    <span class="label-text font-medium">Enabled</span>
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 mt-6">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-check class="w-4 h-4" />
                    {{ $this->isEdit ? 'Update Tenant' : 'Create Tenant' }}
                </button>
                <a href="{{ route('panel.tenants.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
