<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Smart test runner that provides tiered execution modes.
 *
 * Usage:
 *   php artisan app:test              Run feature tests (default, --parallel)
 *   php artisan app:test --smoke      Run critical-path smoke tests only
 *   php artisan app:test --full       Run feature + Dusk browser tests
 *   php artisan app:test --sequential Run without --parallel
 *   php artisan app:test --clear-cache Clear Laravel caches before testing
 *
 * The default mode runs all Pest feature tests in parallel
 * with compact output (~65s for 935 tests). This is the recommended
 * mode for day-to-day development.
 *
 * --smoke runs a targeted subset covering auth, routing, module
 * integrity, and XML handler correctness (~30s for ~80 tests).
 * Use this for quick verification after small changes.
 *
 * --full runs the default feature suite followed by Dusk browser
 * tests. This takes ~150s total and should be run before commits
 * that touch the panel UI or FreeSWITCH integration.
 */
class TestCommand extends Command
{
    protected $signature = 'app:test
                            {--smoke : Run only critical-path smoke tests}
                            {--full : Run feature tests + Dusk browser tests}
                            {--sequential : Disable parallel execution}
                            {--clear-cache : Clear Laravel caches before running tests}';

    protected $description = 'Run the test suite with tiered execution modes';

    /**
     * Execute the test runner in the selected mode.
     */
    public function handle(): int
    {
        if ($this->option('clear-cache')) {
            $this->callSilent('optimize:clear');
        }

        if ($this->option('smoke')) {
            return $this->runSmoke();
        }

        if ($this->option('full')) {
            return $this->runFull();
        }

        return $this->runDefault();
    }

    /**
     * Run critical-path smoke tests — fast verification of core integrity.
     *
     * Covers auth, routing, module autoloading, XML handler, and
     * ESL integration. Skips per-module CRUD tests and slow
     * feature tests.
     */
    private function runSmoke(): int
    {
        $filter = implode('|', [
            // Module system — modules must load and routes compile
            'ModuleAutoloadTest',
            'ModuleManifestTest',
            'ModuleServiceProviderTest',
            // XML Handler — FreeSWITCH mod_xml_curl must return valid XML
            'XmlHandlerControllerTest',
            'XmlHandlerFixtureTest',
            'PbxXmlHandlerSmokeTest',
            // ESL integration — socket protocol must work
            'FreeSwitchServiceIntegrationTest',
            // Tenant isolation — cross-tenant data must not leak
            'CrossTenantIsolationTest',
            'BelongsToTenantTest',
            // Core services
            'TenantContextTest',
            'TenantIdentityResolverTest',
            'PermissionSyncTest',
            'MenuServiceTest',
            // Config integrity
            'FreeswitchConfigTest',
        ]);

        $this->info('Running smoke tests…');

        return $this->runPest([
            '--filter' => $filter,
            '--compact',
        ]);
    }

    /**
     * Run the full suite — feature tests + Dusk browser tests.
     *
     * Feature tests run first (in parallel). If they pass, Dusk
     * browser tests follow. If feature tests fail, Dusk is skipped
     * to save time.
     */
    private function runFull(): int
    {
        $this->info('Step 1/2: Running feature tests…');

        $featureExit = $this->runPest(['--compact']);

        if ($featureExit !== 0) {
            $this->error('Feature tests failed — skipping Dusk browser tests.');

            return $featureExit;
        }

        $this->info('Step 2/2: Running Dusk browser tests…');

        return $this->runDusk();
    }

    /**
     * Run the default suite — all feature tests in parallel.
     */
    private function runDefault(): int
    {
        $this->info('Running feature tests…');

        return $this->runPest(['--compact']);
    }

    /**
     * Run Pest with standard flags.
     *
     * --parallel is used by default unless --sequential is passed.
     * --compact keeps output concise.
     *
     * @param  array<string, string>  $extraArgs  Key-value pairs of flags
     */
    private function runPest(array $extraArgs = []): int
    {
        $args = [base_path('vendor/bin/pest')];
        foreach ($extraArgs as $key => $value) {
            if (is_int($key)) {
                $args[] = $value;
            } else {
                $args[] = "{$key}={$value}";
            }
        }

        // Use parallel execution by default. Pest chooses a sensible process
        // count for the host.
        if (! $this->option('sequential')) {
            $args[] = '--parallel';
        }

        $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, ...$args]));

        $this->line("<comment>\$ {$cmd}</comment>");

        $process = new Process(
            [PHP_BINARY, ...$args],
            base_path(),
            $this->cleanTestingEnvironment(),
            null,
            null,
        );

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? 1;
    }

    /**
     * Return an environment where PHPUnit can apply the forced test settings.
     *
     * Artisan loads the application's .env into the parent process. This
     * command starts Pest directly, so remove those inherited values before
     * PHPUnit applies the in-memory SQLite and temporary array-store settings.
     *
     * @return array<string, string|false>
     */
    private function cleanTestingEnvironment(): array
    {
        $environment = getenv();

        if (! is_array($environment)) {
            return [];
        }

        foreach ($this->dotenvKeys() as $key) {
            $environment[$key] = false;
        }

        return $environment;
    }

    /**
     * Read the local .env keys that should not leak into Pest runs.
     *
     * @return array<int, string>
     */
    private function dotenvKeys(): array
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            return [];
        }

        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($contents === false) {
            return [];
        }

        $keys = [];

        foreach ($contents as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches) === 1) {
                $keys[] = $matches[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Run Laravel Dusk browser tests.
     *
     * Requires Chromium and ChromeDriver.
     */
    private function runDusk(): int
    {
        $cmd = 'bash scripts/dusk.sh';

        $this->line("<comment>\$ {$cmd}</comment>");

        $process = new Process(['bash', base_path('scripts/dusk.sh')], base_path());
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
