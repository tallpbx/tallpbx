<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use App\Contracts\ProcessRunner;
use App\Support\ProcessResult;
use Illuminate\Support\Facades\File;

const LOAD_TEST_MANIFEST_DIR = 'storage/framework/testing/load-tests/manifest';

/**
 * Build a frozen process runner that answers the probes the manifest makes.
 *
 * Keys are the space-joined argument vectors the command executes, so a
 * missing probe fails closed instead of reaching the real host.
 *
 * @param  array<string, array{0: int, 1: string}>  $responses  Exit code and output per command line
 */
function fakeManifestProcessRunner(array $responses): ProcessRunner
{
    return new class($responses) implements ProcessRunner
    {
        /**
         * Receive the canned probe responses.
         *
         * @param  array<string, array{0: int, 1: string}>  $responses
         */
        public function __construct(private readonly array $responses) {}

        /**
         * Return the canned exit code and output registered for this command line.
         *
         * @param  array<int, string>  $argv
         * @param  array<string, string>  $env
         */
        public function run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult
        {
            [$exitCode, $output] = $this->responses[implode(' ', $argv)] ?? [127, 'command not found'];

            return new ProcessResult($exitCode, $output);
        }
    };
}

/**
 * Create the temporary .env, PHP-FPM pool and output paths used by the tests.
 *
 * The fixture .env mixes quoted values, comments and secrets so the parser,
 * the unquoting and the redaction are all exercised in one place.
 *
 * @return array{env: string, pool: string, output: string}
 */
function manifestFixtures(): array
{
    $directory = base_path(LOAD_TEST_MANIFEST_DIR);
    File::ensureDirectoryExists($directory);

    $paths = [
        'env' => $directory.'/.env',
        'pool' => $directory.'/www.conf',
        'output' => $directory.'/manifest.json',
    ];

    File::put($paths['env'], implode(PHP_EOL, [
        '# Managed by the installer',
        'APP_ENV=production',
        'APP_URL="https://pbx.test"',
        'APP_KEY=base64:app-key-value',
        'DB_PASSWORD=db-password-value',
        'MAIL_PASSWORD="mail-password-value"',
        'REDIS_PASSWORD=redis-password-value',
        'FS_XML_HANDLER_TOKEN=handler-token-value',
        'FREESWITCH_CDR_TOKEN=cdr-token-value',
        'SOME_SECRET_VALUE=secret-value',
        'FS_LOG_LEVEL=notice',
    ]).PHP_EOL);

    File::put($paths['pool'], implode(PHP_EOL, [
        '; Start a new pool named www.',
        'pm = static',
        'pm.max_children = 12',
    ]).PHP_EOL);

    File::delete($paths['output']);

    return $paths;
}

/*
|--------------------------------------------------------------------------
| Load-Test Environment Manifest
|--------------------------------------------------------------------------
*/

