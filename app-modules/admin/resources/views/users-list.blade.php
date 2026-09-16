<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-semibold">{{ __('admin.users_title') }}</h2>
        <div class="flex items-center gap-2">
            @if(Auth::guard('admin')->user()?->hasPermission('admin.impersonate'))
                <x-tooltip :tip="__('admin.impersonation_logs_tooltip')" position="left">
                    <a href="{{ route('panel.impersonation-logs.index') }}" class="btn btn-outline btn-sm">
                        <x-heroicon-o-clipboard-document-list class="w-4 h-4" />
                        {{ __('admin.impersonation_logs') }}
                    </a>
                </x-tooltip>
            @endif
            <x-tooltip :tip="__('admin.create_user_tooltip')" position="left">
                <a href="{{ route('panel.users.create') }}" class="btn btn-primary btn-sm">
                    <x-heroicon-o-plus class="w-4 h-4" />
                    {{ __('admin.create_user') }}
                </a>
            </x-tooltip>
        </div>
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
                        <th><x-tooltip :tip="__('admin.email_tooltip')" position="bottom">{{ __('admin.email') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.tenants_count_tooltip')" position="bottom">{{ __('admin.tenants') }}</x-tooltip></th>
                        <th><x-tooltip :tip="__('admin.groups_count_tooltip')" position="bottom">{{ __('admin.groups') }}</x-tooltip></th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td class="font-medium">{{ $user->name }}</td>
                            <td class="text-base-content/60">{{ $user->email }}</td>
                            <td>
                                <span class="badge badge-ghost">{{ $user->tenants_count }}</span>
                            </td>
                            <td>
                                <span class="badge badge-ghost">{{ $user->groups_count }}</span>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-tooltip :tip="__('admin.edit_user')">
                                        <a href="{{ route('panel.users.edit', $user->id) }}"
                                           class="btn btn-ghost btn-xs">
                                            <x-heroicon-o-pencil class="w-4 h-4" />
                                        </a>
                                    </x-tooltip>
                                    <x-tooltip :tip="__('admin.impersonate_tooltip')">
                                        <form method="POST" action="{{ route('panel.users.impersonate', $user) }}" class="inline">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-ghost btn-xs text-info">
                                                <x-heroicon-o-arrow-right-on-rectangle class="w-4 h-4" />
                                            </button>
                                        </form>
                                    </x-tooltip>
                                    <x-tooltip :tip="__('admin.delete_user_tooltip')">
                                        <button wire:click="confirmUserDeletion({{ $user->id }})"
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
                                No users found.
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
            :title="__('admin.modal_delete_user_title')"
            :message="__('admin.modal_delete_user_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_user_confirm')"
            confirm-action="deleteUser()"
            cancel-action="cancelUserDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_user_error_title')"
        />
    @endif
</div>
