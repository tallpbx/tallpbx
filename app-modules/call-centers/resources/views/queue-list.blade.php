<div>
    <x-page-header :title="__('admin.call_center_queues_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.call-centers.queues.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_queue') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.call_center_queue_name') }}</th>
                        <th>{{ __('admin.call_center_queue_strategy') }}</th>
                        <th>{{ __('admin.call_center_queue_timeout') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($queues as $queue)
                        <tr wire:key="{{ $queue->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmQueueDeletion('{{ $queue->id }}')">
                            <td class="font-medium">{{ $queue->name }}</td>
                            <td><span class="badge badge-ghost">{{ $queue->strategy }}</span></td>
                            <td>{{ $queue->timeout }}s</td>
                            <td>
                                @if($queue->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.call-centers.queues.edit', $queue->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$queue->name" wire:click="confirmQueueDeletion('{{ $queue->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_queues_found') }}
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
            :title="__('admin.modal_delete_queue_title')"
            :message="__('admin.modal_delete_generic_statement', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_queue_confirm')"
            confirm-action="deleteQueue('{{ $pendingDeletionId }}')"
            cancel-action="cancelQueueDeletion"
        />
    @endif
</div>
