<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

/**
 * Livewire component that lists SIP accounts with CRUD actions.
 */
class SipAccountsList extends BaseListComponent
{
    use WithPagination;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private SipAccountServiceInterface $accountService;

    /** Inject the SIP-account service. */
    public function boot(SipAccountServiceInterface $accountService): void
    {
        $this->accountService = $accountService;
    }

    /** Get the paginated SIP account list. */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $query = $this->isAdminGuard()
            ? SipAccount::withoutGlobalScope('tenant')
            : SipAccount::query();

        return $query
            ->with('tenantDomain')
            ->orderBy('auth_username')
            ->paginate(25);
    }

    /** Delete the confirmed SIP account. */
    public function deleteAccount(string $accountId): void
    {
        $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($accountId);
        $this->assertCanAccessTenantRecord($account);
        $this->accountService->delete($account);
        $this->cancelAccountDeletion();
        $this->showSuccess('SIP account deleted.');
        $this->dispatch('account-deleted');
    }

    /** Open the shared destructive-action confirmation for one SIP account. */
    public function confirmAccountDeletion(string $accountId): void
    {
        $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($accountId);
        $this->assertCanAccessTenantRecord($account);
        $this->pendingDeletionId = $account->id;
        $this->pendingDeletionName = $account->auth_username;
    }

    /** Close the SIP-account deletion confirmation without changing the account. */
    public function cancelAccountDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }

    /** Render the paginated SIP account list. */
    public function render(): View
    {
        return view('sip-accounts::sip-accounts-list', [
            'accounts' => $this->accounts,
        ])->layout('layouts.app');
    }
}
