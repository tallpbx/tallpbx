<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use App\Console\Commands\PbxDialplanLoadTestCommand;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Modules\CallCenters\Models\Queue;
use Modules\Extensions\Models\Extension;
use Modules\Gateways\Models\Gateway;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\SipAccounts\Models\SipAccount;
use ReflectionMethod;
use Symfony\Component\Process\Process;

const PBX_LOAD_TEST_CSV_PATH = 'storage/framework/testing/load-tests/load-validation-sipp-users.csv';
const PBX_LOAD_TEST_REPORT_PATH = 'storage/framework/testing/load-tests/load-validation-report.json';

/*
|--------------------------------------------------------------------------
| Cache Sweep and SIPp Script Validation
|--------------------------------------------------------------------------
*/

describe('pbx load test scripts and scenarios', function (): void {
    it('keeps the cache sweep runner syntactically valid', function (): void {
        $script = base_path('scripts/run-cache-sweep.sh');
        $process = new Process(['bash', '-n', $script], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    });

    it('defines the 5 standard cache sweep tiers in the runner', function (): void {
        $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

        expect($script)->toContain('cold-baseline')
            ->and($script)->toContain('contributor-only')
            ->and($script)->toContain('prod-baseline-100')
            ->and($script)->toContain('call-center-ttl30')
            ->and($script)->toContain('memory-hit-ceiling');
    });

    it('inspects Redis stats and calculates keyspace hit rates', function (): void {
        $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

        expect($script)->toContain('redis-cli info stats')
            ->and($script)->toContain('keyspace_hits')
            ->and($script)->toContain('keyspace_misses')
            ->and($script)->toContain('Hit Rate');
    });

    it('safely restores production cache defaults on exit', function (): void {
        $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

        expect($script)->toContain('trap restore_defaults EXIT')
            ->and($script)->toContain('FS_XML_HANDLER_CACHE_TTL=5');
    });

    it('formats output with average latency instead of percentiles', function (): void {
        $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

        expect($script)->toContain('Avg (ms)')
            ->and($script)->toContain('"average"')
            ->and($script)->not->toContain('p50');
    });

    it('keeps the SIPp end-to-end validation runner syntactically valid', function (): void {
        $script = base_path('scripts/pbx-sipp-validate.sh');
        $process = new Process(['bash', '-n', $script], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    });

    it('documents the expected SIPp scenarios in the validation runner', function (): void {
        $script = (string) file_get_contents(base_path('scripts/pbx-sipp-validate.sh'));

        expect($script)->toContain('tools/sipp/register.xml')
            ->and($script)->toContain('tools/sipp/uac-extension.xml')
            ->and($script)->toContain('tools/sipp/uac-outbound.xml')
            ->and($script)->toContain('tools/sipp/uas-auto-answer.xml')
            ->and($script)->toContain('tools/sipp/uac-media-client-hangup.xml')
            ->and($script)->toContain('tools/sipp/uac-media-server-hangup.xml')
            ->and($script)->toContain('MEDIA_FLOW')
            ->and($script)->toContain('--include-media-fixtures')
            ->and($script)->toContain('-mi')
            ->and($script)->toContain('-mp')
            ->and($script)->toContain('pbx:load-test:seed')
            ->and($script)->toContain('sipp-users-auth.csv')
            ->and($script)->toContain('trace_counts')
            ->and($script)->toContain('summary.md');
    });

    it('keeps custom SIPp XML scenarios well formed', function (string $scenario): void {
        $path = base_path("tools/sipp/{$scenario}");
        $document = simplexml_load_file($path);

        expect($document)->not->toBeFalse();
    })->with([
        'register.xml',
        'register-uas-auto-answer.xml',
        'uac-call-block.xml',
        'uac-call-forward.xml',
        'uac-conference.xml',
        'uac-emergency.xml',
        'uac-extension-capacity.xml',
        'uac-extension.xml',
        'uac-follow-me.xml',
        'uac-media-client-hangup.xml',
        'uac-media-server-hangup.xml',
        'uac-outbound.xml',
        'uac-ring-group.xml',
        'uac-time-condition.xml',
        'uac-voicemail.xml',
        'uas-auto-answer-capacity.xml',
        'uas-auto-answer.xml',
    ]);

    it('measures end-to-end call setup time in the capacity scenario', function (): void {
        $scenario = (string) file_get_contents(base_path('tools/sipp/uac-extension-capacity.xml'));

        expect($scenario)->toContain('start_rtd="setup"')
            ->and($scenario)->toContain('rtd="setup"')
            ->and($scenario)->toContain('<pause/>');
    });

    it('keeps the capacity destination duration configurable', function (): void {
        $scenario = (string) file_get_contents(base_path('tools/sipp/uas-auto-answer-capacity.xml'));

        expect($scenario)->toContain('<pause/>')
            ->and($scenario)->toContain('<recv request="BYE"/>');
    });
});

/*
|--------------------------------------------------------------------------
| PBX Load Test Seeding Command
|--------------------------------------------------------------------------
*/

describe('pbx load test seed command', function (): void {
    beforeEach(function (): void {
        File::delete(base_path(PBX_LOAD_TEST_CSV_PATH));
    });

    afterEach(function (): void {
        File::delete(base_path(PBX_LOAD_TEST_CSV_PATH));
    });

    it('creates repeatable PBX load test data and a SIPp CSV', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-feature',
            '--domain' => 'load-feature.test',
            '--extensions' => '4',
            '--start' => '3000',
            '--password' => 'Secret1234!',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ])->assertSuccessful();

        $tenant = Tenant::where('slug', 'load-test-feature')->firstOrFail();
        $domain = TenantDomain::where('tenant_id', $tenant->id)->where('domain', 'load-feature.test')->firstOrFail();
        $gateway = Gateway::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'sipp-load-uac')->firstOrFail();
        $outboundRoute = OutboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'SIPp outbound 9-prefix')->firstOrFail();

        expect(Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(4)
            ->and(SipAccount::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(4)
            ->and(InboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('destination_number', '15551230000')->exists())->toBeTrue()
            ->and($outboundRoute->gateway_id)->toBeNull()
            ->and($outboundRoute->gateway)->toBe('sofia/external/$1@127.0.0.1:5088')
            ->and($domain->purpose)->toBe('sip_realm');

        $csv = (string) file_get_contents(base_path(PBX_LOAD_TEST_CSV_PATH));

        expect($csv)->toStartWith("SEQUENTIAL\n")
            ->and($csv)->toContain('3000;Secret1234!;load-feature.test;3001;3000')
            ->and($csv)->toContain('3003;Secret1234!;load-feature.test;3000;3003');
    });

    it('resets only the named synthetic tenant before recreating data', function (): void {
        $otherTenant = Tenant::factory()->create(['slug' => 'real-tenant']);

        $baseOptions = [
            '--tenant' => 'load-test-feature',
            '--domain' => 'load-feature.test',
            '--extensions' => '3',
            '--start' => '3100',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ];

        $this->artisan('pbx:load-test:seed', $baseOptions)->assertSuccessful();
        $firstTenantId = Tenant::where('slug', 'load-test-feature')->value('id');

        $this->artisan('pbx:load-test:seed', [...$baseOptions, '--reset' => true])->assertSuccessful();
        $secondTenantId = Tenant::where('slug', 'load-test-feature')->value('id');

        expect($secondTenantId)->not->toBe($firstTenantId)
            ->and(Tenant::whereKey($otherTenant->id)->exists())->toBeTrue()
            ->and(Extension::withoutGlobalScope('tenant')->where('tenant_id', $secondTenantId)->count())->toBe(3);
    });

    it('can seed optional media-flow fixtures for SIPp RTP validation', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-media',
            '--domain' => 'load-media.test',
            '--extensions' => '3',
            '--start' => '3200',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
            '--include-media-fixtures' => true,
        ])->assertSuccessful();

        $tenant = Tenant::where('slug', 'load-test-media')->firstOrFail();
        $queue = Queue::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('name', 'load_test_moh')
            ->firstOrFail();
        $menu = IvrMenu::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('name', 'load_test_announcement')
            ->firstOrFail();

        expect($queue->music_on_hold)->toBe('local_stream://moh')
            ->and($queue->enabled)->toBeTrue()
            ->and($menu->greeting)->toBe('$${sounds_dir}/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav')
            ->and($menu->timeout)->toBe(3)
            ->and($menu->enabled)->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| PBX Dialplan Load Test Command
