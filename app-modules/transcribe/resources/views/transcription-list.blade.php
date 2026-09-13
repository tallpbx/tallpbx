<div>
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.transcribe_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.transcribe_text') }}</th>
                        <th>{{ __('admin.transcribe_confidence') }}</th>
                        <th>{{ __('admin.transcribe_language') }}</th>
                        <th>{{ __('admin.transcribe_date') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transcriptions as $trans)
                        <tr>
                            <td class="text-sm max-w-md truncate">{{ $trans->text }}</td>
                            <td>{{ $trans->confidence ? ($trans->confidence * 100) . '%' : '-' }}</td>
                            <td>{{ $trans->language ?? '-' }}</td>
                            <td class="text-sm">{{ $trans->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                <button
                                    wire:click="confirmTranscriptionDeletion('{{ $trans->id }}')"
                                    class="btn btn-ghost btn-xs text-error"
                                >
                                    <x-heroicon-o-trash class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_transcriptions_found') }}
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
            :title="__('admin.modal_delete_transcription_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_transcription_confirm')"
            confirm-action="deleteTranscription()"
            cancel-action="cancelTranscriptionDeletion"
        />
    @endif
</div>
