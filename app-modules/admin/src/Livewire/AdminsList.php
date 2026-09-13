<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Admin;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component that lists all administrator accounts.
 *
 * Superadmin and admin management access. Shows account status,
 * system groups, and provides actions to create, edit, or delete admins.
 */
#[Layout('layouts.app')]
class AdminsList extends Component
{
    use HasOperationalFeedback;

    public ?int $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    /**
     * Open confirmation modal for deleting an administrator.
     */
    public function confirmAdminDeletion(int $adminId): void
    {
        $admin = Admin::findOrFail($adminId);

        $currentAdmin = Auth::guard('admin')->user();
        if ($currentAdmin !== null && $currentAdmin->id === $admin->id) {
            $this->showError(__('admin.admin_delete_self_error'));

            return;
        }

        $superAdminsCount = Admin::whereHas('groups', function ($query): void {
            $query->where('name', 'Super Administrators');
        })->where('enabled', true)->count();

        $isTargetSuperAdmin = $admin->groups()->where('name', 'Super Administrators')->exists();

        if ($isTargetSuperAdmin && $superAdminsCount <= 1) {
            $this->showError(__('admin.admin_delete_last_super_error'));

            return;
        }

        $this->pendingDeletionId = $admin->id;
        $this->pendingDeletionName = $admin->email;
        $this->deleteError = null;
    }

    /**
     * Close the admin deletion confirmation dialog.
     */
    public function cancelAdminDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /**
     * Delete the confirmed administrator.
     */
    public function deleteAdmin(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        $admin = Admin::findOrFail($this->pendingDeletionId);

        $currentAdmin = Auth::guard('admin')->user();
        if ($currentAdmin !== null && $currentAdmin->id === $admin->id) {
            $this->deleteError = __('admin.admin_delete_self_error');

            return;
        }

        $superAdminsCount = Admin::whereHas('groups', function ($query): void {
            $query->where('name', 'Super Administrators');
        })->where('enabled', true)->count();

        $isTargetSuperAdmin = $admin->groups()->where('name', 'Super Administrators')->exists();

        if ($isTargetSuperAdmin && $superAdminsCount <= 1) {
            $this->deleteError = __('admin.admin_delete_last_super_error');

            return;
        }

        $admin->groups()->detach();
        $admin->delete();

        $this->cancelAdminDeletion();
        $this->showSuccess(__('admin.admin_deleted_success'));
        $this->dispatch('admin-deleted');
    }

    public function render(): View
    {
        return view('admin::admins-list', [
            'admins' => Admin::with('groups')->orderBy('name')->get(),
        ]);
    }
}
