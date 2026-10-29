<div>
    <x-page-header :title="__('admin.sip_trunks_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.sip-trunks.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_sip_trunk') }}
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
                        <tr wire:key="{{ $trunk->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmTrunkDeletion('{{ $trunk->id }}')">
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
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.sip-trunks.edit', $trunk->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$trunk->name" wire:click="confirmTrunkDeletion('{{ $trunk->id }}')" class="text-error" />
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
