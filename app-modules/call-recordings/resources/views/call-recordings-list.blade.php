<div>
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.call_recordings_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.call_recording_caller') }}</th>
                        <th>{{ __('admin.call_recording_destination') }}</th>
                        <th>{{ __('admin.call_recording_duration') }}</th>
                        <th>{{ __('admin.call_recording_date') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recordings as $recording)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $recording->caller_id_name }}</span>
                                <span class="text-sm text-base-content/60 block">{{ $recording->caller_id }}</span>
                            </td>
                            <td><span class="font-mono text-sm">{{ $recording->destination }}</span></td>
                            <td>{{ gmdate('i:s', $recording->duration) }}</td>
                            <td>{{ $recording->created_at->format('M j, Y g:i A') }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ $recording->mediaAsset !== null ? route('panel.media-assets.stream', $recording->mediaAsset) : asset('storage/' . $recording->file_path) }}" target="_blank" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-play class="w-4 h-4" />
                                    </a>
                                    @if ($recording->mediaAsset !== null)
                                        <a href="{{ route('panel.media-assets.download', $recording->mediaAsset) }}" class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    @endif
                                    <button
                                        wire:click="confirmRecordingDeletion('{{ $recording->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_call_recordings_found') }}
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
            :title="__('admin.modal_delete_recording_title')"
            :message="__('admin.modal_delete_call_recording_message')"
            :confirm-label="__('admin.modal_delete_recording_confirm')"
            confirm-action="deleteRecording('{{ $pendingDeletionId }}')"
            cancel-action="cancelRecordingDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_recording_error_title')"
        />
    @endif
</div>
