{{-- Zone 5: Settings Slide-Over Drawer --}}
<div x-data="{ open: @entangle('showSettingsDrawer') }"
     x-show="open"
     x-cloak
     class="relative z-50">
    {{-- Backdrop --}}
    <div x-show="open"
         x-transition:enter="ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="open = false; $wire.closeSettingsDrawer()"
         class="fixed inset-0 bg-black/40 backdrop-blur-xs"></div>

    {{-- Drawer Panel --}}
    <div class="fixed inset-y-0 right-0 max-w-md w-full bg-base-100 shadow-2xl p-6 overflow-y-auto flex flex-col justify-between border-l border-base-200">
        <div class="space-y-6">
            <div class="flex items-center justify-between pb-4 border-b border-base-200">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cog-6-tooth class="w-6 h-6 text-primary" />
                    <h3 class="text-lg font-bold text-base-content">{{ __('admin.security_protection_settings') }}</h3>
                </div>
                <button wire:click="closeSettingsDrawer" type="button" class="btn btn-ghost btn-circle btn-sm">
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="space-y-4">
                {{-- Max Retries --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_max_retry') }}</span>
                        <x-tooltip :tip="__('admin.security_max_retry_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="maxRetry" type="number" min="1" max="100" class="input input-bordered input-sm w-full" />
                    @error('maxRetry') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Find Time --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_find_time') }}</span>
                        <x-tooltip :tip="__('admin.security_find_time_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="findTime" type="number" min="10" max="86400" class="input input-bordered input-sm w-full" />
                    @error('findTime') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Ban Time --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_ban_time') }}</span>
                        <x-tooltip :tip="__('admin.security_ban_time_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="banTime" type="number" min="60" max="31536000" class="input input-bordered input-sm w-full" />
                    @error('banTime') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Attack Vectors --}}
                <div class="space-y-2 pt-2 border-t border-base-200">
                    <div class="text-xs font-semibold text-base-content/70 mb-2">Monitored Services</div>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectSip" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                        <span class="label-text">{{ __('admin.security_protect_sip') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectWeb" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                        <span class="label-text">{{ __('admin.security_protect_web') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectSsh" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                        <span class="label-text">{{ __('admin.security_protect_ssh') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-6 border-t border-base-200">
            <button wire:click="closeSettingsDrawer" type="button" class="btn btn-outline btn-sm">
                {{ __('client.cancel') }}
            </button>
            <button wire:click="saveSettings" type="button" class="btn btn-primary btn-sm">
                {{ __('client.save') }}
            </button>
        </div>
    </div>
</div>
