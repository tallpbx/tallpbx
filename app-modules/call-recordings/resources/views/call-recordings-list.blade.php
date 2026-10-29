<div>
    <x-page-header :title="__('admin.call_recordings_title')" :tooltip="__('admin.page_info_tooltip')" />

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
                                <span class="text-xs text-base-content/60 block">{{ $recording->caller_id }}</span>
                            </td>
                            <td><span class="font-mono text-sm">{{ $recording->destination }}</span></td>
                            <td>{{ gmdate('i:s', $recording->duration) }}</td>
                            <td>{{ $recording->created_at->format('M j, Y g:i A') }}</td>
                            <td>
                                <div class="flex gap-2">
                                    {{-- Media links keep wire:navigate off: playback/download must
                                         hit the asset URL directly, so they receive labels in place. --}}
                                    <a href="{{ $recording->mediaAsset !== null ? route('panel.media-assets.stream', $recording->mediaAsset) : asset('storage/' . $recording->file_path) }}" target="_blank" class="btn btn-ghost btn-xs" aria-label="{{ __('client.play') }}">
                                        <x-heroicon-o-play class="w-4 h-4" />
                                    </a>
                                    @if ($recording->mediaAsset !== null)
                                        <a href="{{ route('panel.media-assets.download', $recording->mediaAsset) }}" class="btn btn-ghost btn-xs" aria-label="{{ __('client.download') }}">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    @endif
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$recording->caller_id_name" wire:click="confirmRecordingDeletion('{{ $recording->id }}')" class="text-error" />
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

    <div class="mt-4">
        {{ $recordings->links() }}
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
