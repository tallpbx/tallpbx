@props([
    'variant' => 'portal' // 'portal' or 'home'
])

<div x-data="{ 
    open: false,
    locales: {
        'en': { name: 'English', flag: 'gb' },
        'es': { name: 'Español', flag: 'es' },
        'fr': { name: 'Français', flag: 'fr' }
    },
    currentLocale: '{{ app()->getLocale() }}',
    variant: '{{ $variant }}'
}" class="relative">
    <button @click="open = !open" 
            @click.away="open = false"
            class="flex items-center gap-2 px-3 py-2 rounded-xl transition-all border text-sm font-bold uppercase tracking-tight shadow-sm {{ $variant === 'portal' ? 'bg-base-200/40 hover:bg-base-300/60 border-base-300 text-base-content' : 'bg-base-200 hover:bg-base-300 border-base-300 text-base-content' }}">
        <img :src="'https://flagcdn.com/' + locales[currentLocale].flag + '.svg'" 
             :alt="locales[currentLocale].name" 
             class="w-5 h-auto rounded-sm shadow-sm">
        <span x-text="currentLocale" class="tracking-wider"></span>
        <svg :class="{'rotate-180': open}" class="w-3 h-3 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="open"
         @click.away="open = false"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-[-10px]"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
         x-transition:leave-end="opacity-0 scale-95 translate-y-[-10px]"
         class="absolute right-0 mt-2 w-48 rounded-2xl bg-base-100 shadow-2xl border border-base-300 py-2 z-50 overflow-hidden"
         style="display: none;">
        
        <template x-for="(data, code) in locales" :key="code">
            <a :href="variant === 'home' ? '/' + code : '{{ route('lang.switch', ['locale' => 'LOCALE_PLACEHOLDER']) }}'.replace('LOCALE_PLACEHOLDER', code)"
               @click="if (variant === 'home' && window.Livewire) { $event.preventDefault(); window.Livewire.navigate('/' + code); }"
               class="flex items-center gap-3 px-4 py-2.5 hover:bg-base-200 transition-colors group">
                <img :src="'https://flagcdn.com/' + data.flag + '.svg'" :alt="data.name" class="w-6 h-auto rounded-sm shadow-sm">
                <span x-text="data.name" 
                      class="text-sm font-semibold text-base-content"
                      :class="currentLocale === code ? 'text-primary' : ''">
                </span>
                <template x-if="currentLocale === code">
                    <svg class="w-4 h-4 ml-auto text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </template>
            </a>
        </template>
    </div>
</div>
