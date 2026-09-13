<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.follow_me_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.follow-me.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_follow_me') }}
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
                        <th>{{ __('admin.follow_me_name') }}</th>
                        <th>{{ __('admin.follow_me_extension') }}</th>
                        <th>{{ __('admin.follow_me_destination') }}</th>
                        <th>{{ __('admin.follow_me_ring_timeout') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($followMeRecords as $record)
                        <tr>
                            <td class="font-medium">{{ $record->name }}</td>
                            <td><span class="badge badge-ghost">{{ $record->extension }}</span></td>
                            <td><span class="font-mono text-sm">{{ $record->destination }}</span></td>
                            <td>{{ $record->ring_timeout }}s</td>
                            <td>
                                @if ($record->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.follow-me.edit', $record->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmFollowMeDeletion('{{ $record->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_follow_me_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal :open="true" :title="__('admin.modal_delete_follow_me_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_follow_me_confirm')" confirm-action="deleteFollowMe('{{ $pendingDeletionId }}')" cancel-action="cancelFollowMeDeletion" />
    @endif
</div>
