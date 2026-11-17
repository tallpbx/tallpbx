<?php

declare(strict_types=1);

namespace App\Services\Appliance;

use App\Contracts\ProcessRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Restarts and verifies the services a restore or an update depends on.
 *
 * `systemctl restart` reports only that the request was accepted. This class
 * records the expected state first, restarts, then confirms the unit is active
 * afterwards; a required unit that does not return is a failure, an optional
 * unit that was already disabled is skipped and never started.
 */
class RuntimeServiceReconciler
{
    /** Fixed path of the bounded, root-owned worker-restart helper. */
    public const UPDATE_WORKER_HELPER = '/usr/local/sbin/tallpbx-update-workers';

    /**
     * Create the reconciler around the process runner.
     */
    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * Units every operation needs: data stores, web tier, telephony, workers.
     *
     * @return list<string>
     */
    public static function requiredUnits(): array
    {
        return [
            'mariadb',
            'redis-server',
            self::phpFpmUnit(),
            'nginx',
            'freeswitch',
            'freeswitch-listener',
            'tallpbx-queue',
            'tallpbx-scheduler',
            'tallpbx-cdr-worker',
            'tallpbx-reverb',
        ];
    }

    /**
     * Units that may be deliberately disabled and must never be started here.
     *
     * @return list<string>
     */
    public static function optionalUnits(): array
    {
        return ['tallpbx-xml-worker'];
    }

    /**
     * Resident PHP workers reloaded after a web update, in dependency order.
     *
     * @return list<string>
     */
    public static function residentPhpUnits(): array
    {
        return ['freeswitch-listener', 'tallpbx-queue', 'tallpbx-scheduler', 'tallpbx-cdr-worker', 'tallpbx-reverb'];
    }

    /**
     * Restart every unit a restore depends on, then verify it is running.
     *
     * @return array{restarted: list<string>, skipped: list<string>, failed: list<string>, warnings: list<string>, probes: array{ok: list<string>, failed: list<string>}}
     */
    public function reconcile(): array
    {
        $restarted = [];
        $skipped = [];
        $failed = [];
        $warnings = [];

        // Expected state first: an optional unit that is inactive now must stay
        // off — enabling it would silently add an Octane worker.
        $optionalActive = [];

        foreach (self::optionalUnits() as $unit) {
            if ($this->isActive($unit)) {
                $optionalActive[] = $unit;
            } else {
                $skipped[] = $unit;
            }
        }

        foreach (self::requiredUnits() as $unit) {
            $this->restart($unit);
            $restarted[] = $unit;

            if (! $this->isActive($unit)) {
                $failed[] = $unit;
            }
        }

        foreach ($optionalActive as $unit) {
            $this->restart($unit);
            $restarted[] = $unit;

            if (! $this->isActive($unit)) {
                $warnings[] = $unit;
            }
        }

        return [
            'restarted' => $restarted,
            'skipped' => $skipped,
            'failed' => $failed,
            'warnings' => $warnings,
            'probes' => $this->probes(),
        ];
    }

    /**
     * Reload only the allowlisted resident PHP workers after a web update.
     *
     * Core services (MariaDB, Redis, Nginx, FreeSWITCH, PHP-FPM) are never
     * bounced: PHP-FPM is serving the update request and the rest carry live
     * calls. The bounded helper enforces the same rule on the host.
     *
     * @return array{restarted: list<string>, skipped: list<string>, failed: list<string>, warnings: list<string>, probes: array{ok: list<string>, failed: list<string>}}
     */
    public function reconcileAfterUpdate(): array
    {
        $restarted = [];
        $skipped = [];
        $failed = [];
        $warnings = [];

        try {
            $helperResult = $this->runner->run($this->helperCommand());
        } catch (Throwable $exception) {
            return [
                'restarted' => [],
                'skipped' => [],
                'failed' => self::residentPhpUnits(),
                'warnings' => [],
                'probes' => ['ok' => [], 'failed' => []],
                'reason' => trim($exception->getMessage()) ?: 'The worker-restart helper could not run.',
            ];
        }

        $reported = [];

        foreach (preg_split('/\R/', trim($helperResult->output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) < 2) {
                continue;
            }

            [$status, $unit] = $parts;

            if (! in_array($unit, [...self::residentPhpUnits(), ...self::optionalUnits()], true)) {
                continue;
            }

            $optional = in_array($unit, self::optionalUnits(), true);

            if (! in_array($status, $optional ? ['ok', 'skipped', 'failed', 'warning'] : ['ok', 'failed'], true)) {
                continue;
            }

            $reported[$unit] = true;

            if ($optional && in_array($status, ['failed', 'warning'], true)) {
                $warnings[] = $unit;

                continue;
            }

            match ($status) {
                'ok' => $restarted[] = $unit,
                'skipped' => $skipped[] = $unit,
                'failed' => $failed[] = $unit,
                default => null,
            };
        }

        // The helper reports what it did; the unit's own state is the truth.
        foreach (self::residentPhpUnits() as $unit) {
            if ((! isset($reported[$unit]) || ! $this->isActive($unit)) && ! in_array($unit, $failed, true)) {
                $failed[] = $unit;
            }
        }

        if (! $helperResult->ok() && $failed === [] && $warnings === []) {
            $failed = self::residentPhpUnits();
        }

        foreach (self::optionalUnits() as $unit) {
            if (in_array($unit, $skipped, true)) {
                continue;
            }

            if (! $this->isActive($unit) && ! in_array($unit, $warnings, true)) {
                $warnings[] = $unit;
            }
        }

        return [
            'restarted' => $restarted,
            'skipped' => $skipped,
            'failed' => $failed,
            'warnings' => $warnings,
            'probes' => ['ok' => [], 'failed' => []],
        ];
    }

