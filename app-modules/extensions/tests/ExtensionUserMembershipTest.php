<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionServiceInterface;

/*
 * Assigning a portal user to an extension is a cross-tenant write unless the
 * user belongs to the extension's own tenant. The boundary is enforced in the
 * service, so direct callers are bound by it too, not only the Livewire rule.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    $this->extension = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '301',
        'enabled' => true,
    ]);

    $this->member = User::factory()->create(['enabled' => true]);
    $this->member->tenants()->attach($this->tenant->id);

    $this->sharedUser = User::factory()->create(['enabled' => true]);
    $this->sharedUser->tenants()->attach([$this->tenant->id, $this->otherTenant->id]);

    $this->foreignUser = User::factory()->create(['enabled' => true]);
    $this->foreignUser->tenants()->attach($this->otherTenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('rejects a user who belongs only to another tenant', function (): void {
    expect(fn () => app(ExtensionServiceInterface::class)->syncUsers($this->extension, [$this->foreignUser->id]))
        ->toThrow(ValidationException::class);

    expect(DB::table('extension_user')->where('extension_id', $this->extension->id)->count())->toBe(0);
});

it('accepts a member of the extension tenant and a user shared with two tenants', function (): void {
    app(ExtensionServiceInterface::class)->syncUsers($this->extension, [$this->member->id, $this->sharedUser->id]);

    expect($this->extension->fresh('users')->users)->toHaveCount(2);
});

it('refuses a mixed selection instead of silently dropping the foreign user', function (): void {
    expect(fn () => app(ExtensionServiceInterface::class)->syncUsers($this->extension, [
        $this->member->id,
        $this->foreignUser->id,
    ]))->toThrow(ValidationException::class);
});
