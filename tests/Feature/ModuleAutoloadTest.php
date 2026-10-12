<?php

declare(strict_types=1);

/**
 * Validates that every built-in module namespace resolves directly
 * through Composer's root PSR-4 autoloader, without path repositories
 * or package symlinks.
 */

/**
 * Converts a hyphenated module directory name to a PSR-4 namespace prefix.
 */
function moduleNamespace(string $dirName): string
{
    $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $dirName)));

    return "Modules\\{$studly}\\";
}

test('every built-in module provider class is autoloadable via root psr-4', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));

    expect($manifests)->toHaveCount(60, 'Expected exactly 60 built-in module manifests.');

    $missing = [];

    foreach ($manifests as $manifestPath) {
        $json = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($json) || empty($json['namespace'])) {
            continue;
        }

        $namespace = $json['namespace'];
        $providerClass = $namespace.'\\Providers\\ModuleServiceProvider';

        if (! class_exists($providerClass)) {
            $missing[] = $providerClass;
        }
    }

    expect($missing)->toBeEmpty(
        'The following module provider classes are not autoloadable: '
        .implode(', ', $missing)
    );
});

test('root composer.json contains zero path repositories and zero pseudo-package requirements', function (): void {
    /** @var array{repositories?: array<mixed>, require?: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['repositories'] ?? [])->toBeEmpty('Root composer.json should not contain any repositories.');

    $moduleRequires = array_filter(
        array_keys($composer['require'] ?? []),
        fn (string $pkg) => str_starts_with($pkg, 'tallpbx/module-')
    );

    expect($moduleRequires)->toBeEmpty('Root composer.json should not require any tallpbx/module-* pseudo-packages.');
});

test('root composer.json contains direct psr-4 mappings for all 60 built-in modules', function (): void {
    /** @var array{autoload?: array{psr-4?: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $psr4 = $composer['autoload']['psr-4'] ?? [];
    $dirs = glob(base_path('app-modules/*'), GLOB_ONLYDIR);

    expect($dirs)->toHaveCount(60);

    foreach ($dirs as $dir) {
        $name = basename($dir);
        $expectedNs = moduleNamespace($name);
        $expectedPath = "app-modules/{$name}/src/";

        expect($psr4)->toHaveKey($expectedNs)
            ->and($psr4[$expectedNs])->toBe($expectedPath);
    }
});

test('all module directories resolve to the expected namespace prefix', function (string $dir, string $expectedNs) {
    expect(moduleNamespace($dir))->toBe($expectedNs);
})->with([
    ['sip-accounts', 'Modules\\SipAccounts\\'],
    ['call-forwards', 'Modules\\CallForwards\\'],
    ['xml-cdr', 'Modules\\XmlCdr\\'],
    ['sip-profiles', 'Modules\\SipProfiles\\'],
    ['conference-centers', 'Modules\\ConferenceCenters\\'],
    ['call-center-active', 'Modules\\CallCenterActive\\'],
    ['time-conditions', 'Modules\\TimeConditions\\'],
]);
