<?php

declare(strict_types=1);

it('keeps standard module providers behind the lifecycle-aware base provider', function () {
    $providerPaths = moduleProviderPaths();

    expect($providerPaths)->not->toBeEmpty();

    foreach ($providerPaths as $path) {
        if (moduleNameFromProviderPath($path) === 'admin') {
            continue;
        }

        expect(file_get_contents($path))
            ->toContain('extends \\App\\Support\\ModuleServiceProvider');
    }
});

it('limits custom module boot logic to reviewed exceptional modules', function () {
    $providersWithCustomBoot = collect(moduleProviderPaths())
        ->filter(fn (string $path): bool => preg_match('/public\s+function\s+boot\s*\(/', (string) file_get_contents($path)) === 1)
        ->map(fn (string $path): string => moduleNameFromProviderPath($path))
        ->values()
        ->all();

    // Admin owns bespoke permission setup. File Stores additionally registers
    // its reconciliation command only for Artisan, which the base provider's
    // declarative lifecycle does not currently expose.
    expect($providersWithCustomBoot)->toBe(['admin', 'file-stores']);
});

it('keeps visible runtime registration out of non-admin module register methods', function () {
    $forbiddenPatterns = [
        '/loadRoutesFrom\s*\(/',
        '/Livewire::/',
        '/app\s*\(\s*MenuService::class/',
        '/app\s*\(\s*PermissionService::class/',
        '/(?<![A-Za-z0-9_\\\\])Route::/',
        '/(?<![A-Za-z0-9_\\\\])Event::/',
        '/(?<![A-Za-z0-9_\\\\])Schedule::/',
        '/(?<![A-Za-z0-9_\\\\])Gate::/',
    ];

    foreach (moduleProviderPaths() as $path) {
        if (moduleNameFromProviderPath($path) === 'admin') {
            continue;
        }

        $contents = phpCodeWithoutComments((string) file_get_contents($path));

        foreach ($forbiddenPatterns as $pattern) {
            expect(preg_match($pattern, $contents))->toBe(0);
        }
    }
});

/**
 * Return every module service provider path.
 *
 * @return array<int, string>
 */
function moduleProviderPaths(): array
{
    return glob(base_path('app-modules/*/src/Providers/ModuleServiceProvider.php')) ?: [];
}

/**
 * Resolve the module directory name from a provider path.
 */
function moduleNameFromProviderPath(string $path): string
{
    return basename(dirname($path, 3));
}

/**
 * Strip comments from PHP source before checking executable calls.
 */
function phpCodeWithoutComments(string $source): string
{
    $tokens = token_get_all($source);
    $code = '';

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}
