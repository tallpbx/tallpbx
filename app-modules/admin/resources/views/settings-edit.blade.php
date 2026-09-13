<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">{{ __('admin.settings_title') }}</h2>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    {{-- New Setting Form --}}
    <div class="card bg-base-100 border border-base-300 mb-6">
        <div class="card-body">
            <h3 class="card-title text-lg mb-4">Add Setting</h3>
            <div class="flex flex-wrap gap-3 items-end">
                <div class="form-control flex-1 min-w-48">
                    <label for="newKey" class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Key</span></label>
                    <input type="text" id="newKey" wire:model="newKey"
                           class="input input-bordered w-full" placeholder="app.name" />
                    @error('newKey') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-control flex-1 min-w-48">
                    <label for="newValue" class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Value</span></label>
                    <input type="text" id="newValue" wire:model="newValue"
                           class="input input-bordered w-full" placeholder="MyPBX" />
                    @error('newValue') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-control w-32">
                    <label for="newType" class="label justify-start gap-2 pb-1"><span class="label-text font-medium">Type</span></label>
                    <select id="newType" wire:model="newType" class="select select-bordered w-full">
                        <option value="string">string</option>
                        <option value="integer">integer</option>
                        <option value="boolean">boolean</option>
                        <option value="json">json</option>
                    </select>
                </div>
                <button wire:click="createSetting" class="btn btn-primary">
                    <x-heroicon-o-plus class="w-4 h-4" /> Add
                </button>
            </div>
        </div>
    </div>

    {{-- Settings Table --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Value</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($settings as $setting)
                        @if($editingId === $setting->id)
                            <tr class="bg-base-200">
                                <td class="font-mono text-sm">{{ $setting->key }}</td>
                                <td>
                                    <input type="text" wire:model="editValue"
                                           class="input input-bordered input-sm w-full" />
                                    @error('editValue') <span class="label-text-alt text-error">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <select wire:model="editType" class="select select-bordered select-sm w-28">
                                        <option value="string">string</option>
                                        <option value="integer">integer</option>
                                        <option value="boolean">boolean</option>
                                        <option value="json">json</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <button wire:click="saveSetting" class="btn btn-primary btn-xs">
                                            <x-heroicon-o-check class="w-3 h-3" />
                                        </button>
                                        <button wire:click="cancelEdit" class="btn btn-ghost btn-xs">{{ __('client.cancel') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @else
                            <tr>
                                <td class="font-mono text-sm">{{ $setting->key }}</td>
                                <td class="text-base-content/80 max-w-xs truncate">{{ $setting->value }}</td>
                                <td><span class="badge badge-ghost">{{ $setting->type }}</span></td>
                                <td>
                                    <div class="flex gap-2">
                                        <button wire:click="editSetting({{ $setting->id }})"
                                                class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-pencil class="w-4 h-4" />
                                        </button>
                                        <button wire:click="confirmSettingDeletion({{ $setting->id }})"
                                                class="btn btn-ghost btn-xs text-error">
                                            <x-heroicon-o-trash class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-8 text-base-content/40">
                                No settings configured.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_setting_title')"
            :message="__('admin.modal_delete_setting_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_setting_confirm')"
            confirm-action="deleteSetting()"
            cancel-action="cancelSettingDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_setting_error_title')"
        />
    @endif
</div>
