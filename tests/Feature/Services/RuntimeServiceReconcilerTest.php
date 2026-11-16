<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Services\Appliance\RuntimeServiceReconciler;
use App\Support\ProcessResult;
use Illuminate\Support\Facades\Redis;

/*
 * A restore or an update is only finished when the services it restarted are
 * actually running again. `systemctl restart` exit codes are not health
 * signals, so every required unit is verified afterwards and a failure is
 * reported instead of swallowed.
 */

/**
 * Records every command and scripts which units fail or stay inactive.
 */
final class ReconcilerProcessRunner implements ProcessRunner
{
    /** @var list<array<int, string>> */
    public array $commands = [];

    /**
     * @param  list<string>  $failFor  Units whose restart throws
     * @param  list<string>  $inactive  Units reporting "not active"
     * @param  string  $fsStatus  Output of `fs_cli -x status`
     * @param  string  $helperOutput  Fixed-status output of the update helper
     */
    public function __construct(
        private readonly array $failFor = [],
        private readonly array $inactive = [],
        private readonly string $fsStatus = 'UP 0 calls',
        private readonly string $helperOutput = '',
    ) {}

    /**
     * Run one command and return its scripted result.
     *
     * @param  array<int, string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult
    {
        $this->commands[] = $argv;
        $joined = implode(' ', $argv);

        if (($argv[0] ?? null) === 'fs_cli') {
            return new ProcessResult(0, $this->fsStatus);
        }

        if (str_contains($joined, 'tallpbx-update-workers')) {
            return new ProcessResult(0, $this->helperOutput);
        }

        if (($argv[0] ?? null) === 'systemctl') {
            $unit = (string) end($argv);

            if (($argv[1] ?? null) === 'restart' && in_array($unit, $this->failFor, true)) {
                return new ProcessResult(1, 'restart failed');
            }

            if (($argv[1] ?? null) === 'is-active' && in_array($unit, $this->inactive, true)) {
                return new ProcessResult(3, 'inactive');
            }

            return new ProcessResult(0, '');
        }

        return new ProcessResult(0, '');
    }
}

beforeEach(function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('ping')->andReturn(true);
    Redis::shouldReceive('connection')->andReturn($connection);
});

it('restarts every required unit and reports failures instead of swallowing them', function (): void {
    $runner = new ReconcilerProcessRunner(
        failFor: ['tallpbx-cdr-worker'],
        inactive: ['tallpbx-cdr-worker'],
    );

    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['restarted'])->toContain('mariadb', 'nginx', 'freeswitch')
        ->and($result['failed'])->toBe(['tallpbx-cdr-worker'])
        ->and($result['probes']['ok'])->toContain('database', 'redis', 'freeswitch');
});

it('never enables an optional unit that was already disabled', function (): void {
    $runner = new ReconcilerProcessRunner(inactive: ['tallpbx-xml-worker']);

    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['skipped'])->toContain('tallpbx-xml-worker')
        ->and($result['warnings'])->toBe([])
        ->and($result['failed'])->toBe([]);
});

it('warns instead of failing when an enabled optional unit does not return', function (): void {
    // The XML worker was active before the operation but reports inactive
    // afterwards. The fake cannot distinguish "before" from "after", so this
    // covers the optional-active path by removing it from the inactive list
    // for the restart and keeping it inactive afterwards.
    $runner = new class implements ProcessRunner
    {
        /** @var list<array<int, string>> */
        public array $commands = [];

        private int $isActiveCalls = 0;

        /**
         * Serve the scripted systemd and fs_cli responses.
         */
        public function run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult
        {
            $this->commands[] = $argv;

            if (($argv[0] ?? null) === 'fs_cli') {
                return new ProcessResult(0, 'UP');
            }

            if (($argv[0] ?? null) === 'systemctl' && ($argv[1] ?? null) === 'is-active') {
                if (end($argv) === 'tallpbx-xml-worker') {
                    // Active on the pre-check, inactive on the post-check.
                    return $this->isActiveCalls++ === 0 ? new ProcessResult(0, '') : new ProcessResult(3, 'inactive');
                }

                return new ProcessResult(0, '');
            }

            return new ProcessResult(0, '');
        }
    };

    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['failed'])->toBe([])
        ->and($result['warnings'])->toBe(['tallpbx-xml-worker']);
});

it('reloads every resident PHP unit after a web update without touching call or database services', function (): void {
    $runner = new ReconcilerProcessRunner(helperOutput: "ok tallpbx-queue\nok tallpbx-reverb\nok tallpbx-cdr-worker\nok freeswitch-listener\nok tallpbx-scheduler\nskipped tallpbx-xml-worker\n");

    $result = (new RuntimeServiceReconciler($runner))->reconcileAfterUpdate();

    expect($result['failed'])->toBe([])
        ->and($result['restarted'])->toContain('tallpbx-queue', 'tallpbx-reverb', 'tallpbx-cdr-worker', 'freeswitch-listener', 'tallpbx-scheduler')
        ->and($result['skipped'])->toContain('tallpbx-xml-worker');

    $commands = array_map(fn (array $argv): string => implode(' ', $argv), $runner->commands);

    foreach (['mariadb', 'redis-server', 'freeswitch ', 'nginx', 'fpm'] as $forbidden) {
        expect(implode("\n", $commands))->not->toContain('restart '.$forbidden);
    }
});

it('reports a missing update helper as an operator-actionable failure', function (): void {
    $runner = new ReconcilerProcessRunner(helperOutput: '');
    $reconciler = new RuntimeServiceReconciler($runner);

    // Root CLI updates invoke the helper directly and need no sudo preflight;
    // a web (www-data) update must be told how to install it.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        expect($reconciler->preflightUpdate())->toBeNull();

        return;
    }

    if (is_executable('/usr/local/sbin/tallpbx-update-workers')) {
        expect($reconciler->preflightUpdate())->toBeNull();

        return;
    }

    expect($reconciler->preflightUpdate())
        ->not->toBeNull()
        ->toContain('tallpbx-update-workers');
});
