<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Verifies that one load-test report describes a real, complete run.
 *
 * A measurement is only evidence if the artifact it came from is internally
 * consistent. This command checks that the counters add up, that every
 * successful response has a latency sample, that the run is labelled the way
 * the matrix expected, and that it was measured against the server the
 * variant is supposed to use. It exits non-zero and names the first
 * inconsistency it finds, so a broken cell fails instead of being published.
 */
class PbxLoadTestVerifyCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'pbx:load-test:verify
        {report : Path to the cell report to verify}
        {--expect-endpoint= : Endpoint the report must have been measured against}
        {--expect-label= : Label prefix the report must carry}
        {--expect-requests= : Total number of requests the run must have made}
        {--allow-failures : A run with failed responses is still consistent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that a load-test report is internally consistent';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('report');

        if (! is_file($path)) {
            $this->components->error("Report not found: {$path}");

            return self::FAILURE;
        }

        $report = json_decode((string) file_get_contents($path), true);

        if (! is_array($report)) {
            $this->components->error("Report is not readable JSON: {$path}");

            return self::FAILURE;
        }

        $problems = $this->problems($report);

        if ($problems !== []) {
            $this->components->error("Report is not consistent: {$path}");

            foreach ($problems as $problem) {
                $this->components->twoColumnDetail('Problem', $problem);
            }

            return self::FAILURE;
        }

        $this->components->info("Report is consistent: {$path}");
        $this->components->twoColumnDetail('Requests', (string) ($report['target']['total_requests'] ?? 0));
        $this->components->twoColumnDetail('Successful', (string) ($report['successful_responses'] ?? 0));
        $this->components->twoColumnDetail('Failed', (string) ($report['failed_responses'] ?? 0));
        $this->components->twoColumnDetail('Endpoint', (string) ($report['endpoint'] ?? ''));

        return self::SUCCESS;
    }

    /**
     * Collect every inconsistency in a report.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function problems(array $report): array
    {
        $problems = [];

        $total = (int) ($report['target']['total_requests'] ?? 0);
        $successful = (int) ($report['successful_responses'] ?? 0);
        $failed = (int) ($report['failed_responses'] ?? 0);

        if ($total < 1) {
            $problems[] = 'the run made no requests';
        }

        if ($successful + $failed !== $total) {
            $problems[] = sprintf(
                'successful (%d) plus failed (%d) does not equal the requests made (%d)',
                $successful,
                $failed,
                $total,
            );
        }

        $statusCounts = (array) ($report['status_counts'] ?? []);
        $statusTotal = array_sum($statusCounts);

        if ($statusCounts === []) {
            $problems[] = 'no status codes were recorded';
        } elseif ($statusTotal !== $total) {
            $problems[] = sprintf(
                'the status codes add up to %d but %d requests were made',
                $statusTotal,
                $total,
            );
        }

        if ($failed > 0 && ! $this->option('allow-failures')) {
            $problems[] = sprintf('%d requests failed', $failed);
        }

        $latency = (array) ($report['latency_ms'] ?? []);
        $samples = (int) ($latency['sample_count'] ?? 0);
        $missing = (int) ($latency['missing_count'] ?? 0);

        if ($samples + $missing !== $successful) {
            $problems[] = sprintf(
                'latency samples (%d) plus missing (%d) do not match the successful responses (%d)',
                $samples,
                $missing,
                $successful,
            );
        }

        if ($successful > 0 && $samples === 0) {
            $problems[] = 'no latency samples were recorded';
        }

        $expectedEndpoint = $this->option('expect-endpoint');

        if (is_string($expectedEndpoint) && $expectedEndpoint !== '' && ($report['endpoint'] ?? null) !== $expectedEndpoint) {
            $problems[] = sprintf(
                'measured against %s but the variant requires %s',
                (string) ($report['endpoint'] ?? 'nothing'),
                $expectedEndpoint,
            );
        }

        $expectedLabel = $this->option('expect-label');

        if (is_string($expectedLabel) && $expectedLabel !== '') {
            $label = (string) ($report['run']['label'] ?? '');

            if (! str_starts_with($label, $expectedLabel)) {
                $problems[] = sprintf('labelled %s but the matrix expected %s', $label, $expectedLabel);
            }
        }

        $expectedRequests = $this->option('expect-requests');

        // The value arrives as a string from the command line and as an
        // integer from an array-style caller, so both are accepted.
        if ($expectedRequests !== null && $expectedRequests !== '' && $total !== (int) $expectedRequests) {
            $problems[] = sprintf('made %d requests but %d were expected', $total, (int) $expectedRequests);
        }

        return $problems;
    }
}
