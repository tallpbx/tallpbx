<div>
    <x-page-header :title="__('admin.music_on_hold_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.music-on-hold.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_music_on_hold') }}
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
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.audio_file') }}</th>
                        <th>{{ __('admin.description') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($holdMusics as $music)
                        <tr>
                            <td class="font-medium">{{ $music->name }}</td>
                            <td>{{ $music->audio_file ?? '-' }}</td>
                            <td>{{ $music->description ?? '-' }}</td>
                            <td>
                                @if ($music->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.music-on-hold.edit', $music->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$music->name" wire:click="confirmMusicOnHoldDeletion('{{ $music->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_music_on_hold_found') }}
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
            :title="__('admin.modal_delete_music_on_hold_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_music_on_hold_confirm')"
            confirm-action="deleteMusicOnHold()"
            cancel-action="cancelMusicOnHoldDeletion"
        />
    @endif
</div>
