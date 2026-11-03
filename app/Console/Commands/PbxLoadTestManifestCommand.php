<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ProcessRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Writes a machine-readable snapshot of the host a load-test cell runs on.
 *
 * A benchmark number is only comparable when the build, hardware, runtime
 * versions and switch settings travel with it. This command probes the host
 * read-only — no service is reloaded and no nftables state is touched — so
 * the orchestrator can store the result next to the raw run artifacts.
 */
#[Signature('pbx:load-test:manifest
    {--output=storage/app/load-tests/manifest.json : JSON manifest output path}
    {--env-file= : .env file to snapshot; defaults to the application .env}
    {--fpm-pool= : PHP-FPM pool file to inspect; auto-detected when omitted}')]
#[Description('Write a machine-readable environment manifest for load-test runs')]
class PbxLoadTestManifestCommand extends Command
{
    /**
     * Collect the manifest sections and write them as pretty-printed JSON.
     */
    public function handle(ProcessRunner $runner): int
    {
        $outputPath = $this->resolvePath($this->stringOption('output'));

        $manifest = [
            'build' => $this->buildSection($runner),
            'host' => $this->hostSection($runner),
            'runtime' => $this->runtimeSection($runner),
            'configuration' => $this->configurationSection($runner),
            'firewall' => $this->firewallSection($runner),
            'recorded_at' => now()->toIso8601String(),
        ];

        $directory = dirname($outputPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $outputPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $fpm = $manifest['configuration']['php_fpm'];

        $this->components->info('Load-test environment manifest written.');
        $this->components->twoColumnDetail('Output', $outputPath);
        $this->components->twoColumnDetail('Build', ($manifest['build']['branch'] ?? 'unknown').' @ '.substr((string) ($manifest['build']['commit'] ?? 'unknown'), 0, 12));
        $this->components->twoColumnDetail('Host sizing', ($manifest['host']['cpu_cores'] ?? '?').' vCPU / '.($manifest['host']['memory_mb'] ?? '?').' MB RAM');
        $this->components->twoColumnDetail('PHP-FPM', ($fpm['pool'] ?? 'not detected').' ('.($fpm['max_children'] ?? '?').' workers)');
        $this->components->twoColumnDetail('FreeSWITCH DB', $manifest['configuration']['freeswitch']['runtime_db']['mode']);
        $this->components->twoColumnDetail('Firewall', $manifest['firewall']['nftables_active'] ? 'nftables active' : 'not detected');

        return self::SUCCESS;
    }

    /**
     * Record the checked-out build so a result traces back to one commit.
     *
     * @return array{branch: string|null, commit: string|null, dirty: bool|null}
     */
    private function buildSection(ProcessRunner $runner): array
    {
        $branch = $this->probe($runner, ['git', 'branch', '--show-current']);
        $commit = $this->probe($runner, ['git', 'rev-parse', 'HEAD']);
        $status = $this->probe($runner, ['git', 'status', '--porcelain']);

        return [
            'branch' => $branch === null || $branch === '' ? null : $branch,
            // Only accept a full object name; a short or scripted value would
            // make two different builds look identical in the artifacts.
            'commit' => $commit !== null && preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null,
            'dirty' => $status === null ? null : $status !== '',
        ];
    }

    /**
     * Record the hardware sizing the tier claims to provide.
     *
     * @return array<string, int|string|null>
     */
    private function hostSection(ProcessRunner $runner): array
    {
        $memory = $this->memoryInfo();

        return [
            'hostname' => gethostname() ?: null,
            'os' => $this->osRelease(),
            'kernel' => php_uname('r'),
            'architecture' => php_uname('m'),
            'cpu_cores' => $this->integerOrNull($this->probe($runner, ['nproc'])),
            'memory_mb' => $memory['total_mb'],
            'available_mb' => $memory['available_mb'],
            'swap_total_mb' => $memory['swap_total_mb'],
            'swap_free_mb' => $memory['swap_free_mb'],
        ];
    }

    /**
     * Record the runtime versions the measurements depend on.
     *
     * PHP reports itself, so only external daemons are probed; a probe that
     * fails (daemon down, binary absent) stays null instead of guessing.
     *
     * @return array<string, string|null>
     */
    private function runtimeSection(ProcessRunner $runner): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'freeswitch_version' => $this->probe($runner, ['fs_cli', '-x', 'version']),
            'freeswitch_log_level' => $this->probe($runner, ['fs_cli', '-x', 'fsctl loglevel']),
            'redis_version' => $this->probe($runner, ['redis-server', '-v']),
            'mariadb_version' => $this->probe($runner, ['mariadb', '--version']),
        ];
    }

    /**
     * Record every switch setting a cell is allowed to change.
     *
     * @return array<string, mixed>
     */
    private function configurationSection(ProcessRunner $runner): array
    {
        return [
            'app_env' => (string) config('app.env'),
            'app_url' => (string) config('app.url'),
            'cache_store' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
            'session_driver' => (string) config('session.driver'),
            'php_fpm' => $this->phpFpmProfile(),
            'xml_path' => $this->xmlServingPath(),
            'freeswitch' => [
                'log_level' => (string) config('freeswitch.switch.loglevel', 'notice'),
                'runtime_db' => $this->runtimeDatabase($runner),
                'xml_handler' => [
                    'path' => (string) config('freeswitch.xml_handler.path'),
                    'cache_ttl' => [
                        'master' => (int) config('freeswitch.xml_handler.cache_ttl'),
                        'dialplan' => (int) config('freeswitch.xml_handler.dialplan_cache_ttl'),
                        'contributor' => (int) config('freeswitch.xml_handler.dialplan_contributor_cache_ttl'),
                        'directory' => (int) config('freeswitch.xml_handler.directory_cache_ttl'),
                        'acl' => (int) config('freeswitch.xml_handler.acl_cache_ttl'),
                    ],
                    'throttle' => [
                        'enabled' => (bool) config('freeswitch.xml_handler.throttle_enabled'),
                        'limit' => (int) config('freeswitch.xml_handler.throttle_limit'),
                        'decay_minutes' => (int) config('freeswitch.xml_handler.throttle_decay_minutes'),
                    ],
                ],
                'cdr' => [
                    'throttle' => [
                        'enabled' => (bool) config('freeswitch.cdr.throttle_enabled'),
                        'limit' => (int) config('freeswitch.cdr.throttle_limit'),
                        'decay_minutes' => (int) config('freeswitch.cdr.throttle_decay_minutes'),
                    ],
                    'spool_dir' => (string) config('freeswitch.cdr.spool_dir'),
                    'quarantine_dir' => (string) config('freeswitch.cdr.quarantine_dir'),
                ],
            ],
            'env' => $this->envSnapshot(),
        ];
    }

    /**
     * Read the PHP-FPM pool mode and worker cap.
     *
     * @return array{pool: string|null, max_children: int|null, pool_file: string|null}
     */
    private function phpFpmProfile(): array
    {
        $path = $this->nullableStringOption('fpm-pool') ?? $this->detectFpmPool();

        if ($path === null || ! is_readable($path)) {
            return ['pool' => null, 'max_children' => null, 'pool_file' => null];
        }

        $contents = (string) file_get_contents($path);
        $pool = null;
        $children = null;

        if (preg_match('/^\s*pm\s*=\s*(\w+)/m', $contents, $matches) === 1) {
            $pool = strtolower($matches[1]);
        }

        if (preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', $contents, $matches) === 1) {
            $children = (int) $matches[1];
        }

        return ['pool' => $pool, 'max_children' => $children, 'pool_file' => $path];
    }

    /**
     * Find the PHP-FPM pool file for the running PHP version, if installed.
     */
    private function detectFpmPool(): ?string
    {
        $current = '/etc/php/'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'/fpm/pool.d/www.conf';

        if (is_readable($current)) {
            return $current;
        }

        $candidates = glob('/etc/php/*/fpm/pool.d/www.conf') ?: [];

        return $candidates[0] ?? null;
    }

    /**
     * Determine whether FreeSWITCH runs its runtime database on disk or tmpfs.
     *
     * The scaling guide moves the SQLite runtime database to tmpfs; a cell
     * measured with the disk-backed default is not comparable to one that is
     * not, so both the mount state and the filesystem type are recorded.
     *
     * @return array{mode: string, path: string, mounted: bool, filesystem: string|null}
     */
    private function runtimeDatabase(ProcessRunner $runner): array
    {
        $path = '/var/lib/freeswitch/db';
        $mounted = $runner->run(['mountpoint', '-q', $path], timeoutSeconds: 5)->ok();
        $filesystem = $this->probe($runner, ['findmnt', '-n', '-o', 'FSTYPE', '--target', $path]);

        return [
            'mode' => $filesystem === 'tmpfs' ? 'tmpfs' : 'disk',
            'path' => $path,
            'mounted' => $mounted,
            'filesystem' => $filesystem === '' ? null : $filesystem,
        ];
    }

    /**
     * Detect which front controller nginx serves for the XML handler.
     *
     * @return array{serving: string|null, source: string|null}
     */
    private function xmlServingPath(): array
    {
        $configs = array_merge(
            glob('/etc/nginx/sites-enabled/*') ?: [],
            glob('/etc/nginx/conf.d/*.conf') ?: [],
        );

        // The fast controller wins whenever it appears anywhere, because a
        // stock `location` for the same path may still exist as a fallback.
        foreach ($configs as $config) {
            $contents = @file_get_contents($config);

            if (is_string($contents) && str_contains($contents, 'xml-handler-fast.php')) {
                return ['serving' => 'fast', 'source' => $config];
            }
        }

        foreach ($configs as $config) {
            $contents = @file_get_contents($config);

            if (is_string($contents) && str_contains($contents, 'xml-handler')) {
                return ['serving' => 'stock', 'source' => $config];
            }
        }

        return ['serving' => null, 'source' => null];
    }

    /**
     * Record the firewall state without ever mutating the live ruleset.
     *
     * @return array{nftables_active: bool, tallpbx_table_present: bool, rule_count: int, ruleset_sha256: string|null}
     */
    private function firewallSection(ProcessRunner $runner): array
    {
        $ruleset = $this->probe($runner, ['nft', 'list', 'ruleset']);
        $active = $ruleset !== null && $ruleset !== '';

        // Count concrete rules only; table and chain headers are structure.
        $ruleCount = $active
            ? count(array_filter(explode("\n", $ruleset), fn (string $line): bool => str_starts_with(ltrim($line), 'rule ')))
            : 0;

        return [
            'nftables_active' => $active,
            'tallpbx_table_present' => $active && str_contains($ruleset, 'table inet tallpbx_filter'),
            'rule_count' => $ruleCount,
            'ruleset_sha256' => $active ? hash('sha256', $ruleset) : null,
        ];
    }

    /**
     * Snapshot the .env file with every secret value replaced by a placeholder.
     *
     * @return array<string, string>
     */
    private function envSnapshot(): array
    {
        $path = $this->nullableStringOption('env-file') ?? base_path('.env');

        if (! is_readable($path)) {
            return [];
        }

        $variables = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);

            if ($key === '') {
                continue;
            }

            $variables[$key] = $this->redactedValue($key, trim($parts[1]));
        }

        // Sorted keys keep two manifests diffable between cells.
        ksort($variables);

        return $variables;
    }

    /**
     * Replace a value with a placeholder when its key looks like a secret.
     */
    private function redactedValue(string $key, string $value): string
    {
        // APP_KEY is the file encryption key; it stays secret even though the
        // generic pattern below does not match its name.
        if ($key === 'APP_KEY' || preg_match('/(PASSWORD|TOKEN|SECRET|PAT)/i', $key) === 1) {
            return '[redacted]';
        }

        // Store values without their wrapping quotes so reports compare cleanly.
        if (preg_match('/^"(.*)"$/s', $value, $matches) === 1 || preg_match("/^'(.*)'$/s", $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * Read total, available and swap memory from /proc/meminfo in megabytes.
     *
     * @return array{total_mb: int|null, available_mb: int|null, swap_total_mb: int|null, swap_free_mb: int|null}
     */
    private function memoryInfo(): array
    {
        $contents = is_readable('/proc/meminfo') ? (string) file_get_contents('/proc/meminfo') : '';

        return [
            'total_mb' => $this->meminfoValue($contents, 'MemTotal'),
            'available_mb' => $this->meminfoValue($contents, 'MemAvailable'),
            'swap_total_mb' => $this->meminfoValue($contents, 'SwapTotal'),
            'swap_free_mb' => $this->meminfoValue($contents, 'SwapFree'),
        ];
    }

    /**
     * Convert one /proc/meminfo kibibyte entry into megabytes.
     */
    private function meminfoValue(string $contents, string $key): ?int
    {
        if (preg_match('/^'.$key.':\s+(\d+)\s+kB/m', $contents, $matches) !== 1) {
            return null;
        }

        return (int) round(((int) $matches[1]) / 1024);
    }

    /**
     * Read the human-readable operating system name from /etc/os-release.
     */
    private function osRelease(): ?string
    {
        if (! is_readable('/etc/os-release')) {
            return null;
        }

        $contents = (string) file_get_contents('/etc/os-release');

        return preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $contents, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Run a read-only probe and return its trimmed output.
     *
     * A non-zero exit code means the probe is unavailable, which is recorded
     * as null rather than retried or partially parsed.
     *
     * @param  array<int, string>  $argv
     */
    private function probe(ProcessRunner $runner, array $argv, int $timeoutSeconds = 5): ?string
    {
        $result = $runner->run($argv, base_path(), $timeoutSeconds);

        if (! $result->ok()) {
            return null;
        }

        return trim($result->output);
    }

    /**
     * Cast a numeric probe result to an integer, or null when absent.
     */
    private function integerOrNull(?string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
     * Read an optional string option, treating empty values as absent.
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
     * Resolve a path against the application root.
     *
     * Absolute paths are used as-is; relative paths are resolved from the
     * application root so the runner can pass short artifact paths.
     */
    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
