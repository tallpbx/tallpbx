<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.groups_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.groups.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_group') }}
        </a>
    </div>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    {{-- Tenant Filter --}}
    <div class="mb-4">
        <select wire:model.change="tenantId"
                class="select select-bordered select-sm w-full max-w-xs"
                wire:change="setFilter({ tenant_id: $event.target.value ? parseInt($event.target.value) : null })">
            <option value="">{{ __('admin.system_groups') }}</option>
            @foreach($tenants as $tenant)
                <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Groups Table --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.group_description') }}</th>
                        <th>{{ __('admin.users') }}</th>
                        <th>{{ __('admin.permissions') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($groups as $group)
                        <tr>
                            <td class="font-medium">{{ $group->name }}</td>
                            <td class="text-base-content/60">{{ $group->description }}</td>
                            <td>
                                <span class="badge badge-ghost">{{ $group->users_count }}</span>
                            </td>
                            <td>
                                <span class="badge badge-ghost">{{ $group->permissions_count }}</span>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.groups.edit', $group->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmGroupDeletion('{{ $group->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_groups_found') }}
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
            :title="__('admin.modal_delete_group_title')"
            :message="__('admin.modal_delete_group_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_group_confirm')"
            confirm-action="deleteGroup()"
            cancel-action="cancelGroupDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_group_error_title')"
        />
    @endif
</div>
