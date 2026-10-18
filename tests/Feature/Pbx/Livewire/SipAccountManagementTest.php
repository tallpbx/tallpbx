<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\SipAccounts\Livewire\SipAccountsEdit;
use Modules\SipAccounts\Livewire\SipAccountsList;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the sip accounts list component', function () {
    SipAccount::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->assertOk()
        ->assertSee('SIP Accounts')
        ->assertViewHas('accounts', function ($accounts) {
            return $accounts->count() === 3;
        });
});

it('displays auth username and identity mode for each account', function () {
    SipAccount::factory()->create([
        'auth_username' => 'a_7f3k_999',
        'identity_mode' => 'global_username',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->assertSee('a_7f3k_999')
        ->assertSee(__('admin.sip_type_global'));
});

it('deletes a sip account', function () {
    $account = SipAccount::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->call('deleteAccount', $account->id)
        ->assertDispatched('account-deleted');

    $this->assertModelMissing($account);
});

it('opens the shared confirmation modal before deleting a SIP account', function (): void {
    $account = SipAccount::factory()->create(['auth_username' => 'carrier-account']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->call('confirmAccountDeletion', $account->id)
        ->assertSet('pendingDeletionId', $account->id)
        ->assertSet('pendingDeletionName', 'carrier-account')
        ->assertSee('Delete SIP Account?');
});

it('shows empty state when no accounts exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->assertSee(__('admin.no_sip_accounts_found'));
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class)
        ->assertOk()
        ->assertSee('Create SIP Account')
        ->assertSet('authUsername', '')
        ->assertSet('authPassword', '')
        ->assertSet('identityMode', 'global_username');
});

it('renders the edit form with existing account data', function () {
    $account = SipAccount::factory()->create([
        'auth_username' => 'a_7f3k_999',
        'identity_mode' => 'global_username',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class, ['accountId' => $account->id])
        ->assertOk()
        ->assertSee('Edit SIP Account')
        ->assertSet('authUsername', 'a_7f3k_999');
});

it('creates a new sip account', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('authUsername', 'a_test_101')
        ->set('authPassword', 'password123')
        ->set('identityMode', 'global_username')
        ->call('save')
        ->assertRedirect(route('panel.sip-accounts.index'));

    $this->assertDatabaseHas('sip_accounts', [
        'auth_username' => 'a_test_101',
        'identity_mode' => 'global_username',
    ]);
});

it('updates an existing sip account', function () {
    $account = SipAccount::factory()->create(['auth_username' => 'old_user']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class, ['accountId' => $account->id])
        ->set('authUsername', 'updated_user')
        ->set('authPassword', 'new_password')
        ->call('save')
        ->assertRedirect(route('panel.sip-accounts.index'));

    $this->assertDatabaseHas('sip_accounts', [
        'id' => $account->id,
        'auth_username' => 'updated_user',
    ]);
});

it('validates auth username is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class)
        ->set('authUsername', '')
        ->call('save')
        ->assertHasErrors(['authUsername' => 'required']);
});

it('validates auth password is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class)
        ->set('authUsername', 'user')
        ->set('authPassword', '')
        ->call('save')
        ->assertHasErrors(['authPassword' => 'required']);
});

it('validates domain is required for domain username mode', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('authUsername', '101')
        ->set('authPassword', 'pass')
        ->set('identityMode', 'domain_username')
        ->set('tenantDomainId', null)
        ->call('save')
        ->assertHasErrors(['tenantDomainId' => 'required']);
});
