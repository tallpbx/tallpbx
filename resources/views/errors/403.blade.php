<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('admin.error_page_403_title') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        @fonts
        @vite(['resources/css/app.css', 'resources/css/custom.css'])
    </head>
    <body class="bg-base-200 text-base-content min-h-screen flex items-center justify-center p-4 font-sans antialiased">
        <div class="card w-full max-w-md bg-base-100 border border-base-300 p-8 text-center shadow-xl">
            <x-heroicon-o-shield-exclamation class="mx-auto h-12 w-12 text-error" />
            <h1 class="mt-4 text-2xl font-bold tracking-tight text-base-content">{{ __('admin.error_page_403_title') }}</h1>
            <p class="mt-3 text-sm text-base-content/70">{{ $exception?->getMessage() ?: __('admin.error_page_403_message') }}</p>

            @php
                $impersonationService = app(\App\Services\ImpersonationServiceInterface::class);
            @endphp

            @if($impersonationService->isImpersonating())
                @php $originalAdmin = $impersonationService->getOriginalAdmin(); @endphp
                <div class="mt-6 p-4 rounded-lg bg-warning/15 border border-warning/30 text-warning-content text-left">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning shrink-0 mt-0.5" />
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-medium text-base-content">
                                {{ __('client.impersonating', ['name' => Auth::user()?->name ?? 'User', 'admin' => $originalAdmin?->name ?? 'Admin']) }}
                            </p>
                            <div class="mt-3">
                                <a href="{{ route('panel.impersonation.stop') }}" class="btn btn-warning btn-sm w-full">
                                    {{ __('client.stop_impersonating') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-6 flex flex-col gap-2">
                    <a href="{{ url()->previous() ?: route('panel.dashboard') }}" class="btn btn-primary btn-sm">
                        {{ __('admin.back') }}
                    </a>
                </div>
            @endif
        </div>
    </body>
</html>
