<div>
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.voicemail_messages_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.voicemail_message_caller') }}</th>
                        <th>{{ __('admin.voicemail_message_duration') }}</th>
                        <th>{{ __('admin.voicemail_message_date') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $message)
                        <tr @class(['opacity-60' => !$message->listened])>
                            <td>
                                <span class="font-medium">{{ $message->caller_id_name }}</span>
                                <span class="text-sm text-base-content/60 block">{{ $message->caller_id }}</span>
                            </td>
                            <td>{{ gmdate('i:s', $message->duration) }}</td>
                            <td>{{ $message->created_at->format('M j, Y g:i A') }}</td>
                            <td>
                                @if ($message->listened)
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.voicemail_message_listened') }}</span>
                                @else
                                    <span class="badge badge-info badge-sm">{{ __('admin.voicemail_message_new') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ $message->mediaAsset !== null ? route('panel.media-assets.stream', $message->mediaAsset) : asset('storage/' . $message->file_path) }}" target="_blank" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-play class="w-4 h-4" />
                                    </a>
                                    @if ($message->mediaAsset !== null)
                                        <a href="{{ route('panel.media-assets.download', $message->mediaAsset) }}" class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    @endif
                                    <button
                                        wire:click="confirmMessageDeletion('{{ $message->id }}')"
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
                                {{ __('admin.no_voicemail_messages_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $messages->links() }}
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_voicemail_message_title')"
            :message="__('admin.modal_delete_voicemail_message_message')"
            :confirm-label="__('admin.modal_delete_voicemail_message_confirm')"
            confirm-action="deleteMessage('{{ $pendingDeletionId }}')"
            cancel-action="cancelMessageDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_voicemail_message_error_title')"
        />
    @endif
</div>
