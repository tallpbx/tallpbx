<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">File Stores</h1>
            <p class="text-sm text-base-content/60">Remote storage locations for media archives and backups.</p>
        </div>

        <a href="{{ route('panel.file-stores.create') }}" class="btn btn-primary btn-sm whitespace-nowrap" wire:navigate>
            <x-heroicon-o-plus class="w-4 h-4" />
            Add file store
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    <div class="card border border-base-300 bg-base-100 p-4">
        <div class="flex items-start gap-3">
            <x-heroicon-o-server-stack class="mt-0.5 h-5 w-5 shrink-0 text-base-content/60" />
            <div>
                <h2 class="font-medium">Local storage</h2>
                <p class="text-sm text-base-content/60">TallPBX keeps media and backups in separate protected local folders. Media uses checksum verification; backups use verified manifests.</p>
                <dl class="mt-2 space-y-1 text-sm text-base-content/70">
                    <div>
                        <dt class="inline font-medium">Media library:</dt>
                        <dd class="inline">Completed media TallPBX keeps, such as recordings, voicemail, prompts, and music: <code>{{ config('media-storage.store_root') }}</code></dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Media staging:</dt>
                        <dd class="inline">A temporary staging area for active recordings and archive transfers. TallPBX removes files after they are safely saved, and retains them when a transfer needs retrying: <code>{{ config('media-storage.spool_root') }}</code></dd>
                    </div>
                    <div><dt class="inline font-medium">Backup archive:</dt> <dd class="inline"><code>{{ config('backup-storage.root') }}</code></dd></div>
                </dl>
            </div>
        </div>
    </div>

    @if ($canUpdateFileStores)
        <form wire:submit="updateArchiveDestination" class="flex flex-wrap items-end gap-3 border border-base-300 bg-base-100 p-4">
        <div class="grow">
            <label class="label" for="media-archive-file-store">
                <span class="label-text font-medium">{{ __('admin.media_archive_destination') }}</span>
                <x-tooltip tip="Media storage has two areas: the media library keeps completed files; media staging temporarily holds recordings and transfers until TallPBX saves them safely. Remote locations keep an off-server media copy; local backup storage is reserved for backups." align="start" position="right">
                    <x-heroicon-o-information-circle class="ml-1 inline h-4 w-4 cursor-help opacity-60" />
                </x-tooltip>
            </label>
            <select id="media-archive-file-store" class="select select-bordered w-full" wire:model="archiveFileStoreId">
                @foreach ($archiveFileStores as $fileStore)
                    <option value="{{ $fileStore->id }}">{{ $fileStore->name }} ({{ str($fileStore->provider)->headline() }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-sm text-base-content/60">{{ __('admin.media_archive_destination_help') }}</p>
        </div>
        <button type="submit" class="btn btn-primary">{{ __('admin.media_archive_destination_save') }}</button>
        </form>
        @if ($archiveDestinationSaved)
            <p id="archive-destination-status" data-archive-destination-id="{{ $archiveFileStoreId }}" class="sr-only" role="status">Media archive destination saved.</p>
        @endif
    @endif

    <div class="card border border-base-300 bg-base-100">
        <div class="border-b border-base-300 p-4">
            <h2 class="font-medium">Media archive transfers</h2>
            <p class="mt-1 text-sm text-base-content/60">Completed recordings and faxes remain in protected local staging until TallPBX verifies the selected archive destination. Failed transfers retain their local spool and can be retried here.</p>
        </div>
        @if ($archiveTransfers->isEmpty())
            <p class="p-4 text-sm text-base-content/60">No media archive transfers need attention.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($archiveTransfers as $transfer)
                            @php($statusClass = match ($transfer->status->value) {
                                'failed' => 'badge-error',
                                'transferring' => 'badge-warning',
                                default => 'badge-info',
                            })
                            <tr wire:key="media-archive-transfer-{{ $transfer->id }}">
                                <td class="font-medium">{{ $transfer->original_filename }}</td>
                                <td>{{ $transfer->fileStore->name }}</td>
                                <td><span class="badge badge-sm {{ $statusClass }}">{{ str($transfer->status->value)->headline() }}</span></td>
                                <td>{{ $transfer->sync_attempts }}</td>
                                <td>
                                    @if ($canUpdateFileStores && $transfer->status->value === 'failed')
                                        <button type="button" class="btn btn-ghost btn-xs" wire:click="retryMediaArchive('{{ $transfer->id }}')" wire:loading.attr="disabled" wire:target="retryMediaArchive('{{ $transfer->id }}')">Retry transfer</button>
                                    @else
                                        <span class="text-sm text-base-content/60">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Provider</th>
                        <th>Connection</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fileStores as $fileStore)
                        <tr wire:key="file-store-{{ $fileStore->id }}">
                            <td class="font-medium">{{ $fileStore->name }}</td>
                            <td>{{ str($fileStore->provider)->headline() }}</td>
                            <td>
                                @if (isset($connectionMessages[$fileStore->id]))
                                    <span class="text-base-content/70">{{ $connectionMessages[$fileStore->id] }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-tooltip tip="Test connection">
                                        <button type="button" class="btn btn-ghost btn-xs" wire:click="testConnection('{{ $fileStore->id }}')">
                                            <x-heroicon-o-signal class="w-4 h-4" />
                                        </button>
                                    </x-tooltip>
                                    <x-tooltip tip="Edit file store">
                                        <a href="{{ route('panel.file-stores.edit', $fileStore->id) }}" class="btn btn-ghost btn-xs" wire:navigate>
                                            <x-heroicon-o-pencil-square class="w-4 h-4" />
                                        </a>
                                    </x-tooltip>
                                    <x-tooltip tip="Delete file store">
                                        <button type="button" class="btn btn-ghost btn-xs text-error" wire:click="confirmFileStoreDeletion('{{ $fileStore->id }}')">
                                            <x-heroicon-o-trash class="w-4 h-4" />
                                        </button>
                                    </x-tooltip>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-10 text-center text-base-content/60">No file store profiles are configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionFileStoreId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_file_store_title')"
            :message="__('admin.modal_delete_file_store_message', ['name' => $pendingDeletionFileStoreName])"
            :confirm-label="__('admin.modal_delete_file_store_confirm')"
            confirm-action="deleteFileStore('{{ $pendingDeletionFileStoreId }}')"
            cancel-action="cancelFileStoreDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_file_store_error_title')"
        />
    @endif

</div>
