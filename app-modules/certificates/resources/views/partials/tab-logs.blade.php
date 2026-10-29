{{-- TAB 6: AUDIT LOGS --}}
@if ($activeTab === 'logs')
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            <div class="overflow-x-auto">
                <table class="table table-zebra table-sm w-full">
                    <thead>
                        <tr>
                            <th>{{ __('client.timestamp') }}</th>
                            <th>{{ __('client.action') }}</th>
                            <th>{{ __('client.status') }}</th>
                            <th>{{ __('client.administrator') }}</th>
                            <th>{{ __('client.message') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->auditLogs as $log)
                            <tr>
                                <td class="text-xs font-mono whitespace-nowrap text-base-content/70">
                                    {{ $log->created_at?->format('Y-m-d H:i:s') }}
                                </td>
                                <td>
                                    <span class="badge badge-outline badge-sm">{{ $log->action }}</span>
                                </td>
                                <td>
                                    @if ($log->status === 'success')
                                        <span class="badge badge-success badge-sm">Success</span>
                                    @elseif ($log->status === 'error')
                                        <span class="badge badge-error badge-sm">Error</span>
                                    @else
                                        <span class="badge badge-warning badge-sm">{{ $log->status }}</span>
                                    @endif
                                </td>
                                <td class="text-xs text-base-content/80">
                                    {{ $log->admin?->email ?? ($log->admin_id ? 'Admin #' . $log->admin_id : 'System') }}
                                </td>
                                <td class="text-xs text-base-content">{{ $log->message }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-6 text-base-content/60">
                                    No certificate audit log records recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pt-2">
                {{ $this->auditLogs->links() }}
            </div>
        </div>
    </div>
@endif

