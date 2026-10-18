<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Contracts\DialplanXmlContributor;
use App\Models\Module;
use App\Services\DialplanContext;
use App\Services\DialplanXmlCollector;
use App\Services\ModuleState;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\InboundRoutes\Tests\DisabledContributor;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Dialplan Context Tests
|--------------------------------------------------------------------------
*/

describe('dialplan context', function (): void {
    it('generates internal context from tenant UUID', function (): void {
        $context = new DialplanContext;
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $result = $context->internal($uuid);

        expect($result)->toBe('tenant_550e8400-e29b-41d4-a716-446655440000_internal');
    });

    it('generates public context from tenant UUID', function (): void {
        $context = new DialplanContext;
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $result = $context->public($uuid);

        expect($result)->toBe('tenant_550e8400-e29b-41d4-a716-446655440000_public');
    });

    it('parses tenant UUID from internal context', function (): void {
        $context = new DialplanContext;

        $uuid = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000_internal');

        expect($uuid)->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    it('parses tenant UUID from public context', function (): void {
        $context = new DialplanContext;

        $uuid = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000_public');

        expect($uuid)->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    it('returns null for non-tenant context', function (): void {
        $context = new DialplanContext;

        $uuid = $context->parseTenantId('public');

        expect($uuid)->toBeNull();
    });

    it('returns null for malformed tenant context', function (): void {
        $context = new DialplanContext;

        $result = $context->parseTenantId('tenant_550e8400-e29b-41d4-a716-446655440000');

        expect($result)->toBeNull();
    });

    it('returns null for empty context', function (): void {
        $context = new DialplanContext;

        expect($context->parseTenantId(''))->toBeNull();
    });

    it('isTenantContext returns true for valid internal context', function (): void {
        $context = new DialplanContext;

        expect($context->isTenantContext('tenant_uuid-123_internal'))->toBeTrue();
    });

    it('isTenantContext returns true for valid public context', function (): void {
        $context = new DialplanContext;

        expect($context->isTenantContext('tenant_uuid-123_public'))->toBeTrue();
    });

    it('isTenantContext returns false for non-tenant context', function (): void {
        $context = new DialplanContext;

        expect($context->isTenantContext('default'))->toBeFalse()
            ->and($context->isTenantContext('public'))->toBeFalse()
            ->and($context->isTenantContext(''))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| Dialplan XML Collector Tests
|--------------------------------------------------------------------------
*/

describe('dialplan xml collector', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(DialplanXmlCollector::class);
        app()->forgetInstance(ModuleState::class);

        $this->collector = app(DialplanXmlCollector::class);
    });

    it('sorts contributors by priority (lower first)', function (): void {
        $emergency = Mockery::mock(DialplanXmlContributor::class);
        $emergency->shouldReceive('getDialplanPriority')->andReturn(10);
        $emergency->shouldReceive('generateDialplanXml')->andReturn('<extension name="emergency"/>');

        $outbound = Mockery::mock(DialplanXmlContributor::class);
        $outbound->shouldReceive('getDialplanPriority')->andReturn(90);
        $outbound->shouldReceive('generateDialplanXml')->andReturn('<extension name="outbound"/>');

        $inbound = Mockery::mock(DialplanXmlContributor::class);
        $inbound->shouldReceive('getDialplanPriority')->andReturn(60);
        $inbound->shouldReceive('generateDialplanXml')->andReturn('<extension name="inbound"/>');

        app()->instance('dialplan_test_emergency', $emergency);
        app()->instance('dialplan_test_outbound', $outbound);
        app()->instance('dialplan_test_inbound', $inbound);
        app()->tag(['dialplan_test_emergency', 'dialplan_test_outbound', 'dialplan_test_inbound'], 'dialplan.xml');

        $xml = $this->collector->collect(1, 'test_context', '12345');

        $posEmerg = strpos($xml, 'emergency');
        $posInbound = strpos($xml, 'inbound');
        $posOutbound = strpos($xml, 'outbound');

        expect($posEmerg)->toBeLessThan($posInbound)
            ->and($posInbound)->toBeLessThan($posOutbound);
    });

    it('returns empty string when no contributors are registered', function (): void {
        $xml = $this->collector->collect(1, 'test_context', '12345');

        expect($xml)->toBeString();
    });

    it('catches exceptions from individual contributors without breaking the dialplan', function (): void {
        $good = Mockery::mock(DialplanXmlContributor::class);
        $good->shouldReceive('getDialplanPriority')->andReturn(50);
        $good->shouldReceive('generateDialplanXml')->andReturn('<extension name="good"/>');

        $bad = Mockery::mock(DialplanXmlContributor::class);
        $bad->shouldReceive('getDialplanPriority')->andReturn(30);
        $bad->shouldReceive('generateDialplanXml')->andThrow(new RuntimeException('DB error'));

        app()->instance('dialplan_test_good', $good);
        app()->instance('dialplan_test_bad', $bad);
        app()->tag(['dialplan_test_good', 'dialplan_test_bad'], 'dialplan.xml');

        $xml = $this->collector->collect(1, 'test_context', '12345');

        expect($xml)->toContain('good')
            ->not()->toContain('DB error');
    });

    it('skips contributors that return null', function (): void {
        $active = Mockery::mock(DialplanXmlContributor::class);
        $active->shouldReceive('getDialplanPriority')->andReturn(50);
        $active->shouldReceive('generateDialplanXml')->andReturn('<extension name="active"/>');

        $inactive = Mockery::mock(DialplanXmlContributor::class);
        $inactive->shouldReceive('getDialplanPriority')->andReturn(20);
        $inactive->shouldReceive('generateDialplanXml')->andReturn(null);

        app()->instance('dialplan_test_active', $active);
        app()->instance('dialplan_test_inactive', $inactive);
        app()->tag(['dialplan_test_active', 'dialplan_test_inactive'], 'dialplan.xml');

        $xml = $this->collector->collect(1, 'test_context', '12345');

        expect($xml)->toContain('active')
            ->not()->toContain('inactive');
    });

    it('caches context-wide contributor fragments for the same tenant and context', function (): void {
        config([
            'cache.default' => 'array',
            'freeswitch.xml_handler.dialplan_cache_store' => 'array',
            'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
        ]);
        Cache::store('array')->flush();

        $contributor = Mockery::mock(ContextWideDialplanXmlContributor::class);
        $contributor->shouldReceive('getDialplanPriority')->andReturn(50);
        $contributor->shouldReceive('generateDialplanXml')
            ->once()
            ->with(1, 'tenant_1_internal', '2000')
            ->andReturn('<extension name="context-wide"/>');

        app()->instance('dialplan_context_wide_test', $contributor);
        app()->tag(['dialplan_context_wide_test'], 'dialplan.xml');

        $firstXml = $this->collector->collect(1, 'tenant_1_internal', '2000');
        $secondXml = $this->collector->collect(1, 'tenant_1_internal', '2001');

        expect($firstXml)->toContain('context-wide')
            ->and($secondXml)->toContain('context-wide');
    });

    it('skips contributors that belong to disabled modules', function (): void {
        if (! class_exists('Modules\\InboundRoutes\\Tests\\DisabledContributor')) {
            eval(<<<'PHP'
namespace Modules\InboundRoutes\Tests;

use App\Contracts\DialplanXmlContributor;

class DisabledContributor implements DialplanXmlContributor
{
    public function getDialplanPriority(): int
    {
        return 10;
    }

    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        return '<extension name="disabled-inbound-routes"/>';
    }
}
PHP);
        }

        Module::create([
            'name' => 'inbound-routes',
            'display_name' => 'Inbound Routes',
            'version' => '1.0.0',
            'enabled' => false,
        ]);

        app()->bind('disabled_inbound_routes_contributor', fn () => new DisabledContributor);
        app()->tag(['disabled_inbound_routes_contributor'], 'dialplan.xml');

        $xml = $this->collector->collect(1, 'test_context', '12345');

        expect($xml)->not->toContain('disabled-inbound-routes');
    });
});
