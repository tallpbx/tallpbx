<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Administrator — TallPBX</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-base-200 text-base-content min-h-screen flex items-center justify-center">
    <main class="w-full max-w-md mx-4">
        <div class="text-center mb-8">
            <x-heroicon-o-shield-check class="w-12 h-12 text-primary mx-auto mb-3" />
            <h1 class="text-2xl font-bold">Create the first administrator</h1>
            <p class="text-sm text-base-content/60 mt-1">This step is available only until an administrator account is created.</p>
        </div>

        <div class="card bg-base-100 border border-base-300 p-6">
            @if ($errors->any())
                <div class="alert alert-error mb-4 text-sm">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('panel.initial-admin.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="label" for="email"><span class="label-text">Administrator email</span></label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="input input-bordered w-full" />
                </div>
                @if ($requiresActivationCode)
                    <div class="mb-4">
                        <label class="label" for="activation_code"><span class="label-text">One-time activation code</span></label>
                        <input type="text" name="activation_code" id="activation_code" required autocomplete="one-time-code"
                            class="input input-bordered w-full" />
                    </div>
                @endif
                <div class="mb-4">
                    <label class="label justify-start gap-2 pb-1" for="password">
                        <span class="label-text font-medium">Password</span>
                        <span class="label-text-alt text-base-content/60">Minimum 8 characters</span>
                    </label>
                    <input type="password" name="password" id="password" required autocomplete="new-password"
                        placeholder="Minimum 8 characters"
                        class="input input-bordered w-full @error('password') input-error @enderror" />
                    <p class="text-xs text-base-content/60 mt-1">Must be at least 8 characters.</p>
                    @error('password') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="mb-6">
                    <label class="label justify-start gap-2 pb-1" for="password_confirmation">
                        <span class="label-text font-medium">Confirm password</span>
                        <span class="label-text-alt text-base-content/60">Must match password</span>
                    </label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                        placeholder="Confirm password"
                        class="input input-bordered w-full" />
                    <p class="text-xs text-base-content/60 mt-1">Re-enter password to confirm.</p>
                </div>
                <button type="submit" class="btn btn-primary w-full">Create administrator</button>
            </form>
        </div>
    </main>
</body>
</html>
