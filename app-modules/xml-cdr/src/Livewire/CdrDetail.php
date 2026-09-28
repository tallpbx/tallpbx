<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Livewire;

use App\Services\ImpersonationServiceInterface;
use App\Services\TenantManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\XmlCdr\Models\Cdr;

/**
 * Livewire component showing one call detail record in full.
 */
#[Layout('layouts.app')]
class CdrDetail extends Component
{
    public ?string $cdrId = null;

    /**
     * Remember which call detail record to display.
     */
    public function mount(string $cdrId): void
    {
        $this->cdrId = $cdrId;
    }

    /**
     * Load the record by id and render the detail view.
     */
    public function render(): View
    {
        // Administrators may open any record; tenant users only see records
        // of their active tenant, so a foreign id is hidden as "not found"
        // instead of disclosing another tenant's call detail record.
        $cdr = Cdr::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->findOrFail($this->cdrId);

        return view('xml-cdr::cdr-detail', ['cdr' => $cdr]);
    }

    /**
     * Check whether the current user is authenticated via the admin guard.
     */
    protected function isAdminGuard(): bool
    {
        return Auth::guard('admin')->check()
            && ! app(ImpersonationServiceInterface::class)->isImpersonating();
    }
}
