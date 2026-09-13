<div>
    <div class="mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.email_queue_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.email_queue_to') }}</th>
                        <th>{{ __('admin.email_queue_subject') }}</th>
                        <th>{{ __('admin.email_queue_status') }}</th>
                        <th>{{ __('admin.email_queue_sent_at') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td>{{ $item->to }}</td>
                            <td class="text-sm">{{ $item->subject }}</td>
                            <td>
                                @if($item->status === 'sent')
                                    <span class="badge badge-success badge-sm">{{ __('admin.email_queue_sent') }}</span>
                                @elseif($item->status === 'failed')
                                    <span class="badge badge-error badge-sm">{{ __('admin.email_queue_failed') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.email_queue_pending') }}</span>
                                @endif
                            </td>
                            <td class="text-sm">{{ $item->sent_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <button
                                    wire:click="confirmItemDeletion('{{ $item->id }}')"
                                    class="btn btn-ghost btn-xs text-error"
                                >
                                    <x-heroicon-o-trash class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_email_queue_items_found') }}
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
            :title="__('admin.modal_delete_email_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_email_confirm')"
            confirm-action="deleteItem()"
            cancel-action="cancelItemDeletion"
        />
    @endif
</div>
