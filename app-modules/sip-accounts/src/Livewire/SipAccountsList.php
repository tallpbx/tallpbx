<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

/**
 * Livewire component that lists SIP accounts with CRUD actions.
 */
class SipAccountsList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private SipAccountServiceInterface $accountService;

    /** Inject the SIP-account service. */
    public function boot(SipAccountServiceInterface $accountService): void
    {
        $this->accountService = $accountService;
    }

    /** Delete the confirmed SIP account. */
    public function deleteAccount(string $accountId): void
    {
        $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($accountId);
        $this->accountService->delete($account);
        $this->cancelAccountDeletion();
        $this->showSuccess('SIP account deleted.');
        $this->dispatch('account-deleted');
    }

    /** Open the shared destructive-action confirmation for one SIP account. */
    public function confirmAccountDeletion(string $accountId): void
    {
        $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($accountId);
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
            'accounts' => SipAccount::withoutGlobalScope('tenant')
                ->with('tenantDomain')
                ->orderBy('auth_username')
                ->paginate(15),
        ]);
    }
}
