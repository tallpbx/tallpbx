<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.number_translations_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.number-translations.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_number_translation') }}
        </a>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.number_translation_name') }}</th>
                        <th>{{ __('admin.number_translation_match') }}</th>
                        <th>{{ __('admin.number_translation_replace') }}</th>
                        <th>{{ __('admin.number_translation_direction') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($translations as $translation)
                        <tr>
                            <td class="font-medium">{{ $translation->name }}</td>
                            <td class="font-mono text-sm">{{ $translation->match_pattern }}</td>
                            <td class="font-mono text-sm">{{ $translation->replace_pattern ?? '-' }}</td>
                            <td>{{ $translation->direction }}</td>
                            <td>
                                @if($translation->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.number-translations.edit', $translation->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$translation->name" wire:click="confirmTranslationDeletion('{{ $translation->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_number_translations_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($pendingDeletionId !== null)<x-confirmation-modal :open="true" :title="__('admin.modal_delete_number_translation_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_number_translation_confirm')" confirm-action="deleteTranslation('{{ $pendingDeletionId }}')" cancel-action="cancelTranslationDeletion" />@endif
</div>
