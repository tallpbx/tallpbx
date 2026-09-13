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
                    <label for="password" class="label"><span class="label-text">New Password</span></label>
                    <input type="password" id="password" wire:model="password"
                           class="input input-bordered w-full" placeholder="Min 8 characters" required />
                    @error('password') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Confirm Password --}}
                <div class="form-control mt-4">
                    <label for="password_confirmation" class="label"><span class="label-text">Confirm Password</span></label>
                    <input type="password" id="password_confirmation" wire:model="password_confirmation"
                           class="input input-bordered w-full" placeholder="Confirm password" required />
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">{{ __('client.reset_password') }}</button>
            </form>

            <div class="text-center mt-4 text-sm text-base-content/60">
                <a href="{{ route('panel.login.tenant') }}" class="link link-primary">Back to Sign in</a>
            </div>
        </div>
    </div>
</div>
