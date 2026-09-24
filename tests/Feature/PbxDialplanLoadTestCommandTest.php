<?php

declare(strict_types=1);

use App\Console\Commands\PbxDialplanLoadTestCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH = 'storage/framework/testing/load-tests/dialplan-command-sipp-users.csv';
const PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH = 'storage/framework/testing/load-tests/dialplan-command-report.json';

beforeEach(function () {
    File::delete(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH));
    File::delete(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH));
});

afterEach(function () {
    File::delete(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH));
    File::delete(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH));
});

it('load tests the dynamic dialplan XML handler endpoint', function () {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-dialplan',
        '--domain' => 'dialplan-load.test',
        '--extensions' => '4',
        '--start' => '4100',
        '--output' => PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH,
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
        '--report' => PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH,
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

    $report = json_decode((string) file_get_contents(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH)), true);

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

it('can calculate percentiles like p50, p90, p95, p99, and standard deviation from latency data', function () {
    $command = app(PbxDialplanLoadTestCommand::class);
    $rawSamples = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0];

    expect($command->percentile($rawSamples, 50))->toBe(50.0)
        ->and($command->percentile($rawSamples, 90))->toBe(90.0)
        ->and($command->percentile($rawSamples, 95))->toBe(100.0)
        ->and($command->percentile($rawSamples, 99))->toBe(100.0)
        ->and($command->standardDeviation($rawSamples))->toBe(30.277);
});

it('can repeat one dialplan request to measure cache-hit performance', function () {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-cache-hit',
        '--domain' => 'cache-hit-load.test',
        '--extensions' => '4',
        '--start' => '4200',
        '--output' => PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH,
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
        '--report' => PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH,
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

    $report = json_decode((string) file_get_contents(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH)), true);

    expect($report['scenario'])->toBe('cache-hit')
        ->and($report['successful_responses'])->toBe(5);
});

it('fails when the load test tenant has not been seeded', function () {
    Http::fake();

    $this->artisan('pbx:load-test:dialplan', [
        '--tenant' => 'missing-load-tenant',
        '--url' => 'https://pbx.test/api/v1/xml-handler',
        '--report' => PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH,
    ])->assertFailed();

    Http::assertNothingSent();
});

it('fails when measured results exceed configured reporting thresholds', function () {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-thresholds',
        '--domain' => 'threshold-load.test',
        '--extensions' => '4',
        '--start' => '4300',
        '--output' => PBX_DIALPLAN_LOAD_TEST_COMMAND_CSV_PATH,
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
        '--report' => PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH,
    ])->assertFailed();

    $report = json_decode((string) file_get_contents(base_path(PBX_DIALPLAN_LOAD_TEST_COMMAND_REPORT_PATH)), true);

    expect($report['passed'])->toBeFalse()
        ->and($report['failed_responses'])->toBe(1)
        ->and($report['failure_rate_percent'])->toEqual(25.0)
        ->and($report['threshold_results'])->toContain('Failure rate 25% exceeded allowed 10%.');
});

it('fails when average latency exceeds its configured threshold', function () {
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
