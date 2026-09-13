<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            {{ $this->isEdit ? 'Edit Voicemail' : 'Create Voicemail' }}
        </h2>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="tenantId">
                        <span class="label-text font-medium">Tenant</span>
                        <x-tooltip :tip="__('admin.tenant_select_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <select id="tenantId" wire:model="tenantId" class="select select-bordered w-full @error('tenantId') select-error @enderror">
                        <option value="">Select tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenantId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="voicemailId">
                            <span class="label-text font-medium">Voicemail ID</span>
                        </label>
                        <input type="text" id="voicemailId" wire:model="voicemailId"
                               class="input input-bordered w-full @error('voicemailId') input-error @enderror"
                               placeholder="e.g. 1000" />
                        @error('voicemailId') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="mailbox">
                            <span class="label-text font-medium">Mailbox</span>
                        </label>
                        <input type="text" id="mailbox" wire:model="mailbox"
                               class="input input-bordered w-full @error('mailbox') input-error @enderror"
                               placeholder="Same as ID if blank" />
                        @error('mailbox') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="name">
                        <span class="label-text font-medium">Name</span>
                        <x-tooltip :tip="__('admin.name_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                        </x-tooltip>
                    </label>
                    <input type="text" id="name" wire:model="name"
                           class="input input-bordered w-full @error('name') input-error @enderror"
                           placeholder="e.g. John's Voicemail" />
                    @error('name') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="password">
                            <span class="label-text font-medium">PIN / Password</span>
                        </label>
                        <input type="text" id="password" wire:model="password"
                               autocomplete="off"
                               data-lpignore="true"
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other"
                               class="input input-bordered w-full @error('password') input-error @enderror"
                               placeholder="Leave blank for no PIN" />
                        @error('password') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1" for="email">
                            <span class="label-text font-medium">Email Notification</span>
                        </label>
                        <input type="email" id="email" wire:model="email"
                               class="input input-bordered w-full @error('email') input-error @enderror"
                               placeholder="user@example.com" />
                        @error('email') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1" for="greetingUpload">
                        <span class="label-text font-medium">Greeting Audio</span>
                    </label>
                    <input wire:model="greetingUpload" type="file" id="greetingUpload" accept="audio/wav,audio/mpeg,audio/ogg,.wav,.mp3,.ogg" class="file-input file-input-bordered w-full" />
                    @if ($this->isEdit && $greetingMessage)
                        <span class="label-text-alt mt-1">Leave blank to keep the existing greeting.</span>
                    @endif
                    @error('greetingUpload') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-2 pt-2">
                    <label class="label pb-1"><span class="label-text font-medium">Options</span></label>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="requirePassword" class="checkbox checkbox-info" />
                            <span class="label-text">Require PIN to access voicemail</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="forwardToEmail" class="checkbox checkbox-info" />
                            <span class="label-text">Forward messages to email</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="deleteAfterEmail" class="checkbox checkbox-info" />
                            <span class="label-text">Delete message from server after emailing</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
                            <span class="label-text font-medium">Enabled</span>
                            <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-base-content/60 cursor-help" />
                            </x-tooltip>
                        </label>
                    </div>
                </div>

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->isEdit ? 'Update' : 'Create' }}
                    </button>
                    <a href="{{ route('panel.voicemails.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
