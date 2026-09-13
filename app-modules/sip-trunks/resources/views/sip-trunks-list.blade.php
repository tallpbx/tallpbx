<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.sip_trunks_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.sip-trunks.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_sip_trunk') }}
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
                        <th>{{ __('admin.sip_trunk_name') }}</th>
                        <th>{{ __('admin.sip_trunk_host') }}</th>
                        <th>{{ __('admin.sip_trunk_username') }}</th>
                        <th>{{ __('admin.sip_trunk_codecs') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($trunks as $trunk)
                        <tr>
                            <td class="font-medium">{{ $trunk->name }}</td>
                            <td class="font-mono text-sm">{{ $trunk->host }}:{{ $trunk->port }}</td>
                            <td>{{ $trunk->username ?? '-' }}</td>
                            <td class="text-sm">{{ $trunk->codecs ?? '-' }}</td>
                            <td>
                                @if($trunk->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.sip-trunks.edit', $trunk->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmTrunkDeletion('{{ $trunk->id }}')"
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
                                {{ __('admin.no_sip_trunks_found') }}
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
            :title="__('admin.modal_delete_sip_trunk_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_sip_trunk_confirm')"
            confirm-action="deleteTrunk('{{ $pendingDeletionId }}')"
            cancel-action="cancelTrunkDeletion"
        />
    @endif
</div>
