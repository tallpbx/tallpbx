<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">{{ __('admin.gateways_title') }}</h2>
        <a href="{{ route('panel.gateways.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_gateway') }}
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
                        <th><x-tooltip :tip="__('admin.gateway_name_tooltip')" position="bottom">{{ __('admin.gateway_name') }}</x-tooltip></th>
                        <th>Profile</th>
                        <th><x-tooltip :tip="__('admin.gateway_host_tooltip')" position="bottom">{{ __('admin.gateway_host') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.gateway_register_tooltip')" position="bottom">{{ __('admin.gateway_register') }}</x-tooltip></th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($gateways as $gateway)
                        <tr>
                            <td class="font-medium">{{ $gateway->name }}</td>
                            <td>{{ $gateway->profile ?? 'external' }}</td>
                            <td class="text-base-content/60">{{ $gateway->host }}:{{ $gateway->port }}</td>
                            <td>
                                @if($gateway->register)
                                    <span class="badge badge-info">{{ __('client.yes') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.no') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($gateway->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.gateways.edit', $gateway->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmGatewayDeletion('{{ $gateway->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_gateways_found') }}
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
            :title="__('admin.modal_delete_gateway_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_gateway_confirm')"
            confirm-action="deleteGateway('{{ $pendingDeletionId }}')"
            cancel-action="cancelGatewayDeletion"
        />
    @endif
</div>
