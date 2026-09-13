<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">{{ __('admin.fax_inbox_title') }}</h1>
            <a href="{{ route('panel.fax.send') }}" class="btn btn-primary">
                <x-heroicon-o-plus class="w-5 h-5" />
                {{ __('admin.fax_send') }}
            </a>
        </div>

        @if ($operationalMessage !== null)
            <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">
                {{ $operationalMessage }}
            </x-inline-alert>
        @endif

        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.fax_caller') }}</th>
                        <th>{{ __('admin.fax_pages') }}</th>
                        <th>{{ __('admin.fax_received') }}</th>
                        <th class="w-24">{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($faxes as $fax)
                        <tr>
                            <td><span class="font-mono text-sm">{{ $fax->caller_id }}</span></td>
                            <td>{{ $fax->pages }}</td>
                            <td>{{ $fax->received_at?->format('M j, Y g:i A') }}</td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <a href="{{ $fax->mediaAsset !== null ? route('panel.media-assets.stream', $fax->mediaAsset) : asset('storage/' . $fax->document_path) }}" target="_blank" class="btn btn-ghost btn-sm">
                                        <x-heroicon-o-eye class="w-4 h-4" />
                                    </a>
                                    @if ($fax->mediaAsset !== null)
                                        <a href="{{ route('panel.media-assets.download', $fax->mediaAsset) }}" class="btn btn-ghost btn-sm">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    @endif
                                    <button
                                        wire:click="confirmFaxDeletion('{{ $fax->id }}')"
                                        class="btn btn-ghost btn-sm text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-8 text-base-content/50">
                                {{ __('admin.no_faxes_found') }}
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
            :title="__('admin.modal_delete_fax_title')"
            :message="__('admin.modal_delete_fax_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_fax_confirm')"
            confirm-action="deleteFax()"
            cancel-action="cancelFaxDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_fax_error_title')"
        />
    @endif
</div>
