<div>
    <x-page-header :title="$this->isEdit ? 'Edit Extension' : 'Create Extension'" :tooltip="__('admin.extension_header_tooltip')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                @if($this->isAdminGuard())
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <select wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>
                @endif

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.extension_number') }}</span>
                        <x-tooltip :tip="__('admin.extension_number_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="extensionNumber"
                           class="input input-bordered w-full {{ $errors->has('extensionNumber') ? 'input-error' : '' }}" />
                    <x-form-error name="extensionNumber" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.extension_display_name') }}</span>
                        <x-tooltip :tip="__('admin.extension_description_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="displayName"
                           class="input input-bordered w-full {{ $errors->has('displayName') ? 'input-error' : '' }}" />
                    <x-form-error name="displayName" />
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">{{ __('admin.extension_sip_password') }}</span>
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
                               class="input input-bordered w-full pr-10 font-mono {{ $errors->has('password') ? 'input-error' : '' }}" />
                        <button type="button"
                                @click="show = !show"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-base-content/50 hover:text-base-content"
                                aria-label="{{ __('admin.password_visibility') }}"
                                :aria-pressed="show"
                                tabindex="-1">
                            <x-heroicon-o-eye x-show="!show" class="w-5 h-5" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-5 h-5" />
                        </button>
                    </div>
                    <x-form-error name="password" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.extension_effective_caller_id_name') }}</span>
                            <x-tooltip :tip="__('admin.extension_effective_caller_id_name_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="effectiveCallerIdName"
                               placeholder="e.g. John Doe"
                               class="input input-bordered w-full {{ $errors->has('effectiveCallerIdName') ? 'input-error' : '' }}" />
                        <x-form-error name="effectiveCallerIdName" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.extension_effective_caller_id_number') }}</span>
                            <x-tooltip :tip="__('admin.extension_effective_caller_id_number_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="effectiveCallerIdNumber"
                               placeholder="e.g. 1001"
                               class="input input-bordered w-full {{ $errors->has('effectiveCallerIdNumber') ? 'input-error' : '' }}" />
                        <x-form-error name="effectiveCallerIdNumber" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.extension_outbound_caller_id_name') }}</span>
                            <x-tooltip :tip="__('admin.extension_outbound_caller_id_name_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="outboundCallerIdName"
                               class="input input-bordered w-full {{ $errors->has('outboundCallerIdName') ? 'input-error' : '' }}" />
                        <x-form-error name="outboundCallerIdName" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">{{ __('admin.extension_outbound_caller_id_number') }}</span>
                            <x-tooltip :tip="__('admin.extension_outbound_caller_id_number_tooltip')" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="text" wire:model="outboundCallerIdNumber"
                               class="input input-bordered w-full {{ $errors->has('outboundCallerIdNumber') ? 'input-error' : '' }}" />
                        <x-form-error name="outboundCallerIdNumber" />
                    </div>
                </div>

                <div class="pt-2 space-y-4">
                    <div class="flex flex-col sm:flex-row gap-6">
                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" wire:model.live="voicemailEnabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                                <span class="label-text font-medium">{{ __('admin.extension_voicemail_enabled') }}</span>
                                <x-tooltip :tip="__('admin.voicemail_enabled_tooltip')" position="right">
                                    <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                                </x-tooltip>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                                <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
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
                                   class="input input-bordered w-full pr-10 font-mono {{ $errors->has('voicemailPassword') ? 'input-error' : '' }}" />
                            <button type="button"
                                    @click="show = !show"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-base-content/50 hover:text-base-content"
                                    aria-label="{{ __('admin.password_visibility') }}"
                                    :aria-pressed="show"
                                    tabindex="-1">
                                <x-heroicon-o-eye x-show="!show" class="w-5 h-5" />
                                <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-5 h-5" />
                            </button>
                        </div>
                        <x-form-error name="voicemailPassword" />
                    </div>
                    @endif
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? 'Update' : 'Create' }}
                </x-form-submit-button>
                    <a href="{{ route('panel.extensions.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
