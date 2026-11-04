<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Load-Test Report Verification
|--------------------------------------------------------------------------
|
| A measurement is only evidence when the artifact behind it is internally
| consistent. These checks prove the verifier catches a broken report rather
| than passing it: counters that do not add up, a missing latency sample, the
| wrong endpoint, the wrong label, and the wrong request count.
|
*/

const LOAD_TEST_VERIFY_REPORT_PATH = 'storage/framework/testing/load-tests/verify-report.json';

/**
 * Write a report fixture with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function writeLoadTestReport(array $overrides = []): string
{
    $report = array_replace_recursive([
        'run' => ['label' => 'h1-1c1g/v-stock-static/100x5-production-60-dialplan', 'git_commit' => str_repeat('a', 40)],
        'endpoint' => 'http://127.0.0.1/api/v1/xml-handler',
        'target' => ['total_requests' => 100, 'section' => 'dialplan'],
        'successful_responses' => 100,
        'failed_responses' => 0,
        'status_counts' => ['200' => 100],
        'latency_ms' => ['sample_count' => 100, 'missing_count' => 0],
    ], $overrides);

    File::ensureDirectoryExists(dirname(base_path(LOAD_TEST_VERIFY_REPORT_PATH)));
    File::put(base_path(LOAD_TEST_VERIFY_REPORT_PATH), (string) json_encode($report));

    return base_path(LOAD_TEST_VERIFY_REPORT_PATH);
}

describe('pbx load test report verification', function (): void {
    afterEach(function (): void {
        File::delete(base_path(LOAD_TEST_VERIFY_REPORT_PATH));
    });

    it('accepts a report whose counters add up', function (): void {
        $path = writeLoadTestReport();

        $this->artisan('pbx:load-test:verify', ['report' => $path])
            ->assertSuccessful();
    });

    it('rejects a report whose counters do not add up', function (): void {
        $path = writeLoadTestReport(['successful_responses' => 99]);

        $this->artisan('pbx:load-test:verify', ['report' => $path])
            ->assertFailed();
    });

    it('rejects a report whose status codes do not match the request count', function (): void {
        $path = writeLoadTestReport(['status_counts' => ['200' => 98]]);

        $this->artisan('pbx:load-test:verify', ['report' => $path])
            ->assertFailed();
    });

    it('rejects a report with no latency samples for successful responses', function (): void {
        $path = writeLoadTestReport(['latency_ms' => ['sample_count' => 0, 'missing_count' => 0]]);

        $this->artisan('pbx:load-test:verify', ['report' => $path])
            ->assertFailed();
    });

    it('rejects a report measured against the wrong endpoint', function (): void {
        $path = writeLoadTestReport();

        $this->artisan('pbx:load-test:verify', [
            'report' => $path,
            '--expect-endpoint' => 'http://127.0.0.1:8090/api/v1/xml-handler',
        ])->assertFailed();
    });

    it('rejects a report carrying the wrong label', function (): void {
        $path = writeLoadTestReport();

        $this->artisan('pbx:load-test:verify', [
            'report' => $path,
            '--expect-label' => 'h1-1c1g/v-octane',
        ])->assertFailed();
    });

    it('rejects a report that made the wrong number of requests', function (): void {
        $path = writeLoadTestReport();

        $this->artisan('pbx:load-test:verify', [
            'report' => $path,
            '--expect-requests' => 25,
        ])->assertFailed();
    });

    it('rejects a missing report file', function (): void {
        $this->artisan('pbx:load-test:verify', [
            'report' => base_path('storage/framework/testing/load-tests/does-not-exist.json'),
        ])->assertFailed();
    });
});
