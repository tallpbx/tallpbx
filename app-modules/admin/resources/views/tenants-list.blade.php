<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">{{ __('admin.tenants_title') }}</h2>
        <x-tooltip :tip="__('admin.create_tenant_tooltip')" position="left">
            <a href="{{ route('panel.tenants.create') }}" class="btn btn-primary btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.create_tenant') }}
            </a>
        </x-tooltip>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th><x-tooltip :tip="__('admin.name_tooltip')" position="right">{{ __('admin.name') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.tenant_slug_tooltip')" position="bottom">{{ __('admin.tenant_slug') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.primary_user_tooltip')" position="bottom">{{ __('admin.primary_user') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.users_count_tooltip')" position="bottom">{{ __('admin.users') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.tenant_status_tooltip')" position="bottom">{{ __('client.status') }}</x-tooltip></th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tenants as $tenant)
                        <tr>
                            <td class="font-medium">{{ $tenant->name }}</td>
                            <td class="text-base-content/60">{{ $tenant->slug }}</td>
                            <td>
                                @if($tenant->primaryUser)
                                    <span class="text-base-content/80">{{ $tenant->primaryUser->name }}</span>
                                @else
                                    <span class="text-base-content/30 italic">{{ __('admin.none') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-ghost">{{ $tenant->users_count }}</span>
                            </td>
                            <td>
                                @if($tenant->enabled)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-error">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-tooltip :tip="__('admin.edit_tenant')">
                                        <a href="{{ route('panel.tenants.edit', $tenant->id) }}"
                                           class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-pencil class="w-4 h-4" />
                                        </a>
                                    </x-tooltip>
                                    <x-tooltip :tip="__('admin.delete_tenant_tooltip')">
                                        <button wire:click="confirmTenantDeletion({{ $tenant->id }})"
                                                class="btn btn-ghost btn-xs text-error">
                                            <x-heroicon-o-trash class="w-4 h-4" />
                                        </button>
                                    </x-tooltip>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_tenants_found') }}
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
            :title="__('admin.modal_delete_tenant_title')"
            :message="__('admin.modal_delete_tenant_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_tenant_confirm')"
            confirm-action="deleteTenant()"
            cancel-action="cancelTenantDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_tenant_error_title')"
            :required-text="$pendingDeletionName"
        />
    @endif
</div>
