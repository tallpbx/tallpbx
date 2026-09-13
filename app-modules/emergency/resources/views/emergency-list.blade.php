<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.emergency_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.emergency.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_emergency') }}
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
                        <th>{{ __('admin.emergency_caller') }}</th>
                        <th>{{ __('admin.emergency_address') }}</th>
                        <th>{{ __('admin.emergency_latitude') }}</th>
                        <th>{{ __('admin.emergency_longitude') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ $record->caller_id ?? '-' }}</td>
                            <td>{{ $record->address }}</td>
                            <td>{{ $record->latitude ?? '-' }}</td>
                            <td>{{ $record->longitude ?? '-' }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.emergency.edit', $record->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmRecordDeletion('{{ $record->id }}')"
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
                                {{ __('admin.no_emergency_records_found') }}
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
            :title="__('admin.modal_delete_emergency_title')"
            :message="__('admin.modal_delete_emergency_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_emergency_confirm')"
            confirm-action="deleteRecord('{{ $pendingDeletionId }}')"
            cancel-action="cancelRecordDeletion"
        />
    @endif
</div>
