<div class="max-w-5xl">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold">{{ __('admin.backups') }}</h2>
            <x-tooltip :tip="__('admin.backups_list_tooltip')" position="right">
                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-40 hover:opacity-80 ml-1 inline" />
            </x-tooltip>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('panel.backups.restore') }}" class="btn btn-outline btn-error" wire:navigate>Restore</a>
            <a href="{{ route('panel.backups.create') }}" class="btn btn-primary" wire:navigate>
                {{ __('admin.backup_create') }}
            </a>
        </div>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    @if ($backups->isEmpty())
        <div class="card bg-base-100 border border-base-300 p-6 text-center">
            <p class="text-base-content/60">{{ __('admin.backup_no_configs') }}</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="table table-zebra w-full">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th><x-tooltip :tip="__('admin.backup_scopes_tooltip')">{{ __('admin.backup_scopes') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.backup_status_tooltip')">{{ __('admin.status') }}</x-tooltip></th>
                        <th>{{ __('admin.backup_last_run') }}</th>
                        <th><x-tooltip :tip="__('admin.backup_size_tooltip')">{{ __('admin.backup_size') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.backup_retention_tooltip')">{{ __('admin.backup_retention') }}</x-tooltip></th>
                        <th>{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr>
                            <td class="font-medium">{{ $backup->name }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($backup->scope as $s)
                                        <span class="badge badge-sm">{{ $s }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if ($backup->status === 'completed')
                                    <span class="badge badge-success">{{ __('admin.backup_completed') }}</span>
                                @elseif ($backup->status === 'running')
                                    <span class="badge badge-warning">{{ __('admin.backup_running') }}</span>
                                @elseif ($backup->status === 'failed')
                                    <x-tooltip :tip="$backup->last_error">
                                        <span class="badge badge-error">{{ __('admin.backup_failed') }}</span>
                                    </x-tooltip>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.backup_pending') }}</span>
                                @endif
                            </td>
                            <td class="text-sm text-base-content/60">
                                {{ $backup->last_run_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="text-sm">
                                {{ $backup->size_bytes ? number_format($backup->size_bytes / 1024 / 1024, 1) . ' MB' : '—' }}
                            </td>
                            <td>{{ $backup->retention_count }}</td>
                            <td>
                                <div class="flex gap-1">
                                    <x-tooltip :tip="__('admin.backup_run_now')">
                                        <button wire:click="confirmRunNow('{{ $backup->id }}')"
                                            class="btn btn-ghost btn-xs">▶</button>
                                    </x-tooltip>
                                    <x-tooltip :tip="__('admin.delete')">
                                        <button wire:click="confirmBackupDeletion('{{ $backup->id }}')"
                                            class="btn btn-ghost btn-xs text-error">✕</button>
                                    </x-tooltip>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_backup_title')"
            :message="__('admin.modal_delete_backup_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_backup_confirm')"
            confirm-action="deleteBackup('{{ $pendingDeletionId }}')"
            cancel-action="cancelBackupDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_backup_error_title')"
        />
    @endif

    @if ($pendingRunId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_run_backup_title')"
            :message="__('admin.modal_run_backup_message', ['name' => $pendingRunName])"
            :confirm-label="__('admin.modal_run_backup_confirm')"
            confirm-action="runNow()"
            cancel-action="cancelRunNow"
        />
    @endif
</div>
