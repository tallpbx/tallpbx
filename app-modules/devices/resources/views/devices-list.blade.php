<div>
    <x-page-header :title="__('admin.devices_title')" :tooltip="__('admin.device_header_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.devices.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_device') }}
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
                        <th>{{ __('admin.device_vendor') }}</th>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.device_mac') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr wire:key="{{ $device->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmDeviceDeletion('{{ $device->id }}')">
                            <td class="font-medium">{{ $device->vendor }}</td>
                            <td>{{ $device->model }}</td>
                            <td class="font-mono text-sm">{{ $device->mac_address }}</td>
                            <td>
                                @if($device->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.devices.edit', $device->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$device->mac_address" wire:click="confirmDeviceDeletion('{{ $device->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_devices_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
       </div>
    </div>

    @if (method_exists($devices, 'links'))
        <div class="mt-4">
            {{ $devices->links() }}
        </div>
    @endif

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_device_title')"
            :message="__('admin.modal_delete_device_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_device_confirm')"
            confirm-action="deleteDevice('{{ $pendingDeletionId }}')"
            cancel-action="cancelDeviceDeletion"
        />
    @endif
</div>
