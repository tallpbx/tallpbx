<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.extension_settings_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.extension-settings.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_extension_setting') }}
        </a>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.extension_setting_key') }}</th>
                        <th>{{ __('admin.extension_setting_value') }}</th>
                        <th>{{ __('admin.extension_setting_extension') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settings as $setting)
                        <tr>
                            <td class="font-mono text-sm">{{ $setting->key }}</td>
                            <td>{{ $setting->value }}</td>
                            <td>{{ $setting->extension?->extension_number ?? '-' }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.extension-settings.edit', $setting->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmSettingDeletion('{{ $setting->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-8 text-base-content/40">{{ __('admin.no_extension_settings_found') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($pendingDeletionId !== null)<x-confirmation-modal :open="true" :title="__('admin.modal_delete_extension_setting_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_extension_setting_confirm')" confirm-action="deleteSetting('{{ $pendingDeletionId }}')" cancel-action="cancelSettingDeletion" />@endif
</div>
