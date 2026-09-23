<div class="max-w-3xl">
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $adminId ? __('admin.admin_edit') : __('admin.admin_add') }}
        </h2>
        <p class="text-sm text-base-content/60 mt-1">
            {{ __('admin.admin_edit_description') }}
        </p>
    </div>

    <form wire:submit.prevent="save" class="space-y-6">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body space-y-4">
                {{-- Name --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.name') }}</span></label>
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full @error('name') input-error @enderror" required />
                    @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Email --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="email"><span class="label-text font-medium">{{ __('admin.email') }}</span></label>
                    <input type="email" id="email" wire:model="email" class="input input-bordered w-full @error('email') input-error @enderror" required />
                    @error('email') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Password --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="password">
                        <span class="label-text font-medium">{{ $adminId ? __('admin.new_password') : __('admin.password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ $adminId ? __('admin.admin_password_help') : __('admin.password_requirements_min') }}</span>
                    </label>
                    <input type="password" id="password" wire:model="password" autocomplete="new-password" class="input input-bordered w-full @error('password') input-error @enderror" placeholder="{{ $adminId ? __('admin.admin_password_help') : __('admin.password_requirements_min') }}" {{ $adminId ? '' : 'required' }} />
                    <p class="text-xs text-base-content/60 mt-1">{{ $adminId ? __('admin.admin_password_edit_hint') : __('admin.admin_password_create_hint') }}</p>
                    @error('password') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Password Confirmation --}}
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="password_confirmation">
                        <span class="label-text font-medium">{{ $adminId ? __('admin.confirm_new_password') : __('admin.confirm_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('admin.confirm_password_help') }}</span>
                    </label>
                    <input type="password" id="password_confirmation" wire:model="password_confirmation" autocomplete="new-password" class="input input-bordered w-full" placeholder="{{ __('admin.confirm_password') }}" {{ $adminId ? '' : 'required' }} />
                    <p class="text-xs text-base-content/60 mt-1">{{ __('admin.confirm_password_hint') }}</p>
                </div>

                {{-- Enabled Toggle --}}
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="toggle toggle-primary" />
                        <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                    </label>
                    @error('enabled') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- System Groups --}}
                <div class="form-control w-full pt-2">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">{{ __('admin.admin_groups') }}</span></label>
                    <div class="space-y-2 mt-1">
                        @foreach ($systemGroups as $group)
                            <label class="flex items-start gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-200/50 cursor-pointer">
                                <input type="checkbox" wire:model="selectedGroupIds" value="{{ $group->id }}" class="checkbox checkbox-primary mt-0.5" />
                                <div>
                                    <div class="font-medium text-sm">{{ $group->name }}</div>
                                    @if ($group->description)
                                        <div class="text-xs text-base-content/60 mt-0.5">{{ $group->description }}</div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedGroupIds') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('admin.save') }}</span>
                <span wire:loading wire:target="save" class="loading loading-spinner loading-xs"></span>
            </button>
            <a href="{{ route('panel.admins.index') }}" wire:navigate class="btn btn-ghost">
                {{ __('admin.cancel') }}
            </a>
        </div>
    </form>
</div>