    /**
     * Confirm the web tier answers again after leaving maintenance mode.
     *
     * Must run only after `artisan up`: PreventRequestsDuringMaintenance
     * answers /up with 503 while the site is down.
     */
    public function probeHttpHealth(): bool
    {
        try {
            return Http::timeout(5)->get('http://127.0.0.1/up')->status() === 200;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check before a web update that the bounded helper can be used.
     *
     * Returns null when the caller may proceed (helper ready, or running as
     * root), otherwise an operator-actionable installation message.
     */
    public function preflightUpdate(): ?string
    {
        if (self::isRoot()) {
            return null;
        }

        if (! is_executable(self::UPDATE_WORKER_HELPER)) {
            return 'Web updates require the bounded worker-restart helper. Install it once as root: sudo bash '
                .base_path('scripts/resources/install-update-workers.sh');
        }

        try {
            $result = $this->runner->run(['sudo', '-n', '-l', self::UPDATE_WORKER_HELPER]);
        } catch (Throwable) {
            $result = null;
        }

        if ($result === null || ! $result->ok()) {
            return 'The web updater is not yet allowed to run '
                .self::UPDATE_WORKER_HELPER
                .' without a password. Install the sudoers drop-in once as root: sudo bash '
                .base_path('scripts/resources/install-update-workers.sh');
        }

        return null;
    }

    /**
     * Run three bounded functional probes.
     *
     * @return array{ok: list<string>, failed: list<string>}
     */
    private function probes(): array
    {
        $ok = [];
        $failed = [];

        try {
            DB::select('select 1');
            $ok[] = 'database';
        } catch (Throwable) {
            $failed[] = 'database';
        }

        try {
            Redis::connection('default')->ping();
            $ok[] = 'redis';
        } catch (Throwable) {
            $failed[] = 'redis';
        }

        try {
            $status = $this->runner->run(['fs_cli', '-x', 'status'])->output;
            str_contains($status, 'UP') ? $ok[] = 'freeswitch' : $failed[] = 'freeswitch';
        } catch (Throwable) {
            $failed[] = 'freeswitch';
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    /**
     * Restart one unit without letting a failure abort the sweep.
     */
    private function restart(string $unit): void
    {
        try {
            $this->runner->run(['systemctl', 'restart', $unit]);
        } catch (Throwable) {
            // The post-restart state check decides whether this matters.
        }
    }

    /**
     * Report whether one unit is active right now.
     */
    private function isActive(string $unit): bool
    {
        try {
            return $this->runner->run(['systemctl', 'is-active', '--quiet', $unit])->ok();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build the helper invocation: direct as root, through sudo for www-data.
     *
     * @return list<string>
     */
    private function helperCommand(): array
    {
        return self::isRoot() ? [self::UPDATE_WORKER_HELPER] : ['sudo', '-n', self::UPDATE_WORKER_HELPER];
    }

    /**
     * Report whether this process runs as root.
     */
    private static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * Resolve the running PHP-FPM unit from the PHP version, never hardcoded.
     */
    private static function phpFpmUnit(): string
    {
        return 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-fpm';
    }
}
