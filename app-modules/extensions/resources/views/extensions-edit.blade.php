<div>
    <div class="flex items-center gap-2 mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Extension' : 'Create Extension' }}
        </h2>
        <x-tooltip :tip="__('admin.extension_header_tooltip')" align="start" position="right">
            <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
        </x-tooltip>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                @if($this->isAdminGuard())
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <select wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>
                @endif

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Extension Number</span>
                        <x-tooltip :tip="__('admin.extension_number_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="extensionNumber"
                           class="input input-bordered w-full @error('extensionNumber') input-error @enderror" />
                    @error('extensionNumber') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Display Name</span>
                        <x-tooltip :tip="__('admin.extension_description_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="displayName"
                           class="input input-bordered w-full @error('displayName') input-error @enderror" />
                    @error('displayName') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">SIP Password</span>
                        <x-tooltip :tip="__('admin.sip_password_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <div x-data="{ show: false }" class="relative w-full">
                        <input type="text"
                               wire:model="password"
                               autocomplete="off"
                               autocorrect="off"
                               autocapitalize="off"
                               spellcheck="false"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               name="sip_device_auth_secret"
                               :style="show ? '' : '-webkit-text-security: disc; text-security: disc;'"
                               placeholder="{{ $this->isEdit ? 'Leave blank to keep existing password' : '' }}"
                               class="input input-bordered w-full pr-10 font-mono @error('password') input-error @enderror" />
                        <button type="button"
                                @click="show = !show"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-base-content/50 hover:text-base-content"
                                tabindex="-1">
                            <x-heroicon-o-eye x-show="!show" class="w-5 h-5" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-5 h-5" />
                        </button>
                    </div>
                    @error('password') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Effective Caller ID Name</span>
                            <x-tooltip :tip="__('admin.extension_effective_caller_id_name_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="effectiveCallerIdName"
                               placeholder="e.g. John Doe"
                               class="input input-bordered w-full @error('effectiveCallerIdName') input-error @enderror" />
                        @error('effectiveCallerIdName') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Effective Caller ID Number</span>
                            <x-tooltip :tip="__('admin.extension_effective_caller_id_number_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="effectiveCallerIdNumber"
                               placeholder="e.g. 1001"
                               class="input input-bordered w-full @error('effectiveCallerIdNumber') input-error @enderror" />
                        @error('effectiveCallerIdNumber') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Outbound Caller ID Name</span>
                            <x-tooltip :tip="__('admin.extension_outbound_caller_id_name_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="outboundCallerIdName"
                               class="input input-bordered w-full @error('outboundCallerIdName') input-error @enderror" />
                        @error('outboundCallerIdName') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Outbound Caller ID Number</span>
                            <x-tooltip :tip="__('admin.extension_outbound_caller_id_number_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="outboundCallerIdNumber"
                               class="input input-bordered w-full @error('outboundCallerIdNumber') input-error @enderror" />
                        @error('outboundCallerIdNumber') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="pt-2 space-y-4">
                    <div class="flex flex-col sm:flex-row gap-6">
                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" wire:model.live="voicemailEnabled" class="checkbox checkbox-info" />
                                <span class="label-text font-medium">Voicemail Enabled</span>
                                <x-tooltip :tip="__('admin.voicemail_enabled_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                                </x-tooltip>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                                <span class="label-text font-medium">Enabled</span>
                                <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                                </x-tooltip>
                            </label>
                        </div>
                    </div>

                    @if($voicemailEnabled)
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.voicemail_password') }}</span>
                            <x-tooltip :tip="__('admin.voicemail_password_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <div x-data="{ show: false }" class="relative w-full">
                            <input type="text"
                                   wire:model="voicemailPassword"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   autocorrect="off"
                                   autocapitalize="off"
                                   spellcheck="false"
                                   data-lpignore="true"
                                   data-1p-ignore="true"
                                   data-bwignore="true"
                                   data-form-type="other"
                                   name="voicemail_auth_secret"
                                   :style="show ? '' : '-webkit-text-security: disc; text-security: disc;'"
                                   placeholder="{{ $this->isEdit ? ($voicemailPassword ? 'Keep existing password' : $this->extensionNumber) : ($this->extensionNumber ?: 'e.g. 1001') }}"
                                   class="input input-bordered w-full pr-10 font-mono @error('voicemailPassword') input-error @enderror" />
                            <button type="button"
                                    @click="show = !show"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-base-content/50 hover:text-base-content"
                                    tabindex="-1">
                                <x-heroicon-o-eye x-show="!show" class="w-5 h-5" />
                                <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-5 h-5" />
                            </button>
                        </div>
                        @error('voicemailPassword') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                    @endif
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.extensions.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
