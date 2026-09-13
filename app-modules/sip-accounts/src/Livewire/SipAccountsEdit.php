<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Livewire;

use App\Models\TenantDomain;
use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\SipAccounts\Livewire\Validation\SipAccountValidation;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

/**
 * Livewire component for creating and editing SIP accounts.
 */
class SipAccountsEdit extends BaseEditComponent
{
    public ?int $tenantDomainId = null;

    public string $identityMode = 'global_username';

    public string $authUsername = '';

    public string $authPassword = '';

    public ?string $accountId = null;

    /** @var Collection<int, TenantDomain> */
    public Collection $domains;

    private SipAccountServiceInterface $accountService;

    public function boot(SipAccountServiceInterface $accountService): void
    {
        $this->accountService = $accountService;
    }

    public function mount(?string $accountId = null): void
    {
        $this->loadTenants();
        $this->domains = TenantDomain::orderBy('domain')->get();

        if ($accountId !== null) {
            $this->accountId = $accountId;
            $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($accountId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($account);
            $this->tenantId = $account->tenant_id;
            $this->tenantDomainId = $account->tenant_domain_id;
            $this->identityMode = $account->identity_mode;
            $this->authUsername = $account->auth_username;
            $this->enabled = $account->enabled;
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->accountId !== null;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'tenant_domain_id' => $this->tenantDomainId,
            'identity_mode' => $this->identityMode,
            'auth_username' => $this->authUsername,
            'auth_password' => $this->authPassword,
            'enabled' => $this->enabled,
        ];

        if ($this->accountId !== null) {
            $account = SipAccount::withoutGlobalScope('tenant')->findOrFail($this->accountId);
            if (empty($this->authPassword)) {
                unset($data['auth_password']);
            }
            $this->accountService->update($account, $data);
        } else {
            $this->accountService->create($data);
        }

        $this->redirect(route('panel.sip-accounts.index'));
    }

    protected function rules(): array
    {
        return SipAccountValidation::rules(
            isCreate: $this->accountId === null,
            identityMode: $this->identityMode,
        );
    }
}
