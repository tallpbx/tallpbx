<div class="min-h-screen flex items-center justify-center bg-base-200">
    <div class="card bg-base-100 border border-base-300 w-full max-w-md">
        <div class="card-body">
            <h2 class="card-title text-2xl mb-4">{{ __('client.create_account') }}</h2>

            <form wire:submit="register">
                {{-- Name --}}
                <div class="form-control">
                    <label for="name" class="label"><span class="label-text">{{ __('client.name') }}</span></label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full" placeholder="{{ __('client.name_placeholder') }}" required />
                    @error('name') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Email --}}
                <div class="form-control mt-4">
                    <label for="email" class="label"><span class="label-text">{{ __('client.email') }}</span></label>
                    <input type="email" id="email" wire:model="email"
                           class="input input-bordered w-full" placeholder="{{ __('client.email_placeholder') }}" required />
                    @error('email') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Password --}}
                <div class="form-control mt-4">
                    <label for="password" class="label"><span class="label-text">{{ __('client.password') }}</span></label>
                    <input type="password" id="password" wire:model="password"
                           class="input input-bordered w-full" placeholder="{{ __('client.password_placeholder') }}" required />
                    @error('password') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                {{-- Confirm Password --}}
                <div class="form-control mt-4">
                    <label for="password_confirmation" class="label"><span class="label-text">{{ __('client.password_confirmation') }}</span></label>
                    <input type="password" id="password_confirmation" wire:model="password_confirmation"
                           class="input input-bordered w-full" placeholder="{{ __('client.password') }}" required />
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">{{ __('client.create_account') }}</button>
            </form>

            <div class="text-center mt-4 text-sm text-base-content/60">
                {{ __('client.already_have_account') }}
                <a href="{{ route('panel.login') }}" class="link link-primary">{{ __('client.sign_in') }}</a>
            </div>
        </div>
    </div>
</div>
