<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Cdr\CdrPayloadParser;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| CDR Tenant Attribution
|--------------------------------------------------------------------------
|
| Ownership is decided only from call-time identity: the channel variable the
| dialplan stamped on the leg, or the tenant context the channel executed.
| A domain mapping is diagnostic context, never an ownership source: it can
| move after the call and can describe the caller on a cross-tenant B-leg.
|
*/

/**
 * Create the tenants used by the attribution cases.
 *
 * Tenant 1 owns "dedicated.test"; every tenant shares "shared.test".
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function cdrAttributionTenants(): array
{
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    foreach ([$first, $second] as $tenant) {
        TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'shared.test']);
    }

    TenantDomain::factory()->create(['tenant_id' => $first->id, 'domain' => 'dedicated.test']);

    return [$first, $second];
}

/**
 * Build one mod_json_cdr document with the given variable overrides.
 *
 * @param  array<string, mixed>  $variables
 */
function cdrAttributionPayload(array $variables = [], string $context = 'default'): string
{
    return (string) json_encode([
        'variables' => array_merge([
            'uuid' => (string) Str::uuid(),
            'direction' => 'outbound',
            'context' => $context,
        ], $variables),
        'callflow' => [[
            'caller_profile' => ['context' => $context],
        ]],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Parse one payload through a parser that quarantines into a throwaway dir.
 *
 * @param  array<string, mixed>  $variables
 * @return array<string, mixed>|null
 */
function cdrAttributionParse(array $variables = [], string $context = 'default'): ?array
{
    return (new CdrPayloadParser(sys_get_temp_dir().'/cdr-attribution-'.uniqid()))
        ->parse(cdrAttributionPayload($variables, $context));
}

dataset('cdr attribution', [
    'captured variable on a shared domain' => [2, 2],
    'captured variable on a dedicated domain' => [1, 1],
    'shared domain, no captured identity' => [null, null],
    'dedicated domain, no captured identity' => [null, null],
    'variable names a missing tenant' => [999999, null],
    'caller domain differs from captured B-leg tenant' => [2, 2],
]);

it('attributes a CDR cleanly and quarantines ambiguity or conflicts', function (?int $index, ?int $expected): void {
    [$first, $second] = cdrAttributionTenants();

    $tenant = match ($index) {
        1 => $first,
        2 => $second,
        default => null,
    };

    $row = cdrAttributionParse([
        'domain_name' => $index === 1 ? 'dedicated.test' : 'shared.test',
        ...($tenant === null ? [] : ['tenant_id' => (string) $tenant->id]),
    ]);

    if ($expected === null) {
        expect($row)->toBeNull();

        return;
    }

    expect($row)->not->toBeNull()
        ->and($row['tenant_id'])->toBe($tenant->id);
})->with('cdr attribution');

it('attributes an inbound call from its tenant dialplan context', function (): void {
    // Two tenants share every domain, so the domain alone is ambiguous and the
    // call arrives on an IP with the tenant's public context.
    [$first, $second] = cdrAttributionTenants();

    $row = cdrAttributionParse([
        'domain_name' => '10.0.0.9',
        'context' => 'tenant_'.$second->id.'_public',
    ], 'tenant_'.$second->id.'_public');

    expect($row)->not->toBeNull()
        ->and($row['tenant_id'])->toBe($second->id);
});

it('attributes an internal extension call from its tenant context', function (): void {
    [$first] = cdrAttributionTenants();

    // The legacy fixture stores the context only in the callflow profile.
    $payload = json_decode(cdrAttributionPayload([
        'domain_name' => '10.0.0.9',
    ], 'default'), true);
    $payload['variables']['context'] = 'tenant_'.$first->id.'_internal';
    unset($payload['callflow'][0]['caller_profile']['context']);

    $row = (new CdrPayloadParser(sys_get_temp_dir().'/cdr-attribution-'.uniqid()))
        ->parse((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    expect($row)->not->toBeNull()
        ->and($row['tenant_id'])->toBe($first->id);
});

it('treats a non-tenant context as absent, then requires another call-time identity', function (): void {
    [$first] = cdrAttributionTenants();

    expect(cdrAttributionParse([
        'domain_name' => 'dedicated.test',
        'context' => 'default',
        'tenant_id' => (string) $first->id,
    ], 'default')['tenant_id'])->toBe($first->id)
        ->and(cdrAttributionParse(['domain_name' => 'dedicated.test'], 'default'))->toBeNull();
});

it('quarantines a payload whose dialplan context and tenant variable disagree', function (): void {
    [$first, $second] = cdrAttributionTenants();

    expect(cdrAttributionParse([
        'tenant_id' => (string) $second->id,
        'context' => 'tenant_'.$first->id.'_public',
    ], 'tenant_'.$first->id.'_public'))->toBeNull();
});

it('keeps the callee tenant when a cross-tenant B-leg names the caller domain', function (): void {
    // The domain resolves only to the first tenant, but the direct bridge
    // stamped the second tenant on the B-leg, which never entered a tenant
    // dialplan context.
    [, $second] = cdrAttributionTenants();

    $row = cdrAttributionParse([
        'domain_name' => 'dedicated.test',
        'context' => 'default',
        'tenant_id' => (string) $second->id,
    ], 'default');

    expect($row)->not->toBeNull()
        ->and($row['tenant_id'])->toBe($second->id);
});

it('never assigns a delayed call to the new owner of its former domain', function (): void {
    // Capture a payload with no call-time identity while the first tenant owns
    // the domain, then move the domain to the second tenant before ingesting.
    [$first, $second] = cdrAttributionTenants();
    $payload = cdrAttributionPayload(['domain_name' => 'dedicated.test'], 'default');

    TenantDomain::query()
        ->where('domain', 'dedicated.test')
        ->update(['tenant_id' => $second->id]);

    expect((new CdrPayloadParser(sys_get_temp_dir().'/cdr-attribution-'.uniqid()))->parse($payload))->toBeNull();
});

it('quarantines the unmodified captured fixture until it carries call-time identity', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'capture.example']);
    $parser = new CdrPayloadParser(sys_get_temp_dir().'/cdr-attribution-'.uniqid());

    // The captured payload has context="default" and no tenant variable, so it
    // can only be quarantined; a copy carrying the call-time variable parses.
    expect($parser->parse(cdrFixture('a', ['domain_name' => 'capture.example'])))->toBeNull()
        ->and($parser->parse(cdrFixture('a', [
            'domain_name' => 'capture.example',
            'tenant_id' => (string) $tenant->id,
        ])))->not->toBeNull();
});
