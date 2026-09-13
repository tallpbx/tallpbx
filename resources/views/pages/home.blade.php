<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="themeData()" x-init="initTheme()" :data-theme="theme === 'dark' ? 'dark' : (theme === 'light' ? 'light' : (systemDark ? 'dark' : 'light'))">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('home.seo.title') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <meta name="description" content="{{ __('home.seo.description') }}">
        <meta name="keywords" content="{{ __('home.seo.keywords') }}">

        <!-- Hreflang Tags for SEO -->
        <link rel="alternate" hreflang="en" href="{{ url('en') }}" />
        <link rel="alternate" hreflang="es" href="{{ url('es') }}" />
        <link rel="alternate" hreflang="fr" href="{{ url('fr') }}" />
        <link rel="alternate" hreflang="x-default" href="{{ url('en') }}" />

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px) rotate(0deg); }
                50% { transform: translateY(-8px) rotate(0.5deg); }
            }
            @keyframes dash {
                to { stroke-dashoffset: -40; }
            }
            @keyframes pulse-slow {
                0%, 100% { transform: scale(1); opacity: 0.25; }
                50% { transform: scale(1.08); opacity: 0.5; }
            }
            .animate-float { animation: float 6s ease-in-out infinite; }
            .animate-dash-slow { animation: dash 3s linear infinite; }
            .animate-dash-fast { animation: dash 1.5s linear infinite; }
            .animate-pulse-slow { animation: pulse-slow 4s ease-in-out infinite; }
        </style>

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
    <body class="bg-base-200 text-base-content min-h-screen flex flex-col font-sans transition-colors duration-300 antialiased selection:bg-primary selection:text-white relative overflow-x-hidden">

        <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-primary/10 rounded-full filter blur-[80px] pointer-events-none -mr-48 -mt-24"></div>
        <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-purple-500/10 rounded-full filter blur-[70px] pointer-events-none -ml-40 -mb-20"></div>

        <header class="w-full max-w-7xl mx-auto px-6 py-5 relative z-20">
            <div class="bg-base-100/70 backdrop-blur-md border border-base-300/80 rounded-2xl px-6 py-4 flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3">
                    <a href="{{ url('/') }}" class="flex items-center gap-2.5 group">
                        <div class="bg-primary p-1.5 rounded-lg text-white shadow-md shadow-primary/25 transition-transform group-hover:scale-105">
                            <x-heroicon-o-phone class="w-5 h-5" />
                        </div>
                        <span class="text-xl font-bold tracking-tight text-base-content">{{ config('app.name', 'TallPBX') }}</span>
                    </a>
                </div>

                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('panel.login') }}" class="px-4 py-2 text-sm font-medium text-base-content/70 hover:text-base-content transition-colors">
                            {{ __('home.nav.client_sign_in') }}
                        </a>
                        <a href="{{ route('panel.login') }}" class="px-4 py-2 text-sm font-medium text-base-content/70 border border-base-300 hover:bg-base-200 rounded-xl transition-all">
                            {{ __('home.nav.administrator') }}
                        </a>
                    </div>

                    <div class="h-6 w-px bg-base-300"></div>

                    <x-common.language-switcher variant="home" />
                    @livewire('layout.theme-switcher')
                </div>
            </div>
        </header>

        <main class="flex-1 flex flex-col justify-center items-center relative z-10 w-full max-w-7xl mx-auto px-6 py-8 md:py-16">

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center w-full mb-16">

                <div class="lg:col-span-7 text-left space-y-6">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-primary/10 text-primary text-xs font-semibold tracking-wider uppercase rounded-full border border-primary/20 animate-pulse">
                        <span class="w-1.5 h-1.5 bg-primary rounded-full"></span>
                        {{ __('home.hero.title') }}
                    </div>

                    <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold tracking-tight text-base-content leading-none">
                        {{ config('app.name', 'TallPBX') }}
                        <span class="block mt-3 text-2xl sm:text-3xl md:text-4xl text-transparent bg-clip-text bg-gradient-to-r from-primary via-purple-600 to-primary">
                            FreeSWITCH Telephone Platform
                        </span>
                    </h1>

                    <p class="text-lg text-base-content/60 max-w-xl leading-relaxed">
                        {{ __('home.hero.subtitle') }}
                    </p>

                    <p class="text-base text-base-content/60 max-w-xl leading-relaxed">
                        {{ __('home.hero.summary') }}
                    </p>

                    <p class="text-base text-base-content/60 max-w-xl leading-relaxed">
                        {{ __('home.hero.modular_summary') }}
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 pt-4">
                        <a href="{{ route('panel.login') }}" class="btn btn-primary gap-2 rounded-2xl">
                            <span>{{ __('home.nav.client_sign_in') }}</span>
                            <x-heroicon-o-arrow-right class="w-4 h-4" />
                        </a>
                        <a href="{{ route('panel.login') }}" class="btn btn-ghost rounded-2xl border border-base-300">
                            {{ __('home.nav.administrator') }}
                        </a>
                    </div>
                </div>

                <div class="lg:col-span-5 flex justify-center items-center">
                    <div class="relative w-full max-w-md aspect-square rounded-3xl bg-base-100/40 border border-base-300/40 p-6 shadow-xl backdrop-blur-md overflow-hidden">

                        <div class="absolute inset-0 bg-radial-[circle_at_center] from-primary/5 to-transparent"></div>

                        <svg class="w-full h-full text-primary" viewBox="0 0 400 400" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <radialGradient id="glow-grad" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="currentColor" stop-opacity="0.25" />
                                    <stop offset="100%" stop-color="currentColor" stop-opacity="0.0" />
                                </radialGradient>
                                <linearGradient id="line-grad" x1="0" y1="0" x2="400" y2="400" gradientUnits="userSpaceOnUse">
                                    <stop offset="0%" stop-color="#4f46e5" />
                                    <stop offset="50%" stop-color="#a855f7" />
                                    <stop offset="100%" stop-color="#6366f1" />
                                </linearGradient>
                            </defs>

                            <g stroke="currentColor" stroke-width="0.5" opacity="0.08">
                                <line x1="50" y1="0" x2="50" y2="400" />
                                <line x1="150" y1="0" x2="150" y2="400" />
                                <line x1="250" y1="0" x2="250" y2="400" />
                                <line x1="350" y1="0" x2="350" y2="400" />
                                <line x1="0" y1="50" x2="400" y2="50" />
                                <line x1="0" y1="150" x2="400" y2="150" />
                                <line x1="0" y1="250" x2="400" y2="250" />
                                <line x1="0" y1="350" x2="400" y2="350" />
                            </g>

                            <circle cx="200" cy="200" r="100" fill="url(#glow-grad)" class="animate-pulse-slow" />

                            <path d="M 70 110 Q 140 130 200 200" stroke="url(#line-grad)" stroke-width="2" stroke-linecap="round" stroke-dasharray="8, 12" class="animate-dash-fast" opacity="0.8" fill="none" />
                            <path d="M 70 290 Q 140 270 200 200" stroke="url(#line-grad)" stroke-width="2" stroke-linecap="round" stroke-dasharray="8, 12" class="animate-dash-slow" opacity="0.5" fill="none" />

                            <path d="M 200 200 Q 280 130 330 90" stroke="url(#line-grad)" stroke-width="2" stroke-linecap="round" stroke-dasharray="8, 12" class="animate-dash-fast" opacity="0.8" fill="none" />
                            <path d="M 200 200 Q 280 230 330 210" stroke="url(#line-grad)" stroke-width="2" stroke-linecap="round" stroke-dasharray="8, 12" class="animate-dash-slow" opacity="0.7" fill="none" />
                            <path d="M 200 200 Q 260 280 330 310" stroke="url(#line-grad)" stroke-width="2" stroke-linecap="round" stroke-dasharray="8, 12" class="animate-dash-fast" opacity="0.6" fill="none" />

                            <g class="animate-float">
                                <rect x="160" y="160" width="80" height="80" rx="20" fill="white" class="fill-base-100" stroke="url(#line-grad)" stroke-width="2.5" shadow="lg" />
                                <circle cx="200" cy="200" r="18" fill="currentColor" opacity="0.1" />
                                <path d="M192 195v10m16-10v10m-24-18a4 4 0 014-4h16a4 4 0 014 4v4H184v-4z" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                <circle cx="200" cy="200" r="4" fill="currentColor" class="animate-ping" />
                            </g>

                            <circle cx="70" cy="110" r="14" fill="white" class="fill-base-100" stroke="currentColor" stroke-width="2" />
                            <path d="M65 110h10M70 105v10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />

                            <circle cx="70" cy="290" r="14" fill="white" class="fill-base-100" stroke="currentColor" stroke-width="2" />
                            <path d="M65 290h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />

                            <circle cx="330" cy="90" r="14" fill="white" class="fill-base-100" stroke="currentColor" stroke-width="2" />
                            <path d="M324 88a3 3 0 013-3h6a3 3 0 013 3v4h-12v-4z" fill="currentColor" opacity="0.3" />
                            <circle cx="330" cy="90" r="3" fill="currentColor" />

                            <circle cx="330" cy="210" r="14" fill="white" class="fill-base-100" stroke="currentColor" stroke-width="2" />
                            <circle cx="330" cy="210" r="3" fill="currentColor" />

                            <circle cx="330" cy="310" r="14" fill="white" class="fill-base-100" stroke="currentColor" stroke-width="2" />
                            <circle cx="330" cy="310" r="3" fill="currentColor" />

                            <circle cx="120" cy="120" r="3.5" fill="#a855f7" class="animate-ping" />
                            <circle cx="280" cy="225" r="3" fill="#6366f1" />
                            <circle cx="260" cy="145" r="4.5" fill="#4f46e5" class="animate-pulse" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="w-full space-y-8">
                <div class="text-center max-w-2xl mx-auto space-y-3">
                    <h2 class="text-3xl font-bold tracking-tight text-base-content">
                        {{ __('home.features.title') }}
                    </h2>
                    <p class="text-base-content/60">
                        {{ __('home.features.subtitle') }}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

                    <div class="card bg-base-100 border border-base-300 rounded-2xl p-6 space-y-4 hover:border-primary/50 hover:shadow-md transition-all group duration-300">
                        <div class="bg-primary/10 text-primary p-3 rounded-xl w-fit group-hover:scale-105 transition-transform">
                            <x-heroicon-o-users class="w-6 h-6" />
                        </div>
                        <h3 class="text-lg font-bold text-base-content">{{ __('home.features.extensions.title') }}</h3>
                        <p class="text-sm text-base-content/60 leading-relaxed">
                            {{ __('home.features.extensions.desc') }}
                        </p>
                    </div>

                    <div class="card bg-base-100 border border-base-300 rounded-2xl p-6 space-y-4 hover:border-primary/50 hover:shadow-md transition-all group duration-300">
                        <div class="bg-primary/10 text-primary p-3 rounded-xl w-fit group-hover:scale-105 transition-transform">
                            <x-heroicon-o-server class="w-6 h-6" />
                        </div>
                        <h3 class="text-lg font-bold text-base-content">{{ __('home.features.trunks.title') }}</h3>
                        <p class="text-sm text-base-content/60 leading-relaxed">
                            {{ __('home.features.trunks.desc') }}
                        </p>
                    </div>

                    <div class="card bg-base-100 border border-base-300 rounded-2xl p-6 space-y-4 hover:border-primary/50 hover:shadow-md transition-all group duration-300">
                        <div class="bg-primary/10 text-primary p-3 rounded-xl w-fit group-hover:scale-105 transition-transform">
                            <x-heroicon-o-globe-alt class="w-6 h-6" />
                        </div>
                        <h3 class="text-lg font-bold text-base-content">{{ __('home.features.inbound.title') }}</h3>
                        <p class="text-sm text-base-content/60 leading-relaxed">
                            {{ __('home.features.inbound.desc') }}
                        </p>
                    </div>

                    <div class="card bg-base-100 border border-base-300 rounded-2xl p-6 space-y-4 hover:border-primary/50 hover:shadow-md transition-all group duration-300">
                        <div class="bg-primary/10 text-primary p-3 rounded-xl w-fit group-hover:scale-105 transition-transform">
                            <x-heroicon-o-arrows-right-left class="w-6 h-6" />
                        </div>
                        <h3 class="text-lg font-bold text-base-content">{{ __('home.features.outbound.title') }}</h3>
                        <p class="text-sm text-base-content/60 leading-relaxed">
                            {{ __('home.features.outbound.desc') }}
                        </p>
                    </div>

                </div>
            </div>

        </main>

        <footer class="w-full max-w-7xl mx-auto px-6 py-8 border-t border-base-300 mt-12 relative z-10 flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-base-content/50">
            <div>{!! __('admin.copyright', ['year' => date('Y')]) !!}</div>
        </footer>

        @livewireScripts
    </body>
</html>
