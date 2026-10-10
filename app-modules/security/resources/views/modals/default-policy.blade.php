{{-- Modal: Default Inbound Policy --}}
@if ($showDefaultPolicyModal)
    <div class="modal modal-open">
        <div class="modal-box max-w-sm">
            <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_default_policy') }}</h3>
            <form wire:submit="saveDefaultPolicy" class="space-y-4 mt-4">
                <div class="form-control">
                    <label class="label justify-start gap-2">
                        <span class="label-text font-medium">{{ __('admin.security_firewall_status') }} (Default Policy)</span>
                    </label>
                    <select wire:model="firewallDefaultPolicy" class="select select-bordered select-sm w-full">
                        <option value="drop">{{ __('admin.security_policy_drop') }} (Drop)</option>
                        <option value="accept">{{ __('admin.security_policy_accept') }} (Accept)</option>
                    </select>
                    @error('firewallDefaultPolicy') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="modal-action">
                    <button wire:click="closeDefaultPolicyForm"
                            wire:loading.attr="disabled"
                            wire:target="saveDefaultPolicy"
                            type="button" class="btn btn-outline btn-sm">
                        {{ __('client.cancel') }}
                    </button>
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveDefaultPolicy"
                            class="btn btn-primary btn-sm">
                        <span wire:loading.remove wire:target="saveDefaultPolicy" class="inline-flex items-center gap-1.5">
                            <span>{{ __('client.save') }}</span>
                        </span>
                        <span wire:loading wire:target="saveDefaultPolicy" class="inline-flex items-center gap-1.5">
                            <span class="loading loading-spinner loading-xs"></span>
                            <span>{{ __('admin.saving') }}</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
