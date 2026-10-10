{{-- Modal: Confirm Disable Pre-Filters --}}
@if ($showDisablePrefilterModal)
    <div class="modal modal-open" role="dialog">
        <div class="modal-box max-w-md">
            <div class="flex items-start gap-3">
                <div class="p-2 rounded-full bg-warning/10 text-warning shrink-0">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6" />
                </div>
                <div>
                    <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_disable_prefilter_modal_title') }}</h3>
                    <p class="text-sm text-base-content/80 mt-2">
                        {{ __('admin.security_disable_prefilter_modal_desc') }}
                    </p>
                    <div class="mt-3 bg-base-200/50 rounded-box p-3 border border-base-200 text-xs space-y-1.5">
                        <div class="font-semibold text-base-content/90">{{ __('admin.security_disable_prefilter_effects_title') }}:</div>
                        <ul class="list-disc list-inside text-base-content/70 space-y-1">
                            <li>{{ __('admin.security_disable_prefilter_effect_whitelist') }}</li>
                            <li>{{ __('admin.security_disable_prefilter_effect_blacklist') }}</li>
                            <li>{{ __('admin.security_disable_prefilter_effect_attackers') }}</li>
                            <li>{{ __('admin.security_disable_prefilter_effect_threat_feeds') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-action">
                <button wire:click="closeDisablePrefilterModal"
                        wire:loading.attr="disabled"
                        wire:target="executeDisablePrefilter"
                        type="button" class="btn btn-outline btn-sm">
                    {{ __('client.cancel') }}
                </button>
                <button wire:click="executeDisablePrefilter"
                        wire:loading.attr="disabled"
                        wire:target="executeDisablePrefilter"
                        type="button" class="btn btn-warning btn-sm">
                    <span wire:loading.remove wire:target="executeDisablePrefilter" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-no-symbol class="w-4 h-4" />
                        <span>{{ __('admin.security_disable_prefilter_btn') }}</span>
                    </span>
                    <span wire:loading wire:target="executeDisablePrefilter" class="inline-flex items-center gap-1.5">
                        <span class="loading loading-spinner loading-xs"></span>
                        <span>{{ __('admin.saving') }}</span>
                    </span>
                </button>
            </div>
        </div>
        <div class="modal-backdrop" wire:click="closeDisablePrefilterModal"></div>
    </div>
@endif
