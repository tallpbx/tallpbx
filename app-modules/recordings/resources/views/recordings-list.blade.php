<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.recordings_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.recordings.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_recording') }}
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.recording_name') }}</th>
                        <th>{{ __('admin.recording_type') }}</th>
                        <th>{{ __('admin.recording_duration') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recordings as $recording)
                        <tr>
                            <td class="font-medium">{{ $recording->name }}</td>
                            <td><span class="badge badge-ghost">{{ $recording->type }}</span></td>
                            <td>{{ gmdate('i:s', $recording->duration) }}</td>
                            <td>
                                @if ($recording->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    {{-- Media links keep wire:navigate off: playback must hit the
                                         asset URL directly, so it receives its label in place. --}}
                                    <a href="{{ asset('storage/' . $recording->file_path) }}" target="_blank" class="btn btn-ghost btn-xs" aria-label="{{ __('client.play') }}">
                                        <x-heroicon-o-play class="w-4 h-4" />
                                    </a>
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.recordings.edit', $recording->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$recording->name" wire:click="confirmRecordingDeletion('{{ $recording->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_recordings_found') }}
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
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_recording_confirm')"
            confirm-action="deleteRecording()"
            cancel-action="cancelRecordingDeletion"
        />
    @endif
</div>
