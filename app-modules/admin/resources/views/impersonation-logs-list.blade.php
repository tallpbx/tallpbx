<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('panel.users.index') }}" class="btn btn-ghost btn-xs btn-circle">
                    <x-heroicon-o-arrow-left class="w-4 h-4" />
                </a>
                <h1 class="text-2xl font-bold">{{ __('admin.impersonation_logs_title') }}</h1>
            </div>
            <p class="text-sm text-base-content/60 mt-1">
                {{ __('admin.impersonation_logs_description') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('panel.users.index') }}" class="btn btn-ghost btn-sm gap-1.5">
                <x-heroicon-o-users class="w-4 h-4" />
                {{ __('admin.users') }}
            </a>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="card bg-base-100 border border-base-300 mb-6">
        <div class="card-body p-4 flex flex-col sm:flex-row items-center gap-4 justify-between">
            <div class="flex flex-1 flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
                <div class="relative w-full sm:w-72">
                    <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-base-content/50" />
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="{{ __('admin.search_logs_placeholder') }}"
                           class="input input-bordered input-sm w-full pl-9" />
                </div>
                <div class="w-full sm:w-48">
                    <select wire:model.live="actionFilter" class="select select-bordered select-sm w-full">
                        <option value="">{{ __('admin.all_actions') }}</option>
                        <option value="start">{{ __('admin.action_start') }}</option>
                        <option value="stop">{{ __('admin.action_stop') }}</option>
                    </select>
                </div>
            </div>
            @if ($search !== '' || $actionFilter !== '')
                <button wire:click="resetFilters" class="btn btn-ghost btn-sm text-base-content/60 hover:text-base-content">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                    {{ __('admin.clear_filters') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Audit Log Table --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.timestamp') }}</th>
                        <th>{{ __('admin.administrator') }}</th>
                        <th>{{ __('admin.action') }}</th>
                        <th>{{ __('admin.target_user') }}</th>
                        <th>{{ __('admin.ip_address') }}</th>
                        <th>{{ __('admin.user_agent') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="text-sm font-mono whitespace-nowrap">
                                <div>{{ $log->created_at?->format('Y-m-d H:i:s') }}</div>
                                <span class="text-xs text-base-content/50">{{ $log->created_at?->diffForHumans() }}</span>
                            </td>
                            <td>
                                <div class="font-medium text-sm">
                                    {{ $log->display_admin }}
                                </div>
                                <span class="badge badge-ghost badge-xs font-mono">ID: {{ $log->admin_id }}</span>
                            </td>
                            <td>
                                @if($log->action === 'start')
                                    <span class="badge badge-info badge-sm gap-1">
                                        <x-heroicon-o-arrow-right-on-rectangle class="w-3.5 h-3.5" />
                                        {{ __('admin.action_start') }}
                                    </span>
                                @elseif($log->action === 'stop')
                                    <span class="badge badge-warning badge-sm gap-1">
                                        <x-heroicon-o-arrow-left-on-rectangle class="w-3.5 h-3.5" />
                                        {{ __('admin.action_stop') }}
                                    </span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ ucfirst($log->action) }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-medium text-sm">
                                    {{ $log->display_user }}
                                </div>
                                <span class="badge badge-ghost badge-xs font-mono">ID: {{ $log->user_id }}</span>
                            </td>
                            <td class="font-mono text-xs whitespace-nowrap text-base-content/70">
                                {{ $log->ip_address ?? '—' }}
                            </td>
                            <td class="max-w-xs truncate text-xs text-base-content/60" title="{{ $log->user_agent }}">
                                {{ $log->user_agent ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-12 text-base-content/40">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-heroicon-o-clipboard-document-list class="w-8 h-8 opacity-40" />
                                    <span>{{ __('admin.no_impersonation_logs') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
            <div class="p-4 border-t border-base-300">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
