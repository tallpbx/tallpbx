<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
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
    {--section=dialplan : Section mode: dialplan, directory, or mixed}
    {--domain= : Domain used for directory lookups; defaults to the first SIP domain of the tenant}
    {--timing : Request and record app-side timing headers}
    {--timeout=10 : Per-request timeout in seconds}
    {--token= : Optional XML handler bearer token}
    {--label= : Optional human-readable label stored in the JSON report}
    {--interface= : Optional network interface label (e.g. public or private)}
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
        $section = $this->sectionOption();
        $timing = (bool) $this->option('timing');

        // Directory lookups need the SIP domain; dialplan-only runs keep working
        // on tenants without one, so resolve it lazily.
        $domain = $section === 'dialplan' ? '' : $this->resolveDomain($tenant);

        if ($section !== 'dialplan' && $domain === null) {
            $this->components->error('No SIP domain found for directory lookups. Seed the tenant or pass --domain.');

            return self::FAILURE;
        }

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
            section: $section,
            tenantId: (string) $tenant->id,
            domain: (string) $domain,
            extensions: $extensions,
            dialplanContext: $dialplanContext,
        );

        $this->components->info('Running dynamic dialplan XML load test.');
        $this->components->twoColumnDetail('Endpoint', $url);
        $this->components->twoColumnDetail('Tenant', "{$tenant->slug} ({$tenant->id})");
        $this->components->twoColumnDetail('Scenario', $scenario);
        $this->components->twoColumnDetail('Section', $section);
        $this->components->twoColumnDetail('Requests', (string) $requestCount);
        $this->components->twoColumnDetail('Concurrency', (string) $concurrency);

        $started = hrtime(true);
        $results = $this->sendRequests($url, $queries, $concurrency, $timeout, is_string($token) && $token !== '' ? $token : null, $timing);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $report = $this->buildReport(
            tenant: $tenant,
            scenario: $scenario,
            section: $section,
            sectionCounts: $this->sectionCounts($queries),
            scenarioCounts: $this->scenarioCounts($queries, $scenario),
            timingEnabled: $timing,
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

        $tableRows = [
            ['Total requests', (string) $report['total_requests']],
            ['Successful responses', (string) $report['successful_responses']],
            ['Failed responses', (string) $report['failed_responses']],
            ['Elapsed seconds', (string) $report['elapsed_seconds']],
            ['Requests/sec', (string) $report['requests_per_second']],
            ['Average ms', (string) $report['latency_ms']['average']],
            ['Fastest ms', (string) $report['latency_ms']['min']],
            ['Slowest ms', (string) $report['latency_ms']['max']],
            ['Passed thresholds', $report['passed'] ? 'yes' : 'no'],
            ['Report', $this->resolveReportPath($reportPath)],
        ];

        if ($report['timing']['enabled']) {
            $tableRows[] = ['App-side p95 ms', (string) ($report['timing']['request_ms']['p95'] ?? 'n/a')];
        }

        if (isset($report['environment']['redis']['used_memory_human'])) {
            $redisInfo = $report['environment']['redis'];
            $maxMem = $redisInfo['maxmemory_human'] ?? 'uncapped';
            $evicted = $redisInfo['evicted_keys'] ?? 0;
            $tableRows[] = ['Redis memory', "{$redisInfo['used_memory_human']} / {$maxMem} (evicted: {$evicted})"];
        }

        $this->table(
            ['Metric', 'Value'],
            $tableRows,
        );

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Send XML handler requests in concurrent chunks.
     *
     * @param  array<int, array{section: string, params: array<string, string>}>  $queries
     * @return array<int, array{ok: bool, status: int|null, milliseconds: float|null, error: string|null, section: string, handler_ms: float|null, request_ms: float|null, db_queries: int|null}>
     */
    private function sendRequests(string $url, array $queries, int $concurrency, int $timeout, ?string $token, bool $timing = false): array
    {
        $results = [];

        foreach (array_chunk($queries, $concurrency, preserve_keys: true) as $chunk) {
            $timings = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $url, $timeout, $token, $timing, &$timings): array {
                    $requests = [];

                    foreach ($chunk as $key => $item) {
                        $pending = $pool->as((string) $key)
                            ->timeout($timeout)
                            ->accept('application/xml')
                            ->withOptions([
                                'on_stats' => function ($stats) use (&$timings, $key): void {
                                    if (method_exists($stats, 'getTransferTime')) {
                                        // Guzzle reports transfer time in seconds; convert to milliseconds.
                                        $timings[$key] = (float) $stats->getTransferTime() * 1000;
                                    }
                                },
                            ]);

                        if ($token !== null) {
                            $pending = $pending->withToken($token);
                        }

                        // Opt-in: only ask for timing headers when the run wants them.
                        if ($timing) {
                            $pending = $pending->withHeaders(['X-TallPBX-Timing' => '1']);
                        }

                        $requests[] = $pending->get($url, $item['params']);
                    }

                    return $requests;
                }, concurrency: $concurrency);
            } catch (ConnectionException $exception) {
                foreach ($chunk as $key => $item) {
                    $results[$key] = [
                        'ok' => false,
                        'status' => null,
                        'milliseconds' => null,
                        'error' => $exception->getMessage(),
                        'section' => $item['section'],
                        'handler_ms' => null,
                        'request_ms' => null,
                        'db_queries' => null,
                    ];
                }

                continue;
            }

            foreach ($chunk as $key => $item) {
                $response = $responses[(string) $key] ?? null;

                if (! $response instanceof Response) {
                    $results[$key] = [
                        'ok' => false,
                        'status' => null,
                        'milliseconds' => $timings[$key] ?? null,
                        'error' => 'No response returned by HTTP pool.',
                        'section' => $item['section'],
                        'handler_ms' => null,
                        'request_ms' => null,
                        'db_queries' => null,
                    ];

                    continue;
                }

                $ok = $this->responseMatchesSection($item['section'], $item['params'], $response);

                $results[$key] = [
                    'ok' => $ok,
                    'status' => $response->status(),
                    'milliseconds' => $timings[$key] ?? null,
                    'error' => $ok ? null : 'Unexpected XML handler response.',
                    'section' => $item['section'],
                    'handler_ms' => $this->floatHeader($response, 'X-TallPBX-Handler-Ms'),
                    'request_ms' => $this->floatHeader($response, 'X-TallPBX-Request-Ms'),
                    'db_queries' => $this->intHeader($response, 'X-TallPBX-DB-Queries'),
                ];
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Read a numeric timing header as a float, or null when absent.
     */
    private function floatHeader(Response $response, string $name): ?float
    {
        $value = $response->header($name);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Read a numeric count header as an integer, or null when absent.
     */
    private function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Determine whether a response carries the XML expected for its section.
     *
     * @param  array<string, string>  $params
     */
    private function responseMatchesSection(string $section, array $params, Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        if ($section === 'directory') {
            return str_contains($body, '<section name="directory">');
        }

        $context = $params['Caller-Context'] ?? '';

        return str_contains($body, '<section name="dialplan">')
            && str_contains($body, '<context name="'.$context.'">');
    }

    /**
     * Build a complete set of XML handler query parameters.
     *
     * @param  array<int, string>  $extensions
     * @return array<int, array{section: string, params: array<string, string>}>
     */
    private function buildQueries(int $requestCount, string $scenario, string $section, string $tenantId, string $domain, array $extensions, DialplanContext $dialplanContext): array
    {
        $queries = [];
        $dialplanIndex = 0;

        for ($index = 0; $index < $requestCount; $index++) {
            // Mixed mode mirrors a real call flow: two directory auth lookups
            // (caller authentication and destination location) per dialplan
            // lookup.
            $selectedSection = match ($section) {
                'directory' => 'directory',
                'mixed' => ($index % 3 < 2) ? 'directory' : 'dialplan',
                default => 'dialplan',
            };

            if ($selectedSection === 'directory') {
                $user = $extensions[$index % count($extensions)];

                $queries[] = [
                    'section' => 'directory',
                    'params' => [
                        'section' => 'directory',
                        'tag_name' => 'user',
                        'key_name' => 'name',
                        'key_value' => $user,
                        'domain' => $domain,
                        'sip_auth_username' => $user,
                        'user' => $user,
                    ],
                ];

                continue;
            }

            $selectedScenario = $scenario === 'mixed'
                ? ['internal', 'inbound', 'outbound'][$dialplanIndex % 3]
                : $scenario;

            $caller = $extensions[$dialplanIndex % count($extensions)];
            $destination = $extensions[($dialplanIndex + 1) % count($extensions)];
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
                'params' => [
                    'section' => 'dialplan',
                    'Caller-Context' => $context,
                    'Caller-Destination-Number' => $destination,
                    'Caller-Caller-ID-Number' => $caller,
                ],
            ];

            $dialplanIndex++;
        }

        return $queries;
    }

    /**
     * Build the JSON-serializable load test report.
     *
     * @param  array<int, array{ok: bool, status: int|null, milliseconds: float|null, error: string|null, section: string, handler_ms: float|null, request_ms: float|null, db_queries: int|null}>  $results
     * @return array<string, mixed>
     */
    private function buildReport(Tenant $tenant, string $scenario, string $section, array $sectionCounts, array $scenarioCounts, bool $timingEnabled, string $url, int $requestCount, int $concurrency, int $timeout, ?string $label, array $thresholds, float $elapsedMs, array $results): array
    {
        $successful = collect($results)->where('ok', true)->count();
        $failed = count($results) - $successful;
        $successRate = $requestCount > 0 ? round(($successful / $requestCount) * 100, 3) : 0.0;
        $failureRate = $requestCount > 0 ? round(($failed / $requestCount) * 100, 3) : 0.0;
        $latencySummary = $this->summarizeValues(collect($results)->pluck('milliseconds')->all());
        $thresholdResults = $this->thresholdResults($failureRate, $latencySummary, $thresholds);
        $timing = $this->timingSummary($results, $timingEnabled);

        return [
            'run' => [
                'id' => now()->format('Ymd-His').'-'.Str::lower(Str::random(6)),
                'label' => $label,
                'git_commit' => $this->gitCommit(),
                'git_dirty' => $this->gitDirty(),
            ],
            'generated_at' => now()->toIso8601String(),
            // Schema 2 adds the additive timing block for app-side metrics.
            'schema_version' => 2,
            'tenant_slug' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'scenario' => $scenario,
            'endpoint' => $url,
            'target' => [
                'total_requests' => $requestCount,
                'concurrency' => $concurrency,
                'timeout_seconds' => $timeout,
                'network_interface' => $this->nullableStringOption('interface'),
                'scenario_counts' => $scenarioCounts,
                'section' => $section,
                'section_counts' => $sectionCounts,
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
            'timing' => $timing,
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
     * Summarize millisecond samples into the report latency shape.
     *
     * @param  array<int, float|null>  $samples
     * @return array{sample_count: int, missing_count: int, average: float|null, min: float|null, max: float|null, p50: float|null, p90: float|null, p95: float|null, p99: float|null, std_dev: float|null, raw_samples: array<int, float>}
     */
    private function summarizeValues(array $samples): array
    {
        $latencies = collect($samples)
            ->filter(fn (?float $value): bool => $value !== null)
            ->map(fn (float $value): float => round($value, 3))
            ->sort()
            ->values()
            ->all();

        return [
            'sample_count' => count($latencies),
            // Samples that never reported a value (timeouts, connection errors,
            // or runs without the timing headers enabled).
            'missing_count' => max(0, count($samples) - count($latencies)),
            'average' => $latencies === [] ? null : round(array_sum($latencies) / count($latencies), 3),
            'min' => $latencies === [] ? null : min($latencies),
            'max' => $latencies === [] ? null : max($latencies),
            'p50' => $this->percentile($latencies, 50),
            'p90' => $this->percentile($latencies, 90),
            'p95' => $this->percentile($latencies, 95),
            'p99' => $this->percentile($latencies, 99),
            'std_dev' => $this->standardDeviation($latencies),
            'raw_samples' => $latencies,
        ];
    }

    /**
     * Build the additive app-side timing block for the report.
     *
     * @param  array<int, array{ok: bool, status: int|null, milliseconds: float|null, error: string|null, section: string, handler_ms: float|null, request_ms: float|null, db_queries: int|null}>  $results
     * @return array<string, mixed>
     */
    private function timingSummary(array $results, bool $enabled): array
    {
        $dbQueries = collect($results)
            ->pluck('db_queries')
            ->filter(fn (?int $value): bool => $value !== null);

        // Per-section request_ms summaries separate directory work from
        // dialplan work without splitting the run into two commands.
        $bySection = [];
        foreach (collect($results)->groupBy('section') as $sectionName => $group) {
            $summary = $this->summarizeValues($group->pluck('request_ms')->all());
            $bySection[$sectionName] = [
                'count' => $group->count(),
                'p50' => $summary['p50'],
                'p95' => $summary['p95'],
            ];
        }

        return [
            'enabled' => $enabled,
            'request_ms' => $this->summarizeValues(collect($results)->pluck('request_ms')->all()),
            'handler_ms' => $this->summarizeValues(collect($results)->pluck('handler_ms')->all()),
            'db_queries' => [
                'average' => $dbQueries->isEmpty() ? null : round((float) $dbQueries->avg(), 3),
                'max' => $dbQueries->isEmpty() ? null : (int) $dbQueries->max(),
            ],
            'by_section' => $bySection,
        ];
    }

    /**
     * Count which scenario types appear in the generated dialplan queries.
     *
     * @param  array<int, array{section: string, params: array<string, string>}>  $queries
     * @return array<string, int>
     */
    private function scenarioCounts(array $queries, string $scenario): array
    {
        // Directory requests carry no dialplan scenario, so mixed-section
        // reports must count only the dialplan lookups.
        $dialplanCount = count(array_filter(
            $queries,
            fn (array $query): bool => $query['section'] === 'dialplan',
        ));

        if ($scenario !== 'mixed') {
            return [$scenario => $dialplanCount];
        }

        $counts = ['internal' => 0, 'inbound' => 0, 'outbound' => 0];
        $scenarios = array_keys($counts);

        for ($index = 0; $index < $dialplanCount; $index++) {
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
            'available_ram_mb' => $this->availableRamMb(),
            'swap_used_mb' => $this->swapUsedMb(),
            'redis' => $this->redisTelemetry(),
        ];
    }

    /**
     * Resolve Redis memory metrics and eviction stats if Redis is connected.
     *
     * @return array<string, int|string|null>|null
     */
    private function redisTelemetry(): ?array
    {
        try {
            $info = Redis::info();
            $memoryInfo = $info['Memory'] ?? $info['memory'] ?? $info;
            $statsInfo = $info['Stats'] ?? $info['stats'] ?? $info;

            return [
                'used_memory_human' => $memoryInfo['used_memory_human'] ?? null,
                'used_memory_bytes' => isset($memoryInfo['used_memory']) ? (int) $memoryInfo['used_memory'] : null,
                'used_memory_peak_human' => $memoryInfo['used_memory_peak_human'] ?? null,
                'maxmemory_human' => $memoryInfo['maxmemory_human'] ?? null,
                'maxmemory_policy' => $memoryInfo['maxmemory_policy'] ?? null,
                'evicted_keys' => isset($statsInfo['evicted_keys']) ? (int) $statsInfo['evicted_keys'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve available physical RAM in megabytes if running on Linux.
     */
    private function availableRamMb(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || ! preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $matches)) {
            return null;
        }

        return (int) round(((int) $matches[1]) / 1024);
    }

    /**
     * Resolve swap memory currently in use in megabytes if running on Linux.
     */
    private function swapUsedMb(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false
            || ! preg_match('/SwapTotal:\s+(\d+)\s+kB/', $meminfo, $totalMatches)
            || ! preg_match('/SwapFree:\s+(\d+)\s+kB/', $meminfo, $freeMatches)) {
            return null;
        }

        $totalKb = (int) $totalMatches[1];
        $freeKb = (int) $freeMatches[1];

        return (int) round(($totalKb - $freeKb) / 1024);
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

        // Nearest-rank method: the p-th percentile is the smallest sample
        // whose rank covers p% of the values.
        $rank = (int) ceil(($percentile / 100) * count($sortedValues));
        $index = max(0, min(count($sortedValues) - 1, $rank - 1));

        return $sortedValues[$index];
    }

    /**
     * Calculate sample standard deviation for a list of latency samples.
     *
     * @param  array<int, float>  $values
     */
    public function standardDeviation(array $values): ?float
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $sumSquaredDiffs = array_sum(array_map(fn (float $x): float => ($x - $mean) ** 2, $values));

        return round(sqrt($sumSquaredDiffs / ($count - 1)), 3);
    }

    /**
     * Write the JSON report to disk.
     *
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $path, array $report): void
    {
        $absolutePath = $this->resolveReportPath($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($absolutePath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * Resolve the report path against the application root.
     *
     * Absolute paths (for example /var/backups/report.json) are used as-is;
     * relative paths are resolved from the application root.
     */
    private function resolveReportPath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
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
     * Count queries per section so reports stay diff-stable.
     *
     * @param  array<int, array{section: string, params: array<string, string>}>  $queries
     * @return array<string, int>
     */
    private function sectionCounts(array $queries): array
    {
        // Fixed order: directory first, dialplan second. Empty sections are
        // omitted so dialplan-only reports keep a single-entry map.
        $counts = ['directory' => 0, 'dialplan' => 0];

        foreach ($queries as $query) {
            $counts[$query['section']]++;
        }

        return array_filter($counts, fn (int $count): bool => $count > 0);
    }

    /**
     * Resolve the SIP domain used for directory lookups.
     */
    private function resolveDomain(Tenant $tenant): ?string
    {
        $domain = $this->option('domain');

        if (is_string($domain) && trim($domain) !== '') {
            return trim($domain);
        }

        $tenantDomain = TenantDomain::where('tenant_id', $tenant->id)->value('domain');

        return is_string($tenantDomain) && $tenantDomain !== '' ? $tenantDomain : null;
    }

    /**
     * Resolve and validate the section option.
     */
    private function sectionOption(): string
    {
        $section = $this->stringOption('section');
        $allowed = ['dialplan', 'directory', 'mixed'];

        if (! in_array($section, $allowed, true)) {
            throw new \InvalidArgumentException('The [section] option must be one of: '.implode(', ', $allowed).'.');
        }

        return $section;
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