|--------------------------------------------------------------------------
*/

describe('pbx dialplan load test command', function (): void {
    beforeEach(function (): void {
        File::delete(base_path(PBX_LOAD_TEST_CSV_PATH));
        File::delete(base_path(PBX_LOAD_TEST_REPORT_PATH));
    });

    afterEach(function (): void {
        File::delete(base_path(PBX_LOAD_TEST_CSV_PATH));
        File::delete(base_path(PBX_LOAD_TEST_REPORT_PATH));
    });

    it('load tests the dynamic dialplan XML handler endpoint', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-dialplan',
            '--domain' => 'dialplan-load.test',
            '--extensions' => '4',
            '--start' => '4100',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ])->assertSuccessful();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $context = $query['Caller-Context'] ?? 'missing';

            return Http::response(
                '<document type="freeswitch/xml"><section name="dialplan"><context name="'.$context.'"></context></section></document>',
                200,
                ['Content-Type' => 'application/xml'],
            );
        });

        $this->artisan('pbx:load-test:dialplan', [
            '--tenant' => 'load-test-dialplan',
            '--url' => 'https://pbx.test/api/v1/xml-handler',
            '--requests' => '6',
            '--concurrency' => '3',
            '--scenario' => 'mixed',
            '--report' => PBX_LOAD_TEST_REPORT_PATH,
        ])->assertSuccessful();

        Http::assertSentCount(6);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'section=dialplan')
            && str_contains($request->url(), 'Caller-Context=tenant_')
            && str_contains($request->url(), '_internal')
            && str_contains($request->url(), 'Caller-Destination-Number=4101'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '_public')
            && str_contains($request->url(), 'Caller-Destination-Number=15551230000'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '_internal')
            && str_contains($request->url(), 'Caller-Destination-Number=91555123000'));

        $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);

        expect($report['total_requests'])->toBe(6)
            ->and($report['successful_responses'])->toBe(6)
            ->and($report['failed_responses'])->toBe(0)
            ->and($report['scenario'])->toBe('mixed')
            ->and($report['passed'])->toBeTrue()
            ->and($report['success_rate_percent'])->toEqual(100.0)
            ->and($report['failure_rate_percent'])->toEqual(0.0)
            ->and($report['run']['id'])->toBeString()->not->toBeEmpty()
            ->and($report['run']['git_commit'])->toBeString()->not->toBeEmpty()
            ->and($report['environment']['php_version'])->toBe(PHP_VERSION)
            ->and($report['environment']['laravel_version'])->toBe(app()->version())
            ->and($report['target']['scenario_counts'])->toBe([
                'internal' => 2,
                'inbound' => 2,
                'outbound' => 2,
            ])
            ->and(array_keys($report['latency_ms']))->toBe([
                'sample_count',
                'missing_count',
                'average',
                'min',
                'max',
                'p50',
                'p90',
                'p95',
                'p99',
                'std_dev',
                'raw_samples',
            ])
            ->and($report['latency_ms']['raw_samples'])->toBeArray()
            ->and($report['latency_ms']['raw_samples'])->toHaveCount(6)
            ->and($report['latency_ms']['raw_samples'][0])->toBe($report['latency_ms']['min'])
            ->and(end($report['latency_ms']['raw_samples']))->toBe($report['latency_ms']['max'])
            ->and($report['latency_ms']['sample_count'] + $report['latency_ms']['missing_count'])->toBe(6)
            ->and($report['latency_ms']['average'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['min'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['max'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['p50'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['p90'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['p95'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['p99'])->toBeGreaterThanOrEqual(0)
            ->and($report['latency_ms']['std_dev'])->toBeGreaterThanOrEqual(0)
            ->and(array_keys($report['thresholds']))->toBe([
                'max_failure_rate_percent',
                'max_average_ms',
            ])
            ->and($report['thresholds']['max_failure_rate_percent'])->toEqual(0.0)
            ->and($report['thresholds']['max_average_ms'])->toBeNull()
            ->and($report['threshold_results'])->toBe([]);
    });

    it('can calculate percentiles like p50, p90, p95, p99, and standard deviation from latency data', function (): void {
        $command = app(PbxDialplanLoadTestCommand::class);
        $rawSamples = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0];

        expect($command->percentile($rawSamples, 50))->toBe(50.0)
            ->and($command->percentile($rawSamples, 90))->toBe(90.0)
            ->and($command->percentile($rawSamples, 95))->toBe(100.0)
            ->and($command->percentile($rawSamples, 99))->toBe(100.0)
            ->and($command->standardDeviation($rawSamples))->toBe(30.277);
    });

    it('can repeat one dialplan request to measure cache-hit performance', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-cache-hit',
            '--domain' => 'cache-hit-load.test',
            '--extensions' => '4',
            '--start' => '4200',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ])->assertSuccessful();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $context = $query['Caller-Context'] ?? 'missing';

            return Http::response(
                '<document type="freeswitch/xml"><section name="dialplan"><context name="'.$context.'"></context></section></document>',
                200,
                ['Content-Type' => 'application/xml'],
            );
        });

        $this->artisan('pbx:load-test:dialplan', [
            '--tenant' => 'load-test-cache-hit',
            '--url' => 'https://pbx.test/api/v1/xml-handler',
            '--requests' => '5',
            '--concurrency' => '2',
            '--scenario' => 'cache-hit',
            '--report' => PBX_LOAD_TEST_REPORT_PATH,
        ])->assertSuccessful();

        Http::assertSentCount(5);

        Http::assertSent(function (Request $request): bool {
            $query = parse_url($request->url(), PHP_URL_QUERY);

            return is_string($query)
                && str_contains($query, 'Caller-Destination-Number=4201')
                && str_contains($query, 'Caller-Caller-ID-Number=4200')
                && str_contains($query, '_internal');
        });

        $sentUrls = collect(Http::recorded())
            ->map(fn (array $record): string => $record[0]->url())
            ->values();

        expect($sentUrls->unique()->count())->toBe(1);

        $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);

        expect($report['scenario'])->toBe('cache-hit')
            ->and($report['successful_responses'])->toBe(5);
    });

    it('fails when the load test tenant has not been seeded', function (): void {
        Http::fake();

        $this->artisan('pbx:load-test:dialplan', [
            '--tenant' => 'missing-load-tenant',
            '--url' => 'https://pbx.test/api/v1/xml-handler',
            '--report' => PBX_LOAD_TEST_REPORT_PATH,
        ])->assertFailed();

        Http::assertNothingSent();
    });

    it('fails when measured results exceed configured reporting thresholds', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-thresholds',
            '--domain' => 'threshold-load.test',
            '--extensions' => '4',
            '--start' => '4300',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ])->assertSuccessful();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $context = $query['Caller-Context'] ?? 'missing';

            if (str_contains($request->url(), 'Caller-Destination-Number=4302')) {
                return Http::response('<document type="freeswitch/xml"></document>', 200);
            }

            return Http::response(
                '<document type="freeswitch/xml"><section name="dialplan"><context name="'.$context.'"></context></section></document>',
                200,
                ['Content-Type' => 'application/xml'],
            );
        });

        $this->artisan('pbx:load-test:dialplan', [
            '--tenant' => 'load-test-thresholds',
            '--url' => 'https://pbx.test/api/v1/xml-handler',
            '--requests' => '4',
            '--concurrency' => '2',
            '--scenario' => 'internal',
            '--max-failure-rate' => '10',
            '--report' => PBX_LOAD_TEST_REPORT_PATH,
        ])->assertFailed();

        $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);

        expect($report['passed'])->toBeFalse()
            ->and($report['failed_responses'])->toBe(1)
            ->and($report['failure_rate_percent'])->toEqual(25.0)
            ->and($report['threshold_results'])->toContain('Failure rate 25% exceeded allowed 10%.');
    });

    it('fails when average latency exceeds its configured threshold', function (): void {
        $command = app(PbxDialplanLoadTestCommand::class);
        $thresholdResults = new ReflectionMethod($command, 'thresholdResults');
        $thresholdResults->setAccessible(true);

        $failures = $thresholdResults->invoke(
            $command,
            0.0,
            [
                'sample_count' => 3,
                'missing_count' => 0,
                'average' => 125.0,
                'min' => 75.0,
                'max' => 200.0,
            ],
            [
                'max_failure_rate_percent' => 0.0,
                'max_average_ms' => 100.0,
            ],
        );

        expect($failures)->toContain('Average latency 125 ms exceeded allowed 100 ms.');
    });

    it('writes the report to an absolute path without doubling the base path', function (): void {
        $this->artisan('pbx:load-test:seed', [
            '--tenant' => 'load-test-dialplan',
            '--domain' => 'dialplan-load.test',
            '--extensions' => '4',
            '--start' => '4100',
            '--output' => PBX_LOAD_TEST_CSV_PATH,
        ])->assertSuccessful();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $context = $query['Caller-Context'] ?? 'missing';

            return Http::response(
                '<document type="freeswitch/xml"><section name="dialplan"><context name="'.$context.'"></context></section></document>',
                200,
                ['Content-Type' => 'application/xml'],
            );
        });

        $absoluteReport = storage_path('framework/testing/load-tests/absolute-report.json');
        $doubledReport = base_path($absoluteReport);

        File::delete($absoluteReport);
        File::delete($doubledReport);

        $this->artisan('pbx:load-test:dialplan', [
            '--tenant' => 'load-test-dialplan',
            '--url' => 'https://pbx.test/api/v1/xml-handler',
            '--requests' => '3',
            '--concurrency' => '1',
            '--scenario' => 'mixed',
            '--report' => $absoluteReport,
        ])->assertSuccessful();

        expect(File::exists($absoluteReport))->toBeTrue()
            ->and(File::exists($doubledReport))->toBeFalse();

        File::delete($absoluteReport);
        File::delete($doubledReport);
    });
});
