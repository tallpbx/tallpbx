<div class="max-w-4xl space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold">Restore backup</h2>
            <p class="mt-1 text-sm text-base-content/70">A restore replaces the selected database and file scopes on this PBX.</p>
        </div>
        <a href="{{ route('panel.backups.index') }}" class="btn btn-ghost" wire:navigate>Back to backups</a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    @if ($this->operation !== null)
        <section class="border border-base-300 p-5" wire:poll.5s>
            <div class="flex items-center justify-between gap-4">
                <h3 class="font-semibold">Restore operation</h3>
                @php($statusClass = match ($this->operation->status) {
                    'completed' => 'badge-success',
                    'failed' => 'badge-error',
                    'running', 'pending-helper' => 'badge-warning',
                    default => 'badge-info',
                })
                <span class="badge {{ $statusClass }}">{{ ucfirst(str_replace('-', ' ', $this->operation->status)) }}</span>
            </div>
            <p class="mt-3 text-sm text-base-content/70">Operation {{ $this->operation->id }}</p>
            @if ($this->operation->failure_reason !== null)
                <p class="mt-3 text-sm text-error">{{ $this->operation->failure_reason }}</p>
            @endif
            @if ($this->isSuperAdministrator && $this->operation->pre_restore_snapshot_path !== null)
                <p class="mt-3 break-all text-sm text-base-content/70">Pre-restore snapshot: {{ $this->operation->pre_restore_snapshot_path }}</p>
            @endif
        </section>
    @endif

    <section class="border border-base-300 p-5 space-y-5">
        <div>
            <h3 class="font-semibold">Restore from a configured File Store</h3>
            <p class="mt-1 text-sm text-base-content/70">Completed archives from Local storage - backups and remote File Store providers are shown below.</p>
        </div>

        @if ($backups->isEmpty())
            <p class="text-sm text-base-content/70">No completed backup archives are currently available from a File Store.</p>
        @else
            <div class="space-y-4">
                <div class="form-control">
                    <label class="label" for="backupId">
                        <span class="label-text">Available archive</span>
                    </label>
                    <select id="backupId" wire:model.live="backupId" class="select select-bordered w-full">
                        <option value="">Select a completed backup archive</option>
                        @foreach ($backups as $backup)
                            <option value="{{ $backup->id }}">
                                {{ $backup->name }} - {{ basename($backup->last_file_path) }} ({{ $backup->fileStore?->name ?? 'Unavailable' }})
                            </option>
                        @endforeach
                    </select>
                    @error('backupId') <span class="mt-1 text-sm text-error">{{ $message }}</span> @enderror
                </div>

                @if ($backupId !== null)
                    @php($selectedBackup = $backups->firstWhere('id', $backupId))
                    @if ($selectedBackup !== null)
                        <div class="bg-warning/10 border border-warning/30 p-4 text-sm space-y-2">
                            <p><span class="font-medium">Archive:</span> {{ basename($selectedBackup->last_file_path) }}</p>
                            <p><span class="font-medium">File Store:</span> {{ $selectedBackup->fileStore?->name ?? 'Unavailable' }} ({{ $selectedBackup->fileStore?->provider ?? 'unknown' }})</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($selectedBackup->scope as $scope)
                                    <span class="badge badge-sm">{{ ucfirst(str_replace('_', ' ', $scope)) }}</span>
                                @endforeach
                            </div>
                            <p>This restore overwrites the scopes shown above after its manifest checksum is verified.</p>
                        </div>
                    @endif
                @endif

                <button type="button" class="btn btn-error" wire:click="confirmStoredRestore" wire:loading.attr="disabled" wire:target="confirmStoredRestore">Restore selected archive</button>
            </div>
        @endif
    </section>

    <section class="border border-base-300 p-5 space-y-5">
        <div>
            <h3 class="font-semibold">Restore from an uploaded archive</h3>
            <p class="mt-1 text-sm text-base-content/70">Upload the backup archive and its matching <code>backup-manifest.json</code>. Uploads are staged privately and are not retained as a File Store backup.</p>
        </div>

        <div class="space-y-4">
            <div class="form-control">
                <label class="label" for="archiveUpload"><span class="label-text">Archive file</span></label>
                <input id="archiveUpload" wire:model="archiveUpload" type="file" accept=".tar,.gz" class="file-input file-input-bordered w-full" />
                @error('archiveUpload') <span class="mt-1 text-sm text-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-control">
                <label class="label" for="manifestUpload"><span class="label-text">Matching manifest</span></label>
                <input id="manifestUpload" wire:model="manifestUpload" type="file" accept="application/json,.json" class="file-input file-input-bordered w-full" />
                @error('manifestUpload') <span class="mt-1 text-sm text-error">{{ $message }}</span> @enderror
            </div>
            <button type="button" class="btn btn-error" wire:click="confirmUploadedRestore" wire:loading.attr="disabled" wire:target="confirmUploadedRestore,archiveUpload,manifestUpload">Restore uploaded archive</button>
        </div>
    </section>

    @if ($selectedManifest !== [])
        <section class="border border-base-300 p-5 space-y-3">
            <h3 class="font-semibold">Verified manifest</h3>
            <p class="break-all text-sm text-base-content/70">SHA-256: {{ $selectedManifest['archive']['sha256'] }}</p>
            <div class="flex flex-wrap gap-1">
                @foreach ($selectedManifest['scopes'] as $scope)
                    <span class="badge badge-sm">{{ ucfirst(str_replace('_', ' ', $scope)) }}</span>
                @endforeach
            </div>
            @if (($selectedManifest['application_revision'] ?? 'unknown') !== 'unknown')
                <p class="text-sm text-base-content/70">Application revision: {{ $selectedManifest['application_revision'] }}</p>
            @endif
        </section>
    @endif

    @if ($confirmingRestore)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_restore_title')"
            :message="__('admin.modal_restore_message', ['name' => $pendingRestoreName])"
            :confirm-label="__('admin.modal_restore_confirm')"
            confirm-action="requestRestore()"
            cancel-action="cancelRestore"
            :error="$restoreError"
            :error-title="__('admin.modal_restore_error_title')"
            :required-text="$pendingRestoreName ?? ''"
        />
    @endif
</div>
