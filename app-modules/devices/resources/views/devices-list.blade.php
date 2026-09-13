<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.devices_title') }}</h2><x-tooltip :tip="__('admin.device_header_tooltip')" align="start" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.devices.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_device') }}
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
                        <th>{{ __('admin.device_vendor') }}</th>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.device_mac') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr>
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
                                    <a href="{{ route('panel.devices.edit', $device->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmDeviceDeletion('{{ $device->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
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

    <div class="mt-4">
        {{ $devices->links() }}
    </div>

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
