<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\TenantDefaultsService;
use Modules\Dialplans\Models\Dialplan;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\SipProfiles\Models\SipProfile;

it('provisions default PBX records for a tenant', function () {
    $tenant = Tenant::factory()->create();

    $summary = app(TenantDefaultsService::class)->provision($tenant);

    expect($summary)
        ->toHaveKey('sip_profiles')
        ->toHaveKey('dialplans')
        ->toHaveKey('feature_codes')
        ->toHaveKey('music_on_hold')
        ->and($summary['sip_profiles']['created'])->toBe(2)
        ->and($summary['dialplans']['created'])->toBeGreaterThanOrEqual(4)
        ->and($summary['feature_codes']['created'])->toBeGreaterThanOrEqual(17)
        ->and($summary['music_on_hold']['created'])->toBe(2);

    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    expect(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', '*97')->exists())->toBeTrue()
        ->and(MusicOnHold::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'default')->exists())->toBeTrue()
        ->and(Dialplan::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('context', $internalContext)->exists())->toBeTrue();

    $internalProfile = SipProfile::withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->where('name', 'internal')
        ->firstOrFail();

    expect($internalProfile->settings)
        ->toHaveKey('sip-port', '5060')
        ->toHaveKey('sip-ip', '$${local_ip_v4}')
        ->toHaveKey('dtmf-type', 'rfc2833');
});

it('is idempotent for repeated provisioning', function () {
    $tenant = Tenant::factory()->create();

    app(TenantDefaultsService::class)->provision($tenant);
    $secondRun = app(TenantDefaultsService::class)->provision($tenant);

    expect($secondRun['sip_profiles']['created'])->toBe(0)
        ->and($secondRun['dialplans']['created'])->toBe(0)
        ->and($secondRun['feature_codes']['created'])->toBe(0)
        ->and($secondRun['music_on_hold']['created'])->toBe(0)
        ->and(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', '*97')->count())->toBe(1);
});

it('creates isolated defaults for separate tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(TenantDefaultsService::class)->provision($tenantA);
    app(TenantDefaultsService::class)->provision($tenantB);

    expect(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->count())->toBe(2)
        ->and(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->count())->toBe(2)
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->where('code', '*97')->count())->toBe(1)
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->where('code', '*97')->count())->toBe(1);
});
