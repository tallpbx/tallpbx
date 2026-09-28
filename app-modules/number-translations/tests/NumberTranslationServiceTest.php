<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

it('applies enabled rules in order and returns the rewritten number', function (): void {
    $tenant = Tenant::factory()->create();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'strip-nine',
        'match_pattern' => '^9(.+)$',
        'replace_pattern' => '$1',
        'direction' => 'outbound',
        'order' => 1,
        'enabled' => true,
    ]);
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'e164',
        'match_pattern' => '^(\d{10})$',
        'replace_pattern' => '1$1',
        'direction' => 'outbound',
        'order' => 2,
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $result = app(NumberTranslationServiceInterface::class)->translate('95551230000', 'outbound');

    expect($result)->toBe('15551230000');
});

it('filters rules by direction and tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'inbound-only',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'inbound',
        'order' => 1,
        'enabled' => true,
    ]);
    NumberTranslation::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'other-tenant',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '999',
        'direction' => 'both',
        'order' => 1,
        'enabled' => true,
    ]);

    // Inbound rules do not apply to outbound calls.
    app(TenantManager::class)->setTenantId((string) $tenantA->id);
    expect(app(NumberTranslationServiceInterface::class)->translate('8005551212', 'outbound'))->toBe('8005551212');

    // A rule in another tenant must not leak into this tenant's calls.
    expect(app(NumberTranslationServiceInterface::class)->translate('8005551212', 'inbound'))->toBe('15551230000');
});

it('skips rules with invalid regex without breaking the request', function (): void {
    $tenant = Tenant::factory()->create();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'broken',
        'match_pattern' => '(',
        'replace_pattern' => 'x',
        'direction' => 'both',
        'order' => 1,
        'enabled' => true,
    ]);
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'valid',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'both',
        'order' => 2,
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    expect(app(NumberTranslationServiceInterface::class)->translate('8005551212', 'inbound'))->toBe('15551230000');
});

it('persists the rule order through the service write path', function (): void {
    $tenant = Tenant::factory()->create();

    $rule = app(NumberTranslationServiceInterface::class)->create([
        'tenant_id' => $tenant->id,
        'name' => 'ordered',
        'match_pattern' => '^x$',
        'replace_pattern' => 'y',
        'direction' => 'both',
        'order' => 5,
        'enabled' => true,
    ]);

    expect($rule->fresh()->order)->toBe(5);
});
