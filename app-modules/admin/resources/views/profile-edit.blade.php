<div class="max-w-4xl space-y-6">
    <x-page-header :title="__('admin.my_profile')" :subtitle="__('admin.profile_description')" />

    {{-- Profile Information Card --}}
    <div class="card bg-base-100 border border-base-300 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-lg font-medium">{{ __('admin.profile_information') }}</h3>

            @if ($profileSuccess)
                <div class="alert alert-success mt-3 py-2 text-sm">
                    <x-heroicon-o-check-circle class="w-5 h-5 shrink-0" />
                    <span>{{ $profileSuccess }}</span>
                </div>
            @endif

            <form wire:submit.prevent="updateProfile" class="space-y-4 mt-3">
                <div class="form-control w-full max-w-md">
                    <label class="label justify-start gap-2 pb-1" for="name"><span class="label-text font-medium">{{ __('admin.name') }}</span></label>
                    <input type="text" id="name" wire:model="name" class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}" required />
                    <x-form-error name="name" />
                </div>

                <div class="form-control w-full max-w-md">
                    <label class="label justify-start gap-2 pb-1" for="email"><span class="label-text font-medium">{{ __('admin.email') }}</span></label>
                    <input type="email" id="email" wire:model="email" class="input input-bordered w-full {{ $errors->has('email') ? 'input-error' : '' }}" required />
                    <x-form-error name="email" />
                </div>

                <div class="pt-2">
                    <x-form-submit-button target="updateProfile">
                        {{ __('admin.save') }}
                    </x-form-submit-button>
                </div>
            </form>
        </div>
    </div>

    {{-- Password Change Card --}}
    <div class="card bg-base-100 border border-base-300 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-lg font-medium">{{ __('admin.password_change') }}</h3>

            @if ($passwordSuccess)
                <div class="alert alert-success mt-3 py-2 text-sm">
                    <x-heroicon-o-check-circle class="w-5 h-5 shrink-0" />
                    <span>{{ $passwordSuccess }}</span>
                </div>
            @endif

            <form wire:submit.prevent="updatePassword" class="space-y-4 mt-3">
                <div class="form-control w-full max-w-md">
                    <label class="label justify-start gap-2 pb-1" for="currentPassword"><span class="label-text font-medium">{{ __('admin.current_password') }}</span></label>
                    <input type="password" id="currentPassword" wire:model="currentPassword" autocomplete="current-password" class="input input-bordered w-full {{ $errors->has('currentPassword') ? 'input-error' : '' }}" required />
                    <x-form-error name="currentPassword" />
                </div>

                <div class="form-control w-full max-w-md">
                    <label class="label justify-start gap-2 pb-1" for="newPassword">
                        <span class="label-text font-medium">{{ __('admin.new_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('admin.password_requirements_min') }}</span>
                    </label>
                    <input type="password" id="newPassword" wire:model="newPassword" autocomplete="new-password" placeholder="{{ __('admin.password_requirements_min') }}" class="input input-bordered w-full {{ $errors->has('newPassword') ? 'input-error' : '' }}" required />
                    <p class="text-xs text-base-content/60 mt-1">{{ __('admin.profile_new_password_hint') }}</p>
                    <x-form-error name="newPassword" />
                </div>

                <div class="form-control w-full max-w-md">
                    <label class="label justify-start gap-2 pb-1" for="newPassword_confirmation">
                        <span class="label-text font-medium">{{ __('admin.confirm_new_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('admin.confirm_password_help') }}</span>
                    </label>
                    <input type="password" id="newPassword_confirmation" wire:model="newPassword_confirmation" autocomplete="new-password" placeholder="{{ __('admin.confirm_new_password') }}" class="input input-bordered w-full" required />
                    <p class="text-xs text-base-content/60 mt-1">{{ __('admin.confirm_password_hint') }}</p>
                </div>

                <div class="pt-2">
                    <x-form-submit-button target="updatePassword">
                        {{ __('admin.update_password') }}
                    </x-form-submit-button>
                </div>
            </form>
        </div>
    </div>
</div>
