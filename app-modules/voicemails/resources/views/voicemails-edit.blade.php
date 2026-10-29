<div>
    <x-page-header :title="$this->isEdit ? __('admin.edit_voicemail') : __('admin.create_voicemail')" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">{{ __('admin.tenant') }}</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full {{ $errors->has('tenantId') ? 'select-error' : '' }}">
                        <option value="">{{ __('admin.select_tenant') }}</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    <x-form-error name="tenantId" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="voicemailId">
                            <span class="label-text font-medium">{{ __('admin.voicemail_id') }}</span>
                        </label>
                        <input type="text" id="voicemailId" wire:model="voicemailId"
                               class="input input-bordered w-full {{ $errors->has('voicemailId') ? 'input-error' : '' }}"
                               placeholder="e.g. 1000" />
                        <x-form-error name="voicemailId" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="mailbox">
                            <span class="label-text font-medium">{{ __('admin.voicemail_mailbox') }}</span>
                        </label>
                        <input type="text" id="mailbox" wire:model="mailbox"
                               class="input input-bordered w-full {{ $errors->has('mailbox') ? 'input-error' : '' }}"
                               placeholder="Same as ID if blank" />
                        <x-form-error name="mailbox" />
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">{{ __('admin.name') }}</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full {{ $errors->has('name') ? 'input-error' : '' }}"
                           placeholder="e.g. John's Voicemail" />
                    <x-form-error name="name" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="password">
                            <span class="label-text font-medium">{{ __('admin.voicemail_pin_password') }}</span>
                        </label>
                        <input type="text" id="password" wire:model="password"
                               autocomplete="off"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               class="input input-bordered w-full {{ $errors->has('password') ? 'input-error' : '' }}"
                               placeholder="Leave blank for no PIN" />
                        <x-form-error name="password" />
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="email">
                            <span class="label-text font-medium">{{ __('admin.voicemail_email_notification') }}</span>
                        </label>
                        <input type="email" id="email" wire:model="email"
                               class="input input-bordered w-full {{ $errors->has('email') ? 'input-error' : '' }}"
                               placeholder="user@example.com" />
                        <x-form-error name="email" />
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="greetingUpload">
                        <span class="label-text font-medium">{{ __('admin.voicemail_greeting_audio') }}</span>
                    </label>
                    <input wire:model="greetingUpload" type="file" id="greetingUpload" accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg" class="file-input file-input-bordered w-full" />
                    @if ($this->isEdit && $greetingMessage)
                        <span class="label-text-alt mt-1">{{ __('admin.leave_blank_keep_existing_greeting') }}</span>
                    @endif
                    <x-form-error name="greetingUpload" />
                </div>

                <div class="space-y-2 pt-2">
                    <label class="label pb-1"><span class="label-text font-medium">{{ __('admin.voicemail_options') }}</span></label>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="requirePassword" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                            <span class="label-text">{{ __('admin.voicemail_require_pin') }}</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="forwardToEmail" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                            <span class="label-text">{{ __('admin.voicemail_forward_email') }}</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="deleteAfterEmail" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                            <span class="label-text">{{ __('admin.voicemail_delete_after_email') }}</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="enabled" wire:loading.attr="disabled" class="toggle toggle-sm toggle-success" />
                            <span class="label-text font-medium">{{ __('admin.enabled') }}</span>
                            <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                    </div>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <x-form-submit-button>
                    {{ $this->isEdit ? __('client.update') : __('client.create') }}
                </x-form-submit-button>
                    <a href="{{ route('panel.voicemails.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('admin.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
