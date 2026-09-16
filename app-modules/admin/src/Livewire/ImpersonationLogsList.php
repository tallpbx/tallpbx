<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\ImpersonationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire component that displays the admin impersonation audit log.
 *
 * Provides a paginated audit trail of all start and stop impersonation actions,
 * capturing the initiating administrator, target user, action, IP address,
 * user agent, and timestamp. Accessible strictly to administrators with the
 * admin.impersonate permission.
 */
#[Layout('layouts.app')]
class ImpersonationLogsList extends Component
{
    use WithPagination;

    /**
     * Search term for filtering logs by admin, user, or IP address.
     */
    #[Url]
    public string $search = '';

    /**
     * Filter by action type ('start', 'stop', or empty for all).
     */
    #[Url]
    public string $actionFilter = '';

    /**
     * Ensure the authenticated user has permission to view audit logs.
     */
    public function mount(): void
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null || ! $admin->hasPermission('admin.impersonate')) {
            abort(403, 'Unauthorized.');
        }
    }

    /**
     * Reset pagination when search query is updated.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when action filter is changed.
     */
    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all active search and action filters.
     */
    public function resetFilters(): void
    {
        $this->reset(['search', 'actionFilter']);
        $this->resetPage();
    }

    /**
     * Render the component with paginated impersonation logs.
     */
    public function render(): View
    {
        $logs = ImpersonationLog::query()
            ->with(['admin', 'user'])
            ->when($this->actionFilter !== '', function ($query) {
                $query->where('action', $this->actionFilter);
            })
            ->when($this->search !== '', function ($query) {
                $search = trim($this->search);
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('admin_name', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('admin', function ($adminQuery) use ($search) {
                            $adminQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(20);

        return view('admin::impersonation-logs-list', [
            'logs' => $logs,
        ]);
    }
}
