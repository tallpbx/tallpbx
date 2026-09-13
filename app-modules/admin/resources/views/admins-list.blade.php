<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold">{{ __('admin.administrators') }}</h2>
            <p class="text-sm text-base-content/60 mt-1">{{ __('admin.administrators_description') }}</p>
        </div>
        <x-tooltip :tip="__('admin.create_admin_tooltip')" position="left">
            <a href="{{ route('panel.admins.create') }}" class="btn btn-primary btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.admin_add') }}
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
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.email') }}</th>
                        <th>{{ __('admin.admin_groups') }}</th>
                        <th>{{ __('admin.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($admins as $admin)
                        <tr>
                            <td class="font-medium">
                                <div class="flex items-center gap-2">
                                    <x-heroicon-o-user-circle class="w-5 h-5 text-base-content/40" />
                                    <span>{{ $admin->name }}</span>
                                </div>
                            </td>
                            <td class="text-base-content/70 font-mono text-sm">{{ $admin->email }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse($admin->groups as $group)
                                        <span class="badge {{ $group->name === 'Super Administrators' ? 'badge-primary' : 'badge-neutral' }} badge-sm">
                                            {{ $group->name }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-base-content/40">None</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                @if($admin->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-error badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-tooltip :tip="__('admin.admin_edit')">
                                        <a href="{{ route('panel.admins.edit', $admin->id) }}"
                                           class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-pencil class="w-4 h-4" />
                                        </a>
                                    </x-tooltip>
                                    <x-tooltip :tip="__('admin.admin_delete')">
                                        <button wire:click="confirmAdminDeletion({{ $admin->id }})"
                                                class="btn btn-ghost btn-xs text-error">
                                            <x-heroicon-o-trash class="w-4 h-4" />
                                        </button>
                                    </x-tooltip>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_admins_found') }}
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
            :title="__('admin.admin_delete_modal_title')"
            :message="__('admin.admin_delete_confirm', ['email' => $pendingDeletionName])"
            :confirm-label="__('admin.delete')"
            confirm-action="deleteAdmin"
            cancel-action="cancelAdminDeletion"
            :error="$deleteError"
        />
    @endif
</div>
