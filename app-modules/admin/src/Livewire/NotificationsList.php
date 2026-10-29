<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Support\OperationalControlFeedback;
use App\Support\PanelActor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire component that lists in-app notifications for the
 * currently authenticated actor (an Admin or a tenant User).
 *
 * Every query is scoped to the actor's own notifications; admins
 * additionally need the delete permission to remove one.
 */
#[Layout('layouts.app')]
class NotificationsList extends Component
{
    use OperationalControlFeedback;
    use WithPagination;

    public int $unreadCount = 0;

    /**
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        $viewGroup = [['admin.notifications.view', '@tenant-user']];

        return [
            '' => $viewGroup,
            'markAsRead' => $viewGroup,
            'markAllAsRead' => $viewGroup,
            'deleteNotification' => [['admin.notifications.delete', '@tenant-user']],
            'queryStringHandlesPagination' => $viewGroup,
            'getPage' => $viewGroup,
            'previousPage' => $viewGroup,
            'nextPage' => $viewGroup,
            'gotoPage' => $viewGroup,
            'resetPage' => $viewGroup,
            'setPage' => $viewGroup,
        ];
    }

    /**
     * Refresh the unread notification count when the page opens.
     */
    public function mount(): void
    {
        $this->refreshCount();
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(string $notificationId): void
    {
        $notification = $this->actorNotifications()
            ->findOrFail($notificationId);

        $notification->markAsRead();
        $this->refreshCount();
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllAsRead(): void
    {
        $this->actorNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->refreshCount();
    }

    /**
     * Delete one of the current actor's notifications.
     *
     * Admins need the delete permission; tenant users may always delete
     * their own. The scoped query guarantees ownership.
     */
    public function deleteNotification(string $notificationId): void
    {
        $this->actionMessage = null;
        $this->actionError = null;

        if (PanelActor::isAdmin() && ! $this->authorizeAction('admin.notifications.delete')) {
            $this->actionError = 'You do not have permission to delete notifications.';

            return;
        }

        $this->actorNotifications()->findOrFail($notificationId)->delete();
        $this->actionMessage = 'Notification deleted.';
        $this->refreshCount();
    }

    /**
     * Refresh the unread count.
     */
    private function refreshCount(): void
    {
        $this->unreadCount = $this->actorNotifications()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * The authenticated actor — an Admin (admin guard) or a User (web guard).
     */
    private function currentActor(): ?Model
    {
        return PanelActor::resolve();
    }

    /**
     * Base query scoped to the current actor's own notifications.
     *
     * Falls back to an empty query when no guard resolves, so an
     * unexpected session state degrades to an empty list, not a crash.
     */
    private function actorNotifications()
    {
        $actor = $this->currentActor();

        if ($actor === null) {
            // No signed-in actor (unreachable behind auth): return an empty list, never every notification.
            return DatabaseNotification::query()->whereRaw('1 = 0');
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', $actor::class)
            ->where('notifiable_id', $actor->getAuthIdentifier());
    }

    /**
     * Render the paginated notifications list for the signed-in account.
     */
    public function render(): View
    {
        $notifications = $this->actorNotifications()
            ->latest()
            ->paginate(15);

        return view('admin::notifications-list', [
            'notifications' => $notifications,
        ]);
    }
}
