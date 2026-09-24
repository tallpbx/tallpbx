<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DialplanContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Extensions\Models\Extension;

/**
 * Sends concurrent HTTP requests to the FreeSWITCH XML handler dialplan path.
 *
 * This command measures the Laravel-generated dialplan XML path that
 * FreeSWITCH reaches through mod_xml_curl. It is intended for beta load
 * testing against a real web server endpoint, not php artisan serve.
 */
#[Signature('pbx:load-test:dialplan
    {--tenant=load-test-beta : Tenant slug to use for dialplan requests}
    {--url= : Full XML handler URL; defaults to APP_URL plus /api/v1/xml-handler}
    {--requests=100 : Total dialplan XML requests to send}
    {--concurrency=10 : Maximum concurrent XML handler requests}
    {--scenario=mixed : Scenario: internal, inbound, outbound, mixed, or cache-hit}
    {--timeout=10 : Per-request timeout in seconds}
    {--token= : Optional XML handler bearer token}
    {--label= : Optional human-readable label stored in the JSON report}
    {--max-failure-rate=0 : Maximum failed-response percentage allowed before the command fails}
    {--max-average-ms= : Optional maximum average latency in milliseconds before the command fails}
    {--report=storage/app/load-tests/dialplan-report.json : JSON report output path}')]
