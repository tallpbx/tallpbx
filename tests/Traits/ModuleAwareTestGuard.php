<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Str;

/**
 * Marks tests as skipped when they depend on a module that is not installed.
 *
 * Uninstalling a module removes its source files and deletes its central
 * test folder. Any remaining test that references the module would fail
 * with class-not-found errors; this trait turns those into skipped tests
 * so the suite stays green while the module is gone.
 */
trait ModuleAwareTestGuard
{
    /**
     * Skip the current test when the given kebab-case module is not installed.
     */
    protected function skipWhenModuleUninstalled(string $module): void
    {
        if (! $this->moduleIsInstalled($module)) {
            $this->markTestSkipped("Module [{$module}] is not installed.");
        }
    }

    /**
     * Skip the current test when any module referenced by its file is gone.
     */
    protected function skipWhenReferencedModuleUninstalled(): void
    {
        foreach ($this->referencedModuleNames() as $module) {
            $this->skipWhenModuleUninstalled($module);
        }
    }

    /**
     * Extract kebab-case module names from the calling test file's source.
     *
     * @return array<int, string>
     */
    protected function referencedModuleNames(): array
    {
        static $cache = [];

        $file = $this->currentTestFile();

        if (! isset($cache[$file])) {
            $contents = @file_get_contents($file);
            $cache[$file] = $contents === false ? [] : $this->extractModuleNames($contents);
        }

        return $cache[$file];
    }

    /**
     * Find the running test's file.
     *
     * Pest exposes the test file as a static $__filename on the generated
     * test class — the only reliable source inside a beforeEach hook, where
     * the backtrace contains just the hook and vendor frames. Non-Pest
     * runs fall back to scanning the backtrace.
     */
    private function currentTestFile(): string
    {
        if (property_exists(static::class, '__filename') && is_string(static::$__filename ?? null)) {
            return static::$__filename;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? '';

            if (str_contains($file, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)
                && ! str_contains($file, 'ModuleAwareTestGuard.php')) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Extract kebab-case module names from test source text.
     *
     * @return array<int, string>
     */
    protected function extractModuleNames(string $contents): array
    {
        preg_match_all('/Modules\\\\([A-Za-z0-9_]+)\\\\/', $contents, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $studly): string => Str::kebab($studly))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a module is installed in this checkout (local or vendor).
     */
    protected function moduleIsInstalled(string $name): bool
    {
        static $installed = null;

        if ($installed === null) {
            // Scan once per process; each test process runs on one checkout.
            $installed = collect(array_merge(
                glob(base_path('app-modules/*/module.json')) ?: [],
                glob(base_path('vendor/*/*/module.json')) ?: [],
            ))->map(function (string $path): ?string {
                $manifest = json_decode((string) @file_get_contents($path), true);

                return is_array($manifest) ? ($manifest['name'] ?? null) : null;
            })->filter()->flip();
        }

        return $installed->has($name);
    }
}