describe('pbx load test manifest command', function (): void {
    it('writes every manifest section and redacts secrets', function (): void {
        $paths = manifestFixtures();

        app()->instance(ProcessRunner::class, fakeManifestProcessRunner([
            'git branch --show-current' => [0, '5.x'],
            'git rev-parse HEAD' => [0, str_repeat('a', 40)],
            'git status --porcelain' => [0, ''],
            'fs_cli -x version' => [0, 'FreeSWITCH Version 1.11.4+git~20260101T000000Z'],
            'fs_cli -x fsctl loglevel' => [0, 'notice'],
            'redis-server -v' => [0, 'Redis server v=7.0.15 sha=00000000:0 malloc=jemalloc bits=64'],
            'mariadb --version' => [0, 'mariadb  Ver 15.1 Distrib 11.1.2-MariaDB, for debian-linux-gnu'],
            'nproc' => [0, '2'],
            'mountpoint -q /var/lib/freeswitch/db' => [0, ''],
            'findmnt -n -o FSTYPE --target /var/lib/freeswitch/db' => [0, 'tmpfs'],
            'nft list ruleset' => [0, "table inet tallpbx_filter {\n\tchain input {\n\t\trule ip saddr 10.0.0.0/8 accept\n\t}\n}\n"],
        ]));

        $this->artisan('pbx:load-test:manifest', [
            '--output' => $paths['output'],
            '--env-file' => $paths['env'],
            '--fpm-pool' => $paths['pool'],
        ])->assertSuccessful();

        expect(File::exists($paths['output']))->toBeTrue();

        $raw = (string) file_get_contents($paths['output']);
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($manifest))->toBe([
            'build',
            'host',
            'runtime',
            'configuration',
            'firewall',
            'recorded_at',
        ])
            ->and($manifest['build']['branch'])->toBe('5.x')
            ->and($manifest['build']['commit'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($manifest['build']['dirty'])->toBeFalse()
            ->and($manifest['host']['cpu_cores'])->toBe(2)
            ->and($manifest['host']['hostname'])->toBeString()
            ->and($manifest['runtime']['freeswitch_version'])->toContain('1.11.4')
            ->and($manifest['runtime']['freeswitch_log_level'])->toBe('notice')
            ->and($manifest['runtime']['redis_version'])->toContain('7.0.15')
            ->and($manifest['runtime']['mariadb_version'])->toContain('11.1.2')
            ->and($manifest['configuration']['php_fpm'])->toBe([
                'pool' => 'static',
                'max_children' => 12,
                'pool_file' => $paths['pool'],
            ])
            ->and($manifest['configuration']['freeswitch']['runtime_db']['mode'])->toBe('tmpfs')
            ->and($manifest['configuration']['freeswitch']['xml_handler']['cache_ttl']['dialplan'])->toBeInt()
            ->and($manifest['configuration']['freeswitch']['cdr']['throttle']['limit'])->toBeInt()
            ->and($manifest['configuration']['env']['APP_ENV'])->toBe('production')
            ->and($manifest['configuration']['env']['APP_URL'])->toBe('https://pbx.test')
            ->and($manifest['configuration']['env']['DB_PASSWORD'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['MAIL_PASSWORD'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['REDIS_PASSWORD'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['FS_XML_HANDLER_TOKEN'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['FREESWITCH_CDR_TOKEN'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['SOME_SECRET_VALUE'])->toBe('[redacted]')
            ->and($manifest['configuration']['env']['APP_KEY'])->toBe('[redacted]')
            ->and($manifest['configuration']['env'])->not->toHaveKey('# Managed by the installer')
            ->and($manifest['firewall']['nftables_active'])->toBeTrue()
            ->and($manifest['firewall']['tallpbx_table_present'])->toBeTrue()
            ->and($manifest['firewall']['ruleset_sha256'])->toBeString()->toHaveLength(64)
            ->and($manifest['recorded_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');

        // No fixture secret may survive anywhere in the written artifact.
        foreach (['db-password-value', 'mail-password-value', 'redis-password-value', 'handler-token-value', 'cdr-token-value', 'secret-value', 'app-key-value'] as $secret) {
            expect($raw)->not->toContain($secret);
        }
    });

    it('records a disk-backed FreeSWITCH runtime database when the path is not a mountpoint', function (): void {
        $paths = manifestFixtures();

        app()->instance(ProcessRunner::class, fakeManifestProcessRunner([
            'git branch --show-current' => [0, '5.x'],
            'git rev-parse HEAD' => [0, str_repeat('b', 40)],
            'git status --porcelain' => [0, ' M app/Foo.php'],
            'nproc' => [0, '1'],
            'mountpoint -q /var/lib/freeswitch/db' => [1, '/var/lib/freeswitch/db is not a mountpoint'],
            'nft list ruleset' => [1, 'Operation not permitted'],
        ]));

        $this->artisan('pbx:load-test:manifest', [
            '--output' => $paths['output'],
            '--env-file' => $paths['env'],
            '--fpm-pool' => $paths['pool'],
        ])->assertSuccessful();

        $manifest = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);

        // Unavailable probes stay null instead of inventing a value.
        expect($manifest['build']['dirty'])->toBeTrue()
            ->and($manifest['configuration']['freeswitch']['runtime_db']['mode'])->toBe('disk')
            ->and($manifest['runtime']['freeswitch_version'])->toBeNull()
            ->and($manifest['runtime']['redis_version'])->toBeNull()
            ->and($manifest['runtime']['mariadb_version'])->toBeNull()
            ->and($manifest['firewall']['nftables_active'])->toBeFalse()
            ->and($manifest['firewall']['ruleset_sha256'])->toBeNull();
    });
});
