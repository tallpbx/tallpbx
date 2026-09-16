<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('admin.sign_in') }} — {{ config('app.name', 'TallPBX') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script>
        const savedTheme = localStorage.getItem('theme') || 'system';
        let isDark = false;
        if (savedTheme === 'dark') {
            isDark = true;
        } else if (savedTheme === 'system') {
            isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    </script>
</head>
<body class="bg-base-200 text-base-content min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md mx-4">
        <div class="text-center mb-8">
            <x-heroicon-o-phone class="w-12 h-12 text-primary mx-auto mb-3" />
            <h1 class="text-2xl font-bold">{{ config('app.name', 'TallPBX') }}</h1>
            <p class="text-sm text-base-content/60 mt-1">{{ __('client.sign_in_title') }}</p>
        </div>

        <div class="card bg-base-100 border border-base-300 p-6">
            @if($errors->any())
                <div class="alert alert-error mb-4 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('panel.login.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="label"><span class="label-text">{{ __('admin.email') }}</span></label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                        class="input input-bordered w-full" />
                </div>
                <div class="mb-4">
                    <label class="label"><span class="label-text">{{ __('admin.password') }}</span></label>
                    <input type="password" name="password" id="password" required
                        class="input input-bordered w-full" />
                </div>
                <div class="mb-6 flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" id="remember" class="checkbox checkbox-primary" />
                        <span class="text-sm text-base-content/60">{{ __('admin.remember_me') }}</span>
                    </label>
                    <a href="{{ route('panel.password.request') }}" class="text-sm link link-primary">{{ __('client.forgot_password') }}</a>
                </div>
                <button type="submit" class="btn btn-primary w-full">{{ __('admin.sign_in') }}</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:navigated', () => {
            const saved = localStorage.getItem('theme') || 'system';
            let isDark = false;
            if (saved === 'dark') {
                isDark = true;
            } else if (saved === 'system') {
                isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        });
    </script>
</body>
</html>
