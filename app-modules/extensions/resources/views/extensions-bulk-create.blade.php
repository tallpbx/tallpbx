<div>
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <h2 class="text-2xl font-semibold">{{ __('admin.create_multiple_extensions') }}</h2>
            <x-tooltip :tip="__('admin.extension_header_tooltip')" align="start" position="right">
                <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
            </x-tooltip>
        </div>
        <p class="text-sm text-base-content/60 mt-1">
            Create a numeric range of extensions for the selected tenant.
        </p>
    </div>

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
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

                <div class="grid gap-4 grid-cols-1 md:grid-cols-3">
                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">Start Extension</span>
                            <x-tooltip :tip="__('admin.extension_number_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="number" min="1" wire:model.live="startExtension"
                               class="input input-bordered w-full @error('startExtension') input-error @enderror" />
                        @error('startExtension') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1">
                            <span class="label-text font-medium">End Extension</span>
                            <x-tooltip :tip="__('admin.extension_number_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                        <input type="number" min="1" wire:model.live="endExtension"
                               class="input input-bordered w-full @error('endExtension') input-error @enderror" />
                        @error('endExtension') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control w-full">
                        <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Increment</span></label>
                        <input type="number" min="1" wire:model.live="increment"
                               class="input input-bordered w-full @error('increment') input-error @enderror" />
                        @error('increment') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1">
                        <span class="label-text font-medium">Display Name Template</span>
                        <x-tooltip :tip="__('admin.extension_description_tooltip')" position="right">
                            <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                        </x-tooltip>
                    </label>
                    <input type="text" wire:model="displayNameTemplate"
                           class="input input-bordered w-full @error('displayNameTemplate') input-error @enderror" />
                    <span class="label-text-alt text-base-content/50 mt-1">Use {number} where the extension number should appear.</span>
                    @error('displayNameTemplate') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="pt-2 space-y-3">
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="voicemailEnabled" class="checkbox checkbox-info" />
                            <span class="label-text font-medium">Voicemail Enabled</span>
                            <x-tooltip :tip="__('admin.voicemail_enabled_tooltip')" position="right">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
                            </x-tooltip>
                        </label>
                    </div>

                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="copyNumberAlias" class="checkbox checkbox-secondary" />
                            <span class="label-text font-medium">Set each number alias to the extension number</span>
                            <x-tooltip :tip="__('admin.extension_number_tooltip')" position="right">
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

                <div class="form-control w-full">
                    <label class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Description</span></label>
                    <textarea wire:model="description"
                              class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"></textarea>
                    @error('description') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                </div>

                @if($this->previewNumbers !== [])
                    <div class="rounded-box border border-base-300 bg-base-200/50 p-4 text-sm">
                        <div class="font-medium">Preview</div>
                        <div class="mt-1 text-base-content/70">
                            First numbers: {{ implode(', ', $this->previewNumbers) }}{{ count($this->previewNumbers) === 10 ? ', …' : '' }}
                        </div>
                    </div>
                @endif

                <div class="flex gap-2 pt-4 border-t border-base-300">
                    <button type="submit" class="btn btn-primary">
                        {{ __('admin.create_multiple_extensions') }}
                    </button>
                    <a href="{{ route('panel.extensions.index') }}" class="btn btn-ghost">{{ __('client.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
