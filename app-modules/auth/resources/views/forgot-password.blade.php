<div class="min-h-screen flex items-center justify-center bg-base-200">
    <div class="card bg-base-100 border border-base-300 w-full max-w-md">
        <div class="card-body">
            <h2 class="card-title text-2xl mb-4">{{ __('client.forgot_password') }}</h2>
            <p class="text-sm text-base-content/60 mb-4">
                {{ __('client.forgot_password_intro') }}
            </p>

            <form wire:submit="sendResetLink">
                {{-- Email --}}
                <div class="form-control">
                    <label for="email" class="label"><span class="label-text">{{ __('client.email') }}</span></label>
                    <input type="email" id="email" wire:model="email"
                           class="input input-bordered w-full" placeholder="{{ __('client.email_placeholder') }}" required />
                    @error('email') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">{{ __('client.send') }}</button>
            </form>

            <div class="text-center mt-4 text-sm text-base-content/60">
                <a href="{{ route('panel.login.tenant') }}" class="link link-primary">{{ __('client.back_to_sign_in') }}</a>
            </div>
        </div>
    </div>
</div>