#[Description('Load test the Laravel-generated FreeSWITCH dialplan XML handler')]
class PbxDialplanLoadTestCommand extends Command
{
    /**
     * Load test dynamic dialplan generation through the XML handler endpoint.
     */
    public function handle(DialplanContext $dialplanContext): int
    {
        $tenant = Tenant::where('slug', $this->stringOption('tenant'))->first();

        if (! $tenant instanceof Tenant) {
            $this->components->error('Tenant not found. Run pbx:load-test:seed first or pass --tenant.');

            return self::FAILURE;
        }

        $extensions = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('enabled', true)
            ->orderBy('extension_number')
            ->pluck('extension_number')
            ->all();

        if (count($extensions) < 2) {
            $this->components->error('At least two enabled extensions are required for dialplan load testing.');

            return self::FAILURE;
        }

        $requestCount = $this->integerOption('requests', minimum: 1, maximum: 1000000);
        $concurrency = $this->integerOption('concurrency', minimum: 1, maximum: 1000);
        $timeout = $this->integerOption('timeout', minimum: 1, maximum: 300);
        $scenario = $this->scenarioOption();
        $url = $this->xmlHandlerUrl();
        $token = $this->option('token');
        $label = $this->nullableStringOption('label');
        $thresholds = [
            'max_failure_rate_percent' => $this->floatOption('max-failure-rate', minimum: 0, maximum: 100),
            'max_average_ms' => $this->nullableFloatOption('max-average-ms', minimum: 0),
        ];
        $reportPath = $this->stringOption('report');

        $queries = $this->buildQueries(
            requestCount: $requestCount,
            scenario: $scenario,
            tenantId: (string) $tenant->id,
            extensions: $extensions,
            dialplanContext: $dialplanContext,
        );

        $this->components->info('Running dynamic dialplan XML load test.');
        $this->components->twoColumnDetail('Endpoint', $url);
        $this->components->twoColumnDetail('Tenant', "{$tenant->slug} ({$tenant->id})");
        $this->components->twoColumnDetail('Scenario', $scenario);
        $this->components->twoColumnDetail('Requests', (string) $requestCount);
        $this->components->twoColumnDetail('Concurrency', (string) $concurrency);

        $started = hrtime(true);
        $results = $this->sendRequests($url, $queries, $concurrency, $timeout, is_string($token) && $token !== '' ? $token : null);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $report = $this->buildReport(
            tenant: $tenant,
            scenario: $scenario,
            url: $url,
            requestCount: $requestCount,
            concurrency: $concurrency,
            timeout: $timeout,
            label: $label,
            thresholds: $thresholds,
            elapsedMs: $elapsedMs,
            results: $results,
        );
        $this->writeReport($reportPath, $report);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total requests', (string) $report['total_requests']],
                ['Successful responses', (string) $report['successful_responses']],
                ['Failed responses', (string) $report['failed_responses']],
                ['Elapsed seconds', (string) $report['elapsed_seconds']],
                ['Requests/sec', (string) $report['requests_per_second']],
                ['Average ms', (string) $report['latency_ms']['average']],
                ['Fastest ms', (string) $report['latency_ms']['min']],
                ['Slowest ms', (string) $report['latency_ms']['max']],
                ['Passed thresholds', $report['passed'] ? 'yes' : 'no'],
                ['Report', base_path($reportPath)],
            ],
        );

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Send XML handler requests in concurrent chunks.
     *
     * @param  array<int, array<string, string>>  $queries
     * @return array<int, array{ok: bool, status: int|null, milliseconds: float|null, error: string|null}>
     */
    private function sendRequests(string $url, array $queries, int $concurrency, int $timeout, ?string $token): array
    {
        $results = [];

        foreach (array_chunk($queries, $concurrency, preserve_keys: true) as $chunk) {
            $timings = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $url, $timeout, $token, &$timings): array {
                    $requests = [];

                    foreach ($chunk as $key => $query) {
                        $pending = $pool->as((string) $key)
                            ->timeout($timeout)
                            ->accept('application/xml')
                            ->withOptions([
                                'on_stats' => function ($stats) use (&$timings, $key): void {
                                    if (method_exists($stats, 'getTransferTime')) {
                                        $timings[$key] = (float) $stats->getTransferTime() * 1000;
                                    }
                                },
                            ]);

                        if ($token !== null) {
                            $pending = $pending->withToken($token);
                        }

                        $requests[] = $pending->get($url, $query);
                    }

                    return $requests;
                }, concurrency: $concurrency);
            } catch (ConnectionException $exception) {
                foreach (array_keys($chunk) as $key) {
                    $results[$key] = [
                        'ok' => false,
                        'status' => null,
                        'milliseconds' => null,
                        'error' => $exception->getMessage(),
                    ];
                }

                continue;
            }

            foreach ($chunk as $key => $query) {
                $response = $responses[(string) $key] ?? null;

                if (! $response instanceof Response) {
                    $results[$key] = [
                        'ok' => false,
                        'status' => null,
                        'milliseconds' => $timings[$key] ?? null,
                        'error' => 'No response returned by HTTP pool.',
                    ];

                    continue;
                }

                $body = $response->body();
                $ok = $response->successful()
                    && str_contains($body, '<section name="dialplan">')
                    && str_contains($body, '<context name="'.$query['Caller-Context'].'">');

                $results[$key] = [
                    'ok' => $ok,
                    'status' => $response->status(),
                    'milliseconds' => $timings[$key] ?? null,
                    'error' => $ok ? null : 'Unexpected XML handler response.',
                ];
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Build a complete set of XML handler dialplan query parameters.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, array<string, string>>
     */
    private function buildQueries(int $requestCount, string $scenario, string $tenantId, array $extensions, DialplanContext $dialplanContext): array
    {
        $queries = [];

        for ($index = 0; $index < $requestCount; $index++) {
            $selectedScenario = $scenario === 'mixed'
                ? ['internal', 'inbound', 'outbound'][$index % 3]
                : $scenario;

            $caller = $extensions[$index % count($extensions)];
            $destination = $extensions[($index + 1) % count($extensions)];
            $context = $dialplanContext->internal($tenantId);

            if ($selectedScenario === 'cache-hit') {
                $caller = $extensions[0];
                $destination = $extensions[1];
            } elseif ($selectedScenario === 'inbound') {
                $context = $dialplanContext->public($tenantId);
                $destination = '15551230000';
                $caller = '15551239999';
            } elseif ($selectedScenario === 'outbound') {
                $destination = '91555123000';
            }

            $queries[] = [
                'section' => 'dialplan',
                'Caller-Context' => $context,
                'Caller-Destination-Number' => $destination,
                'Caller-Caller-ID-Number' => $caller,
            ];
        }

        return $queries;
    }

    /**
     * Build the JSON-serializable load test report.
     *
     * @param  array<int, array{ok: bool, status: int|null, milliseconds: float|null, error: string|null}>  $results
     * @return array<string, mixed>
     */
    private function buildReport(Tenant $tenant, string $scenario, string $url, int $requestCount, int $concurrency, int $timeout, ?string $label, array $thresholds, float $elapsedMs, array $results): array
    {
        $successful = collect($results)->where('ok', true)->count();
        $failed = count($results) - $successful;
        $successRate = $requestCount > 0 ? round(($successful / $requestCount) * 100, 3) : 0.0;
        $failureRate = $requestCount > 0 ? round(($failed / $requestCount) * 100, 3) : 0.0;
        $latencies = collect($results)
            ->pluck('milliseconds')
            ->filter(fn (?float $value): bool => $value !== null)
            ->map(fn (float $value): float => round($value, 3))
            ->sort()
            ->values()
            ->all();
        $latencySummary = [
            'sample_count' => count($latencies),
            'missing_count' => max(0, count($results) - count($latencies)),
            'average' => $latencies === [] ? null : round(array_sum($latencies) / count($latencies), 3),
            'min' => $latencies === [] ? null : min($latencies),
            'max' => $latencies === [] ? null : max($latencies),
            'raw_samples' => $latencies,
        ];
        $thresholdResults = $this->thresholdResults($failureRate, $latencySummary, $thresholds);

        return [
            'run' => [
                'id' => now()->format('Ymd-His').'-'.Str::lower(Str::random(6)),
                'label' => $label,
                'git_commit' => $this->gitCommit(),
                'git_dirty' => $this->gitDirty(),
            ],
            'generated_at' => now()->toIso8601String(),
            'tenant_slug' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'scenario' => $scenario,
            'endpoint' => $url,
            'target' => [
                'total_requests' => $requestCount,
                'concurrency' => $concurrency,
                'timeout_seconds' => $timeout,
                'scenario_counts' => $this->scenarioCounts($scenario, $requestCount),
            ],
            'environment' => $this->environmentSnapshot(),
            'total_requests' => $requestCount,
            'concurrency' => $concurrency,
            'successful_responses' => $successful,
            'failed_responses' => $failed,
            'success_rate_percent' => $successRate,
            'failure_rate_percent' => $failureRate,
            'elapsed_seconds' => round($elapsedMs / 1000, 3),
            'requests_per_second' => $elapsedMs > 0 ? round($requestCount / ($elapsedMs / 1000), 3) : 0.0,
            'latency_ms' => $latencySummary,
            'thresholds' => $thresholds,
            'threshold_results' => $thresholdResults,
            'passed' => $thresholdResults === [],
            'status_counts' => collect($results)
                ->countBy(fn (array $result): string => (string) ($result['status'] ?? 'connection_error'))
                ->all(),
            'sample_failures' => collect($results)
                ->filter(fn (array $result): bool => ! $result['ok'])
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * Count which scenario types are expected in the request batch.
     *
     * @return array<string, int>
     */
    private function scenarioCounts(string $scenario, int $requestCount): array
    {
        if ($scenario !== 'mixed') {
            return [$scenario => $requestCount];
        }

        $counts = ['internal' => 0, 'inbound' => 0, 'outbound' => 0];
        $scenarios = array_keys($counts);

        for ($index = 0; $index < $requestCount; $index++) {
            $counts[$scenarios[$index % count($scenarios)]]++;
        }

        return $counts;
    }

    /**
     * Build human-readable threshold failures for the report and exit code.
     *
     * @param  array{sample_count: int, missing_count: int, average: float|null, min: float|null, max: float|null}  $latencySummary
     * @param  array{max_failure_rate_percent: float, max_average_ms: float|null}  $thresholds
     * @return array<int, string>
     */
    private function thresholdResults(float $failureRate, array $latencySummary, array $thresholds): array
    {
        $failures = [];

        if ($failureRate > $thresholds['max_failure_rate_percent']) {
            $failures[] = 'Failure rate '.$this->formatMetric($failureRate).'% exceeded allowed '.$this->formatMetric($thresholds['max_failure_rate_percent']).'%.';
        }

        $maximumAverage = $thresholds['max_average_ms'];

        if ($maximumAverage !== null) {
            $average = $latencySummary['average'];

            if ($average === null) {
                $failures[] = "Average latency was unavailable, but a {$this->formatMetric($maximumAverage)} ms threshold was configured.";
            } elseif ($average > $maximumAverage) {
                $failures[] = "Average latency {$this->formatMetric($average)} ms exceeded allowed {$this->formatMetric($maximumAverage)} ms.";
            }
        }

        return $failures;
    }

    /**
     * Capture load-generator environment details that make reports comparable.
     *
     * @return array<string, int|string|null>
     */
    private function environmentSnapshot(): array
    {
        return [
            'hostname' => gethostname() ?: null,
            'os' => php_uname('s').' '.php_uname('r'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env' => (string) config('app.env'),
            'cache_store' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'cpu_cores' => $this->cpuCoreCount(),
        ];
    }

    /**
     * Resolve the visible CPU core count for the load generator host.
     */
    private function cpuCoreCount(): ?int
    {
        $processors = (int) trim((string) @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));

        return $processors > 0 ? $processors : null;
    }

    /**
     * Resolve the current Git commit when the command runs inside a checkout.
     */
    private function gitCommit(): ?string
    {
        $output = [];
        $exitCode = 1;
        @exec('git rev-parse --short=12 HEAD 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && isset($output[0]) && $output[0] !== '' ? $output[0] : null;
    }

    /**
     * Determine whether tracked or untracked files were present during the run.
     */
    private function gitDirty(): ?bool
    {
        $output = [];
        $exitCode = 1;
        @exec('git status --porcelain 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 ? $output !== [] : null;
    }

    /**
     * Format a numeric metric without unnecessary trailing zeroes.
     */
    private function formatMetric(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }


    /**
     * Calculate a nearest-rank percentile for sorted latency values on demand.
     *
     * @param  array<int, float>  $sortedValues
     */
    public function percentile(array $sortedValues, int $percentile): ?float
    {
        if ($sortedValues === []) {
            return null;
        }

        $rank = (int) ceil(($percentile / 100) * count($sortedValues));
        $index = max(0, min(count($sortedValues) - 1, $rank - 1));

        return $sortedValues[$index];
    }

    /**
     * Write the JSON report to disk.
     *
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $path, array $report): void
    {
        $absolutePath = base_path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($absolutePath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * Read a required string option.
     */
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("The [{$name}] option must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * Read an optional string option.
     */
    private function nullableStringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Read a bounded integer option.
     */
    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("The [{$name}] option must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    /**
     * Read a bounded floating point option.
     */
    private function floatOption(string $name, float $minimum, float $maximum): float
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_FLOAT);

        if (! is_float($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("The [{$name}] option must be between {$this->formatMetric($minimum)} and {$this->formatMetric($maximum)}.");
        }

        return $value;
    }

    /**
     * Read an optional lower-bounded floating point option.
     */
    private function nullableFloatOption(string $name, float $minimum): ?float
    {
        $rawValue = $this->option($name);

        if (! is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $value = filter_var($rawValue, FILTER_VALIDATE_FLOAT);

        if (! is_float($value) || $value < $minimum) {
            throw new \InvalidArgumentException("The [{$name}] option must be at least {$this->formatMetric($minimum)}.");
        }

        return $value;
    }

    /**
     * Resolve and validate the scenario option.
     */
    private function scenarioOption(): string
    {
        $scenario = $this->stringOption('scenario');
        $allowed = ['internal', 'inbound', 'outbound', 'mixed', 'cache-hit'];

        if (! in_array($scenario, $allowed, true)) {
            throw new \InvalidArgumentException('The [scenario] option must be one of: '.implode(', ', $allowed).'.');
        }

        return $scenario;
    }

    /**
     * Resolve the XML handler URL.
     */
    private function xmlHandlerUrl(): string
    {
        $url = $this->option('url');

        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }

        return rtrim((string) config('app.url'), '/').config('freeswitch.xml_handler.path', '/api/v1/xml-handler');
    }
}
