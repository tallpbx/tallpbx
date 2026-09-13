<?php

declare(strict_types=1);

namespace Modules\HotDesking\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\HotDesking\Models\HotDeskSession;

/**
 * Livewire component for listing and managing hot desking sessions.
 */
class HotDeskingList extends BaseListComponent
{
    /** @var Collection<int, HotDeskSession> */
    public Collection $sessions;

    public string $statusFilter = 'all';

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /**
     * Mount component and load initial sessions.
     */
    public function mount(): void
    {
        $this->loadSessions();
    }

    /**
     * Load sessions based on current filter.
     */
    public function loadSessions(): void
    {
        $query = HotDeskSession::when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))
            ->with(['extension', 'deviceExtension'])
            ->latest('login_at');

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $this->sessions = $query->get();
    }

    /**
     * Update status filter and refresh sessions.
     */
    public function filterStatus(string $status): void
    {
        $this->statusFilter = $status;
        $this->loadSessions();
    }

    /**
     * End an active hot desking session.
     */
    public function endSession(string $sessionId): void
    {
        $session = HotDeskSession::when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))->findOrFail($sessionId);
        $session->update([
            'is_active' => false,
            'logout_at' => now(),
        ]);

        $this->loadSessions();
        $this->showSuccess('Hot desking session ended.');
        $this->dispatch('session-ended');
    }

    /**
     * Delete a hot desking record permanently.
     */
    public function deleteSession(string $sessionId): void
    {
        $session = HotDeskSession::when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))->findOrFail($sessionId);
        $session->delete();
        $this->cancelSessionDeletion();
        $this->loadSessions();
        $this->showSuccess('Hot desking session record deleted.');
        $this->dispatch('session-deleted');
    }

    /**
     * Open confirmation modal for deleting a session.
     */
    public function confirmSessionDeletion(string $sessionId): void
    {
        $session = HotDeskSession::when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))->findOrFail($sessionId);
        $this->pendingDeletionId = $session->id;
        $this->pendingDeletionName = ($session->extension?->extension_number ?? 'Unknown').' → '.($session->deviceExtension?->extension_number ?? 'Unknown');
    }

    /**
     * Cancel deletion modal.
     */
    public function cancelSessionDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
