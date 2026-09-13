<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.tenant_limits_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.tenant-limits.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_tenant_limit') }}
        </a>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.tenant_limit_resource') }}</th>
                        <th>{{ __('admin.tenant_limit_soft') }}</th>
                        <th>{{ __('admin.tenant_limit_hard') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($limits as $limit)
                        <tr>
                            <td class="font-medium">{{ $limit->resource }}</td>
                            <td>{{ $limit->soft_limit }}</td>
                            <td>{{ $limit->hard_limit }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.tenant-limits.edit', $limit->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmLimitDeletion('{{ $limit->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_tenant_limits_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($pendingDeletionId !== null)<x-confirmation-modal :open="true" :title="__('admin.modal_delete_tenant_limit_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_tenant_limit_confirm')" confirm-action="deleteLimit('{{ $pendingDeletionId }}')" cancel-action="cancelLimitDeletion" />@endif
</div>
