<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.dialplans_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.dialplans.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_dialplan') }}
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
                        <th>{{ __('admin.dialplan_name') }}</th>
                        <th>{{ __('admin.dialplan_context') }}</th>
                        <th>{{ __('admin.dialplan_order') }}</th>
                        <th>{{ __('admin.dialplan_details') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dialplans as $dialplan)
                        <tr>
                            <td class="font-medium">{{ $dialplan->name }}</td>
                            <td><code class="badge badge-ghost">{{ $dialplan->context }}</code></td>
                            <td>{{ $dialplan->order }}</td>
                            <td><span class="badge">{{ $dialplan->details_count }} {{ __('admin.dialplan_rules') }}</span></td>
                            <td>
                                @if($dialplan->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.dialplans.edit', $dialplan->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmDialplanDeletion('{{ $dialplan->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_dialplans_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
       </div>
    </div>

    <div class="mt-4">
        {{ $dialplans->links() }}
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_dialplan_title')"
            :message="__('admin.modal_delete_dialplan_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_dialplan_confirm')"
            confirm-action="deleteDialplan('{{ $pendingDeletionId }}')"
            cancel-action="cancelDialplanDeletion"
        />
    @endif
</div>
