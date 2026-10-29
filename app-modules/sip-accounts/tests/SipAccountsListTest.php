<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Modules\SipAccounts\Livewire\SipAccountsList;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->admin = grantAdminPermissions(permissions: ['sip-accounts.view', 'sip-accounts.create', 'sip-accounts.edit', 'sip-accounts.delete']);
    $this->tenant = Tenant::factory()->create();
});

it('renders the sip accounts list component with paginated accounts', function () {
    SipAccount::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->assertOk()
        ->assertSee('SIP Accounts')
        ->assertViewHas('accounts', function ($accounts) {
            return $accounts instanceof LengthAwarePaginator && $accounts->count() === 3;
        });
});

it('deletes a sip account', function () {
    $account = SipAccount::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipAccountsList::class)
        ->call('deleteAccount', $account->id)
        ->assertDispatched('account-deleted');

    $this->assertModelMissing($account);
});
