<?php

declare(strict_types=1);

/**
 * Validates that every module namespace resolves correctly through
 * Composer's autoloader (previously validated modules_autoload.php).
 *
 * Modules are now autoloaded via Composer path repositories.
 */

/**
 * Converts a hyphenated module directory name to a PSR-4 namespace
 * prefix.
 *
 * Examples:
 *   'sip-accounts'   => 'Modules\\SipAccounts\\'
 *   'call-forwards'  => 'Modules\\CallForwards\\'
 *   'xml-cdr'        => 'Modules\\XmlCdr\\'
 */
function moduleNamespace(string $dirName): string
{
    $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $dirName)));

    return "Modules\\{$studly}\\";
}

test('every module provider class is autoloadable via Composer', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));

    expect($manifests)->not->toBeEmpty('No module manifests found.');

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

test('the backups module is installed through its Composer path repository', function (): void {
    /** @var array{repositories: array<int, array{type: string, url: string}>, require: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['repositories'])->toContain([
        'type' => 'path',
        'url' => 'app-modules/backups',
    ])->and($composer['require'])->toHaveKey('tallpbx/module-backups', '@dev');
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
