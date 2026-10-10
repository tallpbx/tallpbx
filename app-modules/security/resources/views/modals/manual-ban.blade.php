{{-- Modal: Manual Ban --}}
@if ($showManualBanModal)
    <div class="modal modal-open">
        <div class="modal-box">
            <h3 class="font-bold text-lg text-base-content">{{ __('admin.security_manual_ban_title') }}</h3>
            <form wire:submit="manualBan" class="space-y-4 mt-4">
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_attacker_ip') }}</span></label>
                    <input wire:model="manualBanIp" type="text" placeholder="e.g. 198.51.100.42 or 2001:db8::1"
                           class="input input-bordered input-sm font-mono @error('manualBanIp') input-error @enderror" />
                    @error('manualBanIp') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_ban_duration') }}</span></label>
                    <select wire:model="manualBanDuration" class="select select-bordered select-sm">
                        <option value="3600">{{ __('admin.security_ban_1_hour') }}</option>
                        <option value="86400">{{ __('admin.security_ban_24_hours') }}</option>
                        <option value="604800">{{ __('admin.security_ban_7_days') }}</option>
                        <option value="2592000">{{ __('admin.security_ban_30_days') }}</option>
                        <option value="-1">{{ __('admin.security_ban_permanent') }}</option>
                    </select>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">{{ __('admin.security_ban_reason') }}</span></label>
                    <input wire:model="manualBanReason" type="text" placeholder="e.g. Suspicious brute force probe"
                           class="input input-bordered input-sm" />
                </div>

                <div class="modal-action">
                    <button wire:click="$set('showManualBanModal', false)" type="button" class="btn btn-outline btn-sm">
                        {{ __('client.cancel') }}
                    </button>
                    <button type="submit" class="btn btn-error btn-sm">
                        {{ __('admin.security_confirm_ban') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
