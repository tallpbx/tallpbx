<div>
    <x-page-header :title="__('admin.destinations_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.destinations.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_destination') }}
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
                        <th>{{ __('admin.destination_type') }}</th>
                        <th>{{ __('admin.destination_dial_string') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($destinations as $destination)
                        <tr wire:key="{{ $destination->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmDestinationDeletion('{{ $destination->id }}')">
                            <td class="font-medium">{{ $destination->name }}</td>
                            <td><span class="badge badge-ghost">{{ $destination->type }}</span></td>
                            <td><code class="text-sm">{{ $destination->dial_string }}</code></td>
                            <td>
                                @if($destination->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.destinations.edit', $destination->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$destination->name" wire:click="confirmDestinationDeletion('{{ $destination->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_destinations_found') }}
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
            :title="__('admin.modal_delete_destination_title')"
            :message="__('admin.modal_delete_generic_statement', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_destination_confirm')"
            confirm-action="deleteDestination('{{ $pendingDeletionId }}')"
            cancel-action="cancelDestinationDeletion"
        />
    @endif
</div>
