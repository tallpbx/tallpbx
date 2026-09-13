<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <h2 class="text-2xl font-semibold">{{ __('admin.hot_desking_title') }}</h2>
            <x-tooltip :tip="__('admin.hot_desking_tooltip')" position="right">
                <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
            </x-tooltip>
        </div>
        <div class="flex items-center gap-3">
            <div class="join">
                <button
                    wire:click="filterStatus('all')"
                    class="btn btn-sm join-item {{ $statusFilter === 'all' ? 'btn-active' : '' }}"
                >
                    {{ __('admin.all') }}
                </button>
                <button
                    wire:click="filterStatus('active')"
                    class="btn btn-sm join-item {{ $statusFilter === 'active' ? 'btn-active' : '' }}"
                >
                    {{ __('admin.active') }}
                </button>
                <button
                    wire:click="filterStatus('inactive')"
                    class="btn btn-sm join-item {{ $statusFilter === 'inactive' ? 'btn-active' : '' }}"
                >
                    {{ __('admin.inactive') }}
                </button>
            </div>

            <a href="{{ route('panel.hot-desking.create') }}" class="btn btn-primary btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.create_hot_desk_session') }}
            </a>
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
                        <th>{{ __('admin.hot_desking_user_extension') }}</th>
                        <th>{{ __('admin.hot_desking_desk_extension') }}</th>
                        <th>{{ __('admin.ip_address') }}</th>
                        <th>{{ __('admin.status') }}</th>
                        <th>{{ __('admin.hot_desking_login_at') }}</th>
                        <th>{{ __('admin.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td>
                                <div class="font-bold flex items-center gap-2">
                                    <x-heroicon-o-user class="w-4 h-4 text-primary" />
                                    <span>{{ $session->extension?->extension_number ?? __('admin.unknown') }}</span>
                                    @if ($session->extension?->display_name)
                                        <span class="text-xs text-base-content/60 font-normal">({{ $session->extension->display_name }})</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <x-heroicon-o-phone class="w-4 h-4 text-base-content/60" />
                                    <span>{{ $session->deviceExtension?->extension_number ?? __('admin.unknown') }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="font-mono text-xs text-base-content/70">{{ $session->ip_address ?? '—' }}</span>
                            </td>
                            <td>
                                @if ($session->is_active)
                                    <span class="badge badge-success badge-sm gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-success-content animate-pulse"></span>
                                        {{ __('admin.active') }}
                                    </span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="text-xs text-base-content/70" title="{{ $session->login_at->toIso8601String() }}">
                                    {{ $session->login_at->diffForHumans() }}
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    @if ($session->is_active)
                                        <button
                                            wire:click="endSession('{{ $session->id }}')"
                                            class="btn btn-warning btn-xs"
                                            title="{{ __('admin.hot_desking_end_session') }}"
                                        >
                                            <x-heroicon-o-arrow-right-on-rectangle class="w-3.5 h-3.5" />
                                            {{ __('admin.hot_desking_logout') }}
                                        </button>
                                    @endif

                                    <a href="{{ route('panel.hot-desking.edit', $session->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>

                                    <button
                                        wire:click="confirmSessionDeletion('{{ $session->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                        title="{{ __('admin.delete') }}"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_hot_desking_sessions_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :title="__('admin.delete')"
            :message="__('admin.confirm_delete_hot_desk_session')"
            :confirm-action="'deleteSession(\''.$pendingDeletionId.'\')'"
            :cancel-action="'cancelSessionDeletion'"
        />
    @endif
</div>
