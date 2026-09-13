<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Extensions\Models\Extension;
use Modules\HotDesking\Models\HotDeskSession;
use Modules\HotDesking\Services\HotDeskingService;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->extUser = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '201',
        'display_name' => 'Alice User',
    ]);

    $this->extDesk = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '104',
        'display_name' => 'Desk 4',
    ]);

    $this->service = app(HotDeskingService::class);
});

it('starts a new hot desking session successfully', function (): void {
    $session = $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $this->extDesk->id,
        '192.168.1.50',
        'Morning shift desk'
    );

    expect($session)->toBeInstanceOf(HotDeskSession::class)
        ->and($session->tenant_id)->toBe($this->tenant->id)
        ->and($session->extension_id)->toBe($this->extUser->id)
        ->and($session->device_extension_id)->toBe($this->extDesk->id)
        ->and($session->is_active)->toBeTrue()
        ->and($session->ip_address)->toBe('192.168.1.50')
        ->and($session->logout_at)->toBeNull();
});

it('automatically deactivates previous sessions for the same user or desk phone', function (): void {
    $firstSession = $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $this->extDesk->id
    );

    expect($firstSession->fresh()->is_active)->toBeTrue();

    // Another desk extension
    $extDesk2 = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '105',
    ]);

    // Alice moves from desk 104 to desk 105
    $secondSession = $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $extDesk2->id
    );

    expect($secondSession->is_active)->toBeTrue()
        ->and($firstSession->fresh()->is_active)->toBeFalse()
        ->and($firstSession->fresh()->logout_at)->not->toBeNull();
});

it('terminates an active session via logout', function (): void {
    $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $this->extDesk->id
    );

    $loggedOut = $this->service->logout($this->tenant->id, $this->extUser->id);
    expect($loggedOut)->toBeTrue();

    $active = $this->service->getActiveSessionForExtension($this->tenant->id, $this->extUser->id);
    expect($active)->toBeNull();
});

it('enforces tenant isolation between tenants', function (): void {
    $session = $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $this->extDesk->id
    );

    // Another tenant cannot see or log out this session
    $loggedOutByOtherTenant = $this->service->logout($this->otherTenant->id, $this->extUser->id);
    expect($loggedOutByOtherTenant)->toBeFalse()
        ->and($session->fresh()->is_active)->toBeTrue();

    $otherTenantSessions = $this->service->getActiveSessions($this->otherTenant->id);
    expect($otherTenantSessions)->toBeEmpty();
});

it('generates dialplan XML including login, logout, and dynamic routing', function (): void {
    $this->service->login(
        $this->tenant->id,
        $this->extUser->id,
        $this->extDesk->id
    );

    $xml = $this->service->generateDialplanXml(
        $this->tenant->id,
        "tenant_{$this->tenant->id}_internal",
        '201'
    );

    expect($xml)->not->toBeNull()
        ->and($xml)->toContain('hot_desk_login')
        ->and($xml)->toContain('^\\*11$')
        ->and($xml)->toContain('hot_desk_logout')
        ->and($xml)->toContain('^\\*12$')
        ->and($xml)->toContain('expression="^201$"')
        ->and($xml)->toContain('<action application="transfer" data="104 XML ${context}"/>');
});
