<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.call_broadcasts_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.call-broadcast.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_call_broadcast') }}
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
                        <th>{{ __('admin.call_broadcast_name') }}</th>
                        <th>{{ __('admin.call_broadcast_recipients') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('admin.call_broadcast_date') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($broadcasts as $broadcast)
                        <tr>
                            <td class="font-medium">{{ $broadcast->name }}</td>
                            <td>
                                {{ $broadcast->recipients_count }}
                                @if ($broadcast->status !== 'draft')
                                    <span class="text-xs opacity-70">
                                        · {{ $broadcast->answered_count }} {{ __('admin.call_broadcast_answered') }}
                                        · {{ $broadcast->failed_count }} {{ __('admin.call_broadcast_failed') }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if ($broadcast->status === 'completed')
                                    <span class="badge badge-success badge-sm">{{ __('admin.call_broadcast_completed') }}</span>
                                @elseif ($broadcast->status === 'sending')
                                    <span class="badge badge-info badge-sm">{{ __('admin.call_broadcast_sending') }}</span>
                                @elseif ($broadcast->status === 'failed')
                                    <span class="badge badge-error badge-sm">{{ __('admin.call_broadcast_failed') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.call_broadcast_draft') }}</span>
                                @endif
                            </td>
                            <td>{{ $broadcast->created_at->format('M j, Y') }}</td>
                            <td>
                                <div class="flex gap-2">
                                    @if ($broadcast->status === 'draft')
                                        <button
                                            wire:click="confirmSend('{{ $broadcast->id }}')"
                                            class="btn btn-primary btn-xs"
                                        >{{ __('admin.send_call_broadcast') }}</button>
                                    @endif
                                    <button
                                        wire:click="confirmBroadcastDeletion('{{ $broadcast->id }}')"
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
                                {{ __('admin.no_call_broadcasts_found') }}
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
            :title="__('admin.modal_delete_call_broadcast_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_call_broadcast_confirm')"
            confirm-action="deleteBroadcast()"
            cancel-action="cancelBroadcastDeletion"
        />
    @endif

    @if ($sendingId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_send_broadcast_title')"
            :message="__('admin.modal_send_broadcast_message', ['name' => $sendingName])"
            :confirm-label="__('admin.modal_send_broadcast_confirm')"
            confirm-action="sendBroadcast()"
            cancel-action="cancelSend"
        />
    @endif
</div>
