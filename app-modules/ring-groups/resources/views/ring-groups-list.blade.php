<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.ring_groups_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.ring-groups.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_ring_group') }}
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
                        <th>{{ __('admin.ring_group_name') }}</th>
                        <th>{{ __('admin.ring_group_strategy') }}</th>
                        <th>{{ __('admin.ring_group_timeout') }}</th>
                        <th>{{ __('admin.ring_group_extensions') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ringGroups as $group)
                        <tr>
                            <td class="font-medium">{{ $group->name }}</td>
                            <td>
                                <span class="badge badge-ghost">{{ $group->strategy }}</span>
                            </td>
                            <td>{{ $group->ring_timeout }}s</td>
                            <td>
                                @if ($group->extensions->count() > 0)
                                    <span class="badge badge-ghost">{{ $group->extensions->count() }} {{ __('admin.extensions') }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($group->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.ring-groups.edit', $group->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmRingGroupDeletion('{{ $group->id }}')"
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
                                {{ __('admin.no_ring_groups_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal :open="true" :title="__('admin.modal_delete_ring_group_title')" :message="__('admin.modal_delete_ring_group_message', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_ring_group_confirm')" confirm-action="deleteRingGroup('{{ $pendingDeletionId }}')" cancel-action="cancelRingGroupDeletion" />
    @endif
</div>
