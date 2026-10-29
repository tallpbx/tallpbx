<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantContextMissingException;
use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Extensions\Models\Extension;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->clear();
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('returns an empty collection and zero count on unscoped queries when tenant context is null', function (): void {
    Extension::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1001',
        'display_name' => 'Existing Extension',
    ]);

    expect(app(TenantManager::class)->getTenantId())->toBeNull();
    expect(Extension::query()->count())->toBe(0)
        ->and(Extension::query()->get())->toHaveCount(0);
});

it('throws TenantContextMissingException on creates when tenant context is null and no tenant_id is provided', function (): void {
    expect(app(TenantManager::class)->getTenantId())->toBeNull();

    expect(fn () => Extension::create([
        'extension_number' => '1002',
        'display_name' => 'Failing Extension',
    ]))->toThrow(
        TenantContextMissingException::class,
        'Cannot persist tenant-scoped model ['.Extension::class.'] without an active tenant context or explicit tenant_id.'
    );
});

it('allows creates when explicit tenant_id is provided even if tenant context is null', function (): void {
    expect(app(TenantManager::class)->getTenantId())->toBeNull();

    $extension = Extension::create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1003',
        'display_name' => 'Explicit Tenant Extension',
    ]);

    expect($extension->exists)->toBeTrue()
        ->and((int) $extension->tenant_id)->toBe($this->tenant->id);
});

it('throws TenantContextMissingException when updating an existing model if tenant_id is cleared', function (): void {
    $extension = Extension::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1004',
        'display_name' => 'Updatable Extension',
    ]);

    $extension->tenant_id = null;

    expect(fn () => $extension->save())->toThrow(
        TenantContextMissingException::class,
        'Cannot update tenant-scoped model ['.Extension::class.'] without an active tenant context or explicit tenant_id.'
    );
});

it('throws TenantContextMissingException when deleting an existing model if tenant_id is empty', function (): void {
    $extension = Extension::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1005',
        'display_name' => 'Deletable Extension',
    ]);

    $extension->tenant_id = null;

    expect(fn () => $extension->delete())->toThrow(
        TenantContextMissingException::class,
        'Cannot delete tenant-scoped model ['.Extension::class.'] without an active tenant context or explicit tenant_id.'
    );
});

it('renders TenantContextMissingException as 503 response for HTTP requests', function (): void {
    $exception = new TenantContextMissingException('Service temporarily unavailable.');
    $request = Request::create('/some-page', 'GET');

    $response = $exception->render($request);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(503);
});

it('renders TenantContextMissingException as 503 JSON for JSON requests', function (): void {
    $exception = new TenantContextMissingException('No tenant context available.');
    $request = Request::create('/api/resource', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = $exception->render($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(503)
        ->and($response->getData(true))->toBe([
            'message' => 'No tenant context available.',
        ]);
});
