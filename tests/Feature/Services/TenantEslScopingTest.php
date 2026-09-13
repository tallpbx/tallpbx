<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantEslScoping;
use Modules\CallCenters\Models\Queue;
use Modules\Conferences\Models\Conference;
use Modules\Extensions\Models\Extension;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    $this->scoping = app(TenantEslScoping::class);
});

it('keeps every channel for an admin (null tenant)', function () {
    $channels = [
        ['uuid' => 'a', 'context' => 'tenant_1_internal'],
        ['uuid' => 'b', 'context' => 'tenant_2_internal'],
        ['uuid' => 'c', 'context' => ''],
    ];

    expect($this->scoping->filterChannelsByContext($channels, null))->toHaveCount(3);
});

it('keeps only channels whose context belongs to the tenant', function () {
    Extension::factory()->create(['tenant_id' => $this->tenant->id, 'extension_number' => '1001']);
    $channels = [
        ['uuid' => 'a', 'context' => 'tenant_'.$this->tenant->id.'_internal'],
        ['uuid' => 'b', 'context' => 'tenant_'.$this->tenant->id.'_public'],
        ['uuid' => 'c', 'context' => 'tenant_'.$this->other->id.'_internal'],
        ['uuid' => 'd', 'context' => ''],
        ['uuid' => 'e', 'context' => 'default'],
    ];

    $filtered = $this->scoping->filterChannelsByContext($channels, (int) $this->tenant->id);

    expect(array_column($filtered, 'uuid'))->toBe(['a', 'b']);
});

it('keeps conferences matching the tenant name or a member extension', function () {
    Extension::factory()->create(['tenant_id' => $this->tenant->id, 'extension_number' => '1001']);
    Conference::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'sales', 'enabled' => true]);

    $conferences = [
        ['name' => 'sales', 'members' => 0, 'status' => 'running', 'members_list' => []],
        ['name' => '3000', 'members' => 1, 'status' => 'running', 'members_list' => [
            ['id' => '1', 'uuid' => 'u1', 'caller_id' => '1001'],
        ]],
        ['name' => 'other-conf', 'members' => 1, 'status' => 'running', 'members_list' => [
            ['id' => '1', 'uuid' => 'u2', 'caller_id' => '2001'],
        ]],
    ];

    $filtered = $this->scoping->filterConferencesByTenant($conferences, (int) $this->tenant->id);

    expect(array_column($filtered, 'name'))->toBe(['sales', '3000']);
});

it('keeps queues and agents belonging to the tenant', function () {
    Extension::factory()->create(['tenant_id' => $this->tenant->id, 'extension_number' => '1000']);
    Queue::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'support', 'enabled' => true]);

    $queues = [
        ['name' => 'support', 'strategy' => 'longest-idle-agent'],
        ['name' => 'billing', 'strategy' => 'ring-all'],
    ];
    $agents = [
        ['agent' => '1000@support', 'status' => 'Available'],
        ['agent' => '5000@billing', 'status' => 'Available'],
    ];

    expect(array_column($this->scoping->filterQueuesByTenant($queues, (int) $this->tenant->id), 'name'))->toBe(['support']);
    expect(array_column($this->scoping->filterAgentsByTenant($agents, (int) $this->tenant->id), 'agent'))->toBe(['1000@support']);
});

it('keeps registrations whose username maps uniquely to the tenant', function () {
    SipAccount::factory()->create(['tenant_id' => $this->tenant->id, 'auth_username' => '1001', 'enabled' => true]);

    $registrations = [
        ['user' => '1001@pbx.local', 'contact' => 'sip:1001@10.0.0.5', 'status' => 'registered', 'expires' => '300'],
        ['user' => '2001@pbx.local', 'contact' => 'sip:2001@10.0.0.6', 'status' => 'registered', 'expires' => '300'],
    ];

    $filtered = $this->scoping->filterRegistrationsByTenant($registrations, (int) $this->tenant->id);

    expect(array_column($filtered, 'user'))->toBe(['1001@pbx.local']);
});

it('disambiguates ambiguous usernames by domain and fails closed when the domain is shared', function () {
    // 1001 exists in both tenants (ambiguous).
    SipAccount::factory()->create(['tenant_id' => $this->tenant->id, 'auth_username' => '1001', 'enabled' => true]);
    SipAccount::factory()->create(['tenant_id' => $this->other->id, 'auth_username' => '1001', 'enabled' => true]);
    TenantDomain::factory()->create(['tenant_id' => $this->tenant->id, 'domain' => 'tenant-a.example.com']);
    TenantDomain::factory()->create(['tenant_id' => $this->other->id, 'domain' => 'shared.example.com']);
    TenantDomain::factory()->create(['tenant_id' => $this->tenant->id, 'domain' => 'shared.example.com']);

    $unique = [['user' => '1001@tenant-a.example.com', 'contact' => '', 'status' => '', 'expires' => '']];
    $shared = [['user' => '1001@shared.example.com', 'contact' => '', 'status' => '', 'expires' => '']];
    $unknown = [['user' => '1001@nowhere.example.com', 'contact' => '', 'status' => '', 'expires' => '']];

    expect($this->scoping->filterRegistrationsByTenant($unique, (int) $this->tenant->id))->toHaveCount(1);
    // Shared domain: fail closed (excluded from BOTH tenants).
    expect($this->scoping->filterRegistrationsByTenant($shared, (int) $this->tenant->id))->toHaveCount(0);
    expect($this->scoping->filterRegistrationsByTenant($shared, (int) $this->other->id))->toHaveCount(0);
    expect($this->scoping->filterRegistrationsByTenant($unknown, (int) $this->tenant->id))->toHaveCount(0);
});

it('excludes registrations with an unknown username even when the domain is unique', function () {
    // No SIP account with this username exists anywhere; the domain alone
    // must NOT admit the registration (fail-closed on unknown identity).
    TenantDomain::factory()->create(['tenant_id' => $this->tenant->id, 'domain' => 'tenant-a.example.com']);

    $registrations = [['user' => '9999@tenant-a.example.com', 'contact' => '', 'status' => '', 'expires' => '']];

    expect($this->scoping->filterRegistrationsByTenant($registrations, (int) $this->tenant->id))->toHaveCount(0);
});
