<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Validation\ValidationException;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

beforeEach(function () {
    $this->service = app(SipAccountServiceInterface::class);
});

it('creates a sip account in global username mode', function () {
    $tenant = Tenant::factory()->create();

    $account = $this->service->create([
        'tenant_id' => $tenant->id,
        'identity_mode' => 'global_username',
        'auth_username' => 'a_7f3k_999',
        'auth_password' => 'secret123',
        'enabled' => true,
    ]);

    expect($account)
        ->toBeInstanceOf(SipAccount::class)
        ->identity_mode->toBe('global_username')
        ->auth_username->toBe('a_7f3k_999')
        ->global_auth_key->toBe('global:a_7f3k_999')
        ->enabled->toBeTrue();
});

it('creates a sip account in domain username mode', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

    $account = $this->service->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'identity_mode' => 'domain_username',
        'auth_username' => '101',
        'auth_password' => 'secret456',
        'enabled' => true,
    ]);

    expect($account->global_auth_key)->toBe("domain:{$domain->id}:101");
});

it('creates a sip account in hybrid mode', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

    $account = $this->service->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'identity_mode' => 'hybrid',
        'auth_username' => 'h_abc_101',
        'auth_password' => 'secret789',
        'enabled' => true,
    ]);

    expect($account->global_auth_key)->toBe("hybrid:{$domain->id}:h_abc_101");
});

it('updates a sip account', function () {
    $account = SipAccount::factory()->create([
        'auth_username' => 'old_user',
    ]);

    $updated = $this->service->update($account, [
        'auth_username' => 'new_user',
        'auth_password' => 'new_pass',
    ]);

    expect($updated->auth_username)->toBe('new_user');
});

it('deletes a sip account', function () {
    $account = SipAccount::factory()->create();

    $this->service->delete($account);

    $this->assertModelMissing($account);
});

it('enforces global uniqueness for global username mode', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant1->id,
        'identity_mode' => 'global_username',
        'auth_username' => 'unique_user',
        'auth_password' => 'pass1',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant2->id,
        'identity_mode' => 'global_username',
        'auth_username' => 'unique_user',
        'auth_password' => 'pass2',
    ]);
});

it('allows same username in different domains for domain username mode', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();
    $domain1 = TenantDomain::factory()->create(['tenant_id' => $tenant1->id]);
    $domain2 = TenantDomain::factory()->create(['tenant_id' => $tenant2->id]);

    $account1 = $this->service->create([
        'tenant_id' => $tenant1->id,
        'tenant_domain_id' => $domain1->id,
        'identity_mode' => 'domain_username',
        'auth_username' => '101',
        'auth_password' => 'pass1',
    ]);

    $account2 = $this->service->create([
        'tenant_id' => $tenant2->id,
        'tenant_domain_id' => $domain2->id,
        'identity_mode' => 'domain_username',
        'auth_username' => '101',
        'auth_password' => 'pass2',
    ]);

    expect($account1->id)->not->toBe($account2->id)
        ->and($account1->auth_username)->toBe($account2->auth_username);
});

it('requires domain_id for domain username mode', function () {
    $tenant = Tenant::factory()->create();

    $this->expectException(InvalidArgumentException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'identity_mode' => 'domain_username',
        'auth_username' => '101',
        'auth_password' => 'pass1',
    ]);
});
