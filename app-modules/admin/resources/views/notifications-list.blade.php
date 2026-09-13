<div>
    @if ($actionMessage)
        <div class="alert alert-success mb-4"><span>{{ $actionMessage }}</span></div>
    @endif
    @if ($actionError)
        <div class="alert alert-error mb-4"><span>{{ $actionError }}</span></div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ __('admin.notifications_title') }}</h1>
            <p class="text-sm text-base-content/60 mt-1">
                {{ trans_choice('admin.unread_notifications', $unreadCount, ['count' => $unreadCount]) }}
            </p>
        </div>
        @if ($unreadCount > 0)
            <button wire:click="markAllAsRead" class="btn btn-ghost btn-sm">
                {{ __('admin.mark_all_read') }}
            </button>
        @endif
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body p-0">
            @if ($notifications->isEmpty())
                <div class="p-8 text-center text-base-content/50">
                    <p>{{ __('admin.no_notifications') }}</p>
                </div>
            @else
                <div class="divide-y divide-base-300">
                    @foreach ($notifications as $notification)
                        <div class="flex items-start gap-4 p-4 {{ is_null($notification->read_at) ? 'bg-base-200' : '' }}">
                            {{-- Unread dot --}}
                            <div class="mt-1.5 shrink-0">
                                @if (is_null($notification->read_at))
                                    <span class="block w-2.5 h-2.5 rounded-full bg-primary"></span>
                                @else
                                    <span class="block w-2.5 h-2.5 rounded-full bg-base-300"></span>
                                @endif
                            </div>

                            {{-- Content --}}
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm">
                                    {{ data_get($notification->data, 'title', __('admin.notification_default_title')) }}
                                </p>
                                <p class="text-sm text-base-content/60 mt-0.5">
                                    {{ data_get($notification->data, 'message', '') }}
                                </p>
                                @if (data_get($notification->data, 'category') !== null && data_get($notification->data, 'original_filename') !== null)
                                    <p class="text-xs text-base-content/50 mt-1">
                                        {{ data_get($notification->data, 'category') }} · {{ data_get($notification->data, 'original_filename') }}
                                    </p>
                                @endif
                                <p class="text-xs text-base-content/40 mt-1">
                                    {{ $notification->created_at->diffForHumans() }}
                                </p>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 shrink-0">
                                @if (is_null($notification->read_at))
                                    <button wire:click="markAsRead('{{ $notification->id }}')"
                                            class="btn btn-ghost btn-xs"
                                            title="{{ __('admin.mark_read') }}">
                                        {{ __('admin.mark_read') }}
                                    </button>
                                @endif
                                <button wire:click="deleteNotification('{{ $notification->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                        title="{{ __('client.delete') }}">
                                    {{ __('client.delete') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pagination --}}
                @if ($notifications->hasPages())
                    <div class="p-4 border-t border-base-300">
                        {{ $notifications->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
