<div class="min-h-screen flex items-center justify-center bg-base-200">
    <div class="card bg-base-100 border border-base-300 w-full max-w-md">
        <div class="card-body">
            <h2 class="card-title text-2xl mb-4">{{ __('client.reset_password') }}</h2>
            <p class="text-sm text-base-content/60 mb-4">
                Enter your email and a new password to reset your account.
            </p>

            <form wire:submit="resetPassword">
                {{-- Hidden Token --}}
                <input type="hidden" wire:model="token" />

                {{-- Email --}}
                <div class="form-control">
                    <label for="email" class="label"><span class="label-text">Email</span></label>
                    <input type="email" id="email" wire:model="email"
                           class="input input-bordered w-full" placeholder="email@example.com" required />
                    @error('email') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Password --}}
                <div class="form-control mt-4">
                    <label for="password" class="label justify-start gap-2 pb-1">
                        <span class="label-text">{{ __('client.new_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('client.password_requirements_min') }}</span>
                    </label>
                    <input type="password" id="password" wire:model="password"
                           class="input input-bordered w-full @error('password') input-error @enderror" placeholder="{{ __('client.password_requirements_min') }}" required />
                    <p class="text-xs text-base-content/60 mt-1">{{ __('client.password_requirements_hint') }}</p>
                    @error('password') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Confirm Password --}}
                <div class="form-control mt-4">
                    <label for="password_confirmation" class="label justify-start gap-2 pb-1">
                        <span class="label-text">{{ __('client.confirm_password') }}</span>
                        <span class="label-text-alt text-base-content/60">{{ __('client.confirm_password_help') }}</span>
                    </label>
                    <input type="password" id="password_confirmation" wire:model="password_confirmation"
                           class="input input-bordered w-full" placeholder="{{ __('client.confirm_password') }}" required />
                    <p class="text-xs text-base-content/60 mt-1">{{ __('client.confirm_password_hint') }}</p>
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">{{ __('client.reset_password') }}</button>
            </form>

            <div class="text-center mt-4 text-sm text-base-content/60">
                <a href="{{ route('panel.login') }}" class="link link-primary">{{ __('client.back_to_sign_in') }}</a>
            </div>
        </div>
    </div>
</div>
