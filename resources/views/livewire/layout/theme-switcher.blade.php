<div class="join">
    <button
        wire:click="setTheme('light')"
        class="btn btn-sm join-item {{ $theme === 'light' ? 'btn-active' : 'btn-ghost' }}"
        title="{{ __('admin.light') }}"
        aria-label="{{ __('admin.light') }}"
    >
        <x-heroicon-o-sun class="w-4 h-4" />
    </button>
    <button
        wire:click="setTheme('dark')"
        class="btn btn-sm join-item {{ $theme === 'dark' ? 'btn-active' : 'btn-ghost' }}"
        title="{{ __('admin.dark') }}"
        aria-label="{{ __('admin.dark') }}"
    >
        <x-heroicon-o-moon class="w-4 h-4" />
    </button>
    <button
        wire:click="setTheme('system')"
        class="btn btn-sm join-item {{ $theme === 'system' ? 'btn-active' : 'btn-ghost' }}"
        title="{{ __('admin.system') }}"
        aria-label="{{ __('admin.system') }}"
    >
        <x-heroicon-o-computer-desktop class="w-4 h-4" />
    </button>
</div>
