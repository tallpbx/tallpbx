{{-- Modal: Attack Protection Settings --}}
@if ($showSettingsDrawer)
    <div class="modal modal-open" role="dialog" aria-modal="true" aria-labelledby="protection-settings-title">
        <div class="modal-box max-w-lg">
            <div class="flex items-center justify-between pb-3 border-b border-base-200">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cog-6-tooth class="w-5 h-5 text-primary" />
                    <h3 id="protection-settings-title" class="font-bold text-lg text-base-content">{{ __('admin.security_protection_settings') }}</h3>
                </div>
                <button wire:click="closeSettingsDrawer" type="button" class="btn btn-ghost btn-circle btn-sm" aria-label="{{ __('client.close') }}">
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <form wire:submit="saveSettings" class="space-y-4 mt-4">
                {{-- Max Retries --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_max_retry') }}</span>
                        <x-tooltip :tip="__('admin.security_max_retry_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="maxRetry" type="number" min="1" max="100"
                           class="input input-bordered input-sm w-full {{ $errors->has('maxRetry') ? 'input-error' : '' }}" />
                    <x-form-error name="maxRetry" />
                </div>

                {{-- Find Time --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_find_time') }}</span>
                        <x-tooltip :tip="__('admin.security_find_time_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="findTime" type="number" min="10" max="86400"
                           class="input input-bordered input-sm w-full {{ $errors->has('findTime') ? 'input-error' : '' }}" />
                    <x-form-error name="findTime" />
                </div>

                {{-- Ban Time --}}
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_ban_time') }}</span>
                        <x-tooltip :tip="__('admin.security_ban_time_help')" align="start" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input wire:model="banTime" type="number" min="60" max="31536000"
                           class="input input-bordered input-sm w-full {{ $errors->has('banTime') ? 'input-error' : '' }}" />
                    <x-form-error name="banTime" />
                </div>

                {{-- Attack Vectors / Monitored Services --}}
                <div class="space-y-2 pt-2 border-t border-base-200">
                    <div class="text-xs font-semibold text-base-content/70 mb-2">{{ __('admin.security_monitored_services') }}</div>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectSip" wire:loading.attr="disabled" type="checkbox"
                               class="toggle toggle-sm toggle-success" aria-label="{{ __('admin.security_protect_sip') }}" />
                        <span class="label-text">{{ __('admin.security_protect_sip') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectWeb" wire:loading.attr="disabled" type="checkbox"
                               class="toggle toggle-sm toggle-success" aria-label="{{ __('admin.security_protect_web') }}" />
                        <span class="label-text">{{ __('admin.security_protect_web') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input wire:model="protectSsh" wire:loading.attr="disabled" type="checkbox"
                               class="toggle toggle-sm toggle-success" aria-label="{{ __('admin.security_protect_ssh') }}" />
                        <span class="label-text">{{ __('admin.security_protect_ssh') }}</span>
                    </label>
                </div>

                <div class="modal-action">
                    <button wire:click="closeSettingsDrawer"
                            wire:loading.attr="disabled"
                            wire:target="saveSettings"
                            type="button" class="btn btn-outline btn-sm">
                        {{ __('client.cancel') }}
                    </button>
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveSettings"
                            class="btn btn-primary btn-sm">
                        <span wire:loading.remove wire:target="saveSettings" class="inline-flex items-center gap-1.5">
                            <span>{{ __('client.save') }}</span>
                        </span>
                        <span wire:loading wire:target="saveSettings" class="inline-flex items-center gap-1.5">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.saving') }}</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
        <div class="modal-backdrop" wire:click="closeSettingsDrawer"></div>
    </div>
@endif

