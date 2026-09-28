<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Devices\Models\Device;

use function Pest\Laravel\get;

beforeEach(function () {
    config([
        'provisioning.enabled' => true,
        'provisioning.http_auth_username' => null,
        'provisioning.http_auth_password' => null,
        'provisioning.cidr' => null,
    ]);

    $tenant = Tenant::factory()->create();
    Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'grandstream',
        'mac_address' => '00:11:22:33:44:55',
        'enabled' => true,
    ]);
});

it('hides the endpoint when provisioning is disabled', function () {
    config(['provisioning.enabled' => false]);

    // 404 even for a valid, enabled device — the endpoint does not exist.
    get('/provision/00:11:22:33:44:55')->assertNotFound();
});

it('requires matching basic auth credentials when configured', function () {
    config([
        'provisioning.http_auth_username' => 'provision',
        'provisioning.http_auth_password' => 'secret',
    ]);

    get('/provision/00:11:22:33:44:55')->assertStatus(401);
    get('/provision/00:11:22:33:44:55', ['Authorization' => 'Basic '.base64_encode('provision:wrong')])->assertStatus(401);
    get('/provision/00:11:22:33:44:55', ['Authorization' => 'Basic '.base64_encode('provision:secret')])->assertOk();
});

it('authenticates before revealing whether a MAC address exists', function () {
    config([
        'provisioning.http_auth_username' => 'provision',
        'provisioning.http_auth_password' => 'secret',
    ]);

    // 401 (auth required), NOT 404 (unknown MAC) — no MAC probing.
    get('/provision/00:00:00:00:00:00')->assertStatus(401);
});

it('rejects requests from outside the CIDR allowlist', function () {
    config(['provisioning.cidr' => '10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
        ->get('/provision/00:11:22:33:44:55')
        ->assertForbidden();

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->get('/provision/00:11:22:33:44:55')
        ->assertOk();
});

it('treats blanked credentials as unset factors', function () {
    config([
        'provisioning.http_auth_username' => 'provision',
        'provisioning.http_auth_password' => '',
    ]);

    // Blanked password factor is disabled: username alone authenticates,
    // with any (or no) password sent by the phone.
    get('/provision/00:11:22:33:44:55')->assertStatus(401);
    get('/provision/00:11:22:33:44:55', ['Authorization' => 'Basic '.base64_encode('provision:anything')])->assertOk();
});

it('rate-limits failed auth attempts per ip and clears on success', function () {
    config([
        'provisioning.http_auth_username' => 'provision',
        'provisioning.http_auth_password' => 'secret',
    ]);

    $wrong = ['Authorization' => 'Basic '.base64_encode('provision:wrong')];

    foreach (range(1, 5) as $attempt) {
        get('/provision/00:11:22:33:44:55', $wrong)->assertStatus(401);
    }

    // The sixth failure is throttled instead of another 401.
    get('/provision/00:11:22:33:44:55', $wrong)->assertStatus(429);

    // Correct credentials succeed and clear the limiter.
    get('/provision/00:11:22:33:44:55', ['Authorization' => 'Basic '.base64_encode('provision:secret')])->assertOk();
});
