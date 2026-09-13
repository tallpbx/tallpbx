<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\User;
use App\Services\UserServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component that lists all users with CRUD actions.
 *
 * Admin-only access. Shows tenant memberships and group
 * assignments for each user.
 */
#[Layout('layouts.app')]
class UsersList extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, User> */
    public Collection $users;

    public ?int $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private UserServiceInterface $userService;

    public function boot(UserServiceInterface $userService): void
    {
        $this->userService = $userService;
    }

    public function mount(): void
    {
        $this->loadUsers();
    }

    /**
     * Load all users with relationship counts.
     */
    private function loadUsers(): void
    {
        $this->users = $this->userService->all();
        $this->users->loadCount(['tenants', 'groups']);
    }

    /** Open the shared confirmation modal for one user. */
    public function confirmUserDeletion(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->pendingDeletionId = $user->id;
        $this->pendingDeletionName = $user->email;
        $this->deleteError = null;
    }

    /** Close the user deletion confirmation without deleting. */
    public function cancelUserDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /** Delete the user that the user confirmed, keeping expected failures in the modal. */
    public function deleteUser(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        $user = User::findOrFail($this->pendingDeletionId);

        try {
            $this->userService->delete($user);
        } catch (RuntimeException $exception) {
            $this->deleteError = 'User could not be deleted. '.$exception->getMessage();

            return;
        }

        $this->cancelUserDeletion();
        $this->loadUsers();
        $this->showSuccess('User deleted.');
        $this->dispatch('user-deleted');
    }

    public function render(): View
    {
        return view('admin::users-list');
    }
}
