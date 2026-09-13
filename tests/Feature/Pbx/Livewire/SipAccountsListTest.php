<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\SipAccounts\Livewire\SipAccountsList;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
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
