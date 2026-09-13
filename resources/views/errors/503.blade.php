<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('admin.error_page_503_title') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        @fonts
        @vite(['resources/css/app.css', 'resources/css/custom.css'])
    </head>
    <body class="bg-base-200 text-base-content min-h-screen flex items-center justify-center p-4 font-sans antialiased">
        <div class="card w-full max-w-md bg-base-100 border border-base-300 p-8 text-center shadow-xl">
            <x-heroicon-o-clock class="mx-auto h-12 w-12 text-warning" />
            <h1 class="mt-4 text-2xl font-bold">{{ __('admin.error_page_503_title') }}</h1>
            <p class="mt-3 text-sm text-base-content/70">{{ __('admin.error_page_503_message') }}</p>
        </div>
    </body>
</html>
