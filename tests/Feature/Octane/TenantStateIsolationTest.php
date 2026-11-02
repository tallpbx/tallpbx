<?php

declare(strict_types=1);

use App\Listeners\Octane\ClearTenantContext;
use App\Listeners\Octane\VerifyCleanDatabaseState;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    app(TenantManager::class)->clear();
});

it('clears active tenant context on RequestTerminated', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->setTenantId('tenant-alpha-uuid');

    expect($tenantManager->getTenantId())->toBe('tenant-alpha-uuid')
        ->and($tenantManager->hasTenant())->toBeTrue();

    Event::dispatch(new RequestTerminated(app(), app(), Request::create('/test'), new Response));

    expect($tenantManager->getTenantId())->toBeNull()
        ->and($tenantManager->hasTenant())->toBeFalse();
});

it('clears active tenant context on OperationTerminated events', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->setTenantId('tenant-beta-uuid');

    expect($tenantManager->getTenantId())->toBe('tenant-beta-uuid');

    Event::dispatch(new TaskTerminated(app(), app(), [], 'result'));

    expect($tenantManager->getTenantId())->toBeNull()
        ->and($tenantManager->hasTenant())->toBeFalse();
});

it('clears active tenant context on RequestReceived as pre-request boundary guard', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->setTenantId('tenant-lingering-uuid');

    expect($tenantManager->getTenantId())->toBe('tenant-lingering-uuid');

    Event::dispatch(new RequestReceived(app(), app(), Request::create('/test')));

    expect($tenantManager->getTenantId())->toBeNull()
        ->and($tenantManager->hasTenant())->toBeFalse();
});

it('ensures tenant context set during request 1 does not bleed into request 2 on the same container', function (): void {
    $tenantManager = app(TenantManager::class);

    // --- Request 1 (Tenant A) ---
    $request1 = Request::create('/api/tenant-a/resource');
    Event::dispatch(new RequestReceived(app(), app(), $request1));

    // Request 1 sets its tenant context
    $tenantManager->setTenantId('tenant-11111111-1111-1111-1111-111111111111');
    expect($tenantManager->getTenantId())->toBe('tenant-11111111-1111-1111-1111-111111111111');

    // Request 1 terminates
    Event::dispatch(new RequestTerminated(app(), app(), $request1, new Response));
    expect($tenantManager->getTenantId())->toBeNull();

    // --- Request 2 (Tenant B or unauthenticated guest on persistent container) ---
    $request2 = Request::create('/api/public/status');
    Event::dispatch(new RequestReceived(app(), app(), $request2));

    // Must NOT have Tenant A context
    expect($tenantManager->getTenantId())->toBeNull()
        ->and($tenantManager->hasTenant())->toBeFalse();

    // Request 2 sets Tenant B
    $tenantManager->setTenantId('tenant-22222222-2222-2222-2222-222222222222');
    expect($tenantManager->getTenantId())->toBe('tenant-22222222-2222-2222-2222-222222222222');

    // Request 2 terminates
    Event::dispatch(new RequestTerminated(app(), app(), $request2, new Response));
    expect($tenantManager->getTenantId())->toBeNull()
        ->and($tenantManager->hasTenant())->toBeFalse();
});

it('safely rolls back open database transactions on RequestTerminated', function (): void {
    DB::connection();

    DB::beginTransaction();
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    Event::dispatch(new RequestTerminated(app(), app(), Request::create('/test'), new Response));

    expect(DB::transactionLevel())->toBe(0);
});

it('safely rolls back open database transactions on RequestReceived as a pre-request guard', function (): void {
    DB::connection();

    DB::beginTransaction();
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    Event::dispatch(new RequestReceived(app(), app(), Request::create('/test')));

    expect(DB::transactionLevel())->toBe(0);
});

it('safely rolls back nested database transactions', function (): void {
    DB::connection();

    DB::beginTransaction();
    DB::beginTransaction();
    expect(DB::transactionLevel())->toBeGreaterThanOrEqual(2);

    Event::dispatch(new RequestTerminated(app(), app(), Request::create('/test'), new Response));

    expect(DB::transactionLevel())->toBe(0);
});

it('registers ClearTenantContext and VerifyCleanDatabaseState in config/octane.php', function (): void {
    $listeners = config('octane.listeners');

    expect($listeners)->toBeArray();

    expect($listeners)->toHaveKey(RequestReceived::class);
    expect($listeners[RequestReceived::class])->toContain(ClearTenantContext::class);
    expect($listeners[RequestReceived::class])->toContain(VerifyCleanDatabaseState::class);

    expect($listeners)->toHaveKey(RequestTerminated::class);
    expect($listeners[RequestTerminated::class])->toContain(ClearTenantContext::class);
    expect($listeners[RequestTerminated::class])->toContain(VerifyCleanDatabaseState::class);

    expect($listeners)->toHaveKey(OperationTerminated::class);
    expect($listeners[OperationTerminated::class])->toContain(ClearTenantContext::class);
    expect($listeners[OperationTerminated::class])->toContain(VerifyCleanDatabaseState::class);
});

it('handles event object with app and sandbox cleanly in ClearTenantContext', function (): void {
    $listener = new ClearTenantContext;

    app(TenantManager::class)->setTenantId('tenant-mock-uuid');
    expect(app(TenantManager::class)->getTenantId())->toBe('tenant-mock-uuid');

    $fakeEvent = (object) [
        'app' => app(),
        'sandbox' => app(),
    ];

    $listener->handle($fakeEvent);
    expect(app(TenantManager::class)->getTenantId())->toBeNull();

    app(TenantManager::class)->setTenantId('tenant-mock-uuid-2');
    $listener->handle(null);
    expect(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('handles event object cleanly in VerifyCleanDatabaseState', function (): void {
    $listener = new VerifyCleanDatabaseState;

    DB::connection();
    DB::beginTransaction();
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $fakeEvent = (object) [
        'app' => app(),
        'sandbox' => app(),
    ];

    $listener->handle($fakeEvent);
    expect(DB::transactionLevel())->toBe(0);
});
