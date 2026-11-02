<div>
    <x-page-header :title="__('admin.cdr_title')" :tooltip="__('admin.page_info_tooltip')" />

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="flex items-center gap-2 mb-4">
        <label for="legFilter" class="text-sm font-medium">{{ __('admin.cdr_leg') }}</label>
        <select id="legFilter" wire:model.live="legFilter" class="select select-bordered select-sm">
            <option value="all">{{ __('admin.cdr_leg_all') }}</option>
            <option value="a">{{ __('admin.cdr_leg_a') }}</option>
            <option value="b">{{ __('admin.cdr_leg_b') }}</option>
        </select>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.cdr_caller') }}</th>
                        <th>{{ __('admin.cdr_destination') }}</th>
                        <th>{{ __('admin.cdr_direction') }}</th>
                        <th>{{ __('admin.cdr_leg') }}</th>
                        <th>{{ __('admin.cdr_duration') }}</th>
                        <th>{{ __('admin.cdr_date') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $record->caller_id_name }}</span>
                                <span class="text-xs text-base-content/60 block">{{ $record->caller_id }}</span>
                            </td>
                            <td><span class="font-mono text-sm">{{ $record->destination }}</span></td>
                            <td>
                                <span class="badge badge-ghost badge-sm">{{ $record->direction }}</span>
                            </td>
                            <td>
                                <span class="badge badge-xs font-mono">{{ strtoupper((string) $record->leg) }}</span>
                            </td>
                            <td>{{ gmdate('i:s', $record->duration) }}</td>
                            <td>{{ $record->start_stamp?->format('M j, Y g:i A') }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-eye" :label="__('client.view')" :href="route('panel.cdr.detail', $record->id)" />
                                    @if ($record->record_path !== null)
                                        {{-- Media links keep wire:navigate off: playback must hit the
                                             recording URL directly (same as the fax inbox). --}}
                                        <a href="{{ route('panel.cdr.recording', $record->id) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-xs btn-square" aria-label="{{ __('admin.cdr_recording') }}">
                                            <x-heroicon-o-play class="w-4 h-4" />
                                        </a>
                                    @endif
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$record->caller_id_name" wire:click="confirmCdrDeletion('{{ $record->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_cdrs_found') }}
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
            :title="__('admin.modal_delete_call_detail_record_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_call_detail_record_confirm')"
            confirm-action="deleteCdr()"
            cancel-action="cancelCdrDeletion"
        />
    @endif
</div>
