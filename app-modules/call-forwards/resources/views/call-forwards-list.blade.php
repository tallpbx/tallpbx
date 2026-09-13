<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.call_forwards_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.call-forwards.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_call_forward') }}
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
                        <th>{{ __('admin.extension') }}</th>
                        <th>{{ __('admin.forward_type') }}</th>
                        <th>{{ __('admin.destination') }}</th>
                        <th>{{ __('admin.ring_timeout') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($forwards as $forward)
                        <tr>
                            <td class="font-medium">{{ $forward->extension_uuid }}</td>
                            <td><span class="badge badge-ghost">{{ $forward->forward_type }}</span></td>
                            <td>{{ $forward->destination }}</td>
                            <td>{{ $forward->ring_timeout }}s</td>
                            <td>
                                @if ($forward->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.call-forwards.edit', $forward->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmForwardDeletion('{{ $forward->id }}')"
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
                                {{ __('admin.no_call_forwards_found') }}
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
            :title="__('admin.modal_delete_call_forward_title')"
            :message="__('admin.modal_delete_call_forward_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_call_forward_confirm')"
            confirm-action="deleteForward('{{ $pendingDeletionId }}')"
            cancel-action="cancelForwardDeletion"
        />
    @endif
</div>
