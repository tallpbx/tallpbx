<?php

declare(strict_types=1);

use App\Support\ModuleDependencySorter;

/**
 * Validates that all module.json manifests contain the required fields,
 * conform to schema constraints, declare unique priorities, enforce
 * core invariants, and can be topologically sorted without cycles.
 */
test('all module manifests contain required fields and conform to schema constraints', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/module.json')), true);
    $requiredKeys = ['name', 'version', 'namespace', 'display_name'];

    // Guard asserting our required-key list equals resources/schemas/module.json's required array
    expect($requiredKeys)->toBe($schema['required']);

    $manifests = glob(base_path('app-modules/*/module.json'));
    expect($manifests)->toHaveCount(60);

    $namePattern = '/'.$schema['properties']['name']['pattern'].'/';
    $versionPattern = '/'.$schema['properties']['version']['pattern'].'/';
    $namespacePattern = '/'.$schema['properties']['namespace']['pattern'].'/';
    $allowedCategories = $schema['properties']['category']['enum'];

    $errors = [];

    foreach ($manifests as $path) {
        $relative = str_replace(base_path('app-modules/'), '', $path);
        $json = json_decode((string) file_get_contents($path), true);

        // Required keys
        foreach ($requiredKeys as $key) {
            if (! isset($json[$key]) || (is_string($json[$key]) && trim($json[$key]) === '')) {
                $errors[] = "{$relative}: missing required field '{$key}'";
            }
        }

        // Name pattern
        if (isset($json['name']) && ! preg_match($namePattern, (string) $json['name'])) {
            $errors[] = "{$relative}: invalid name pattern '{$json['name']}'";
        }

        // Version pattern
        if (isset($json['version']) && ! preg_match($versionPattern, (string) $json['version'])) {
            $errors[] = "{$relative}: invalid version pattern '{$json['version']}'";
        }

        // Namespace pattern
        if (isset($json['namespace']) && ! preg_match($namespacePattern, (string) $json['namespace'])) {
            $errors[] = "{$relative}: invalid namespace pattern '{$json['namespace']}'";
        }

        // Category enum
        if (isset($json['category']) && ! in_array($json['category'], $allowedCategories, true)) {
            $errors[] = "{$relative}: invalid category '{$json['category']}'";
        }

        // Types
        if (isset($json['priority']) && ! is_int($json['priority'])) {
            $errors[] = "{$relative}: priority must be an integer";
        }
        if (isset($json['required']) && ! is_bool($json['required'])) {
            $errors[] = "{$relative}: required must be a boolean";
        }
        if (isset($json['protected']) && ! is_bool($json['protected'])) {
            $errors[] = "{$relative}: protected must be a boolean";
        }
        if (isset($json['providers']) && ! is_array($json['providers'])) {
            $errors[] = "{$relative}: providers must be an array";
        }

        // Requirements.packages item shape {name, version}
        if (isset($json['requirements']['packages'])) {
            if (! is_array($json['requirements']['packages'])) {
                $errors[] = "{$relative}: requirements.packages must be an array";
            } else {
                foreach ($json['requirements']['packages'] as $idx => $pkg) {
                    if (! is_array($pkg) || ! isset($pkg['name']) || ! isset($pkg['version']) || ! is_string($pkg['name']) || ! is_string($pkg['version'])) {
                        $errors[] = "{$relative}: package requirement at index {$idx} must be an object with string name and version";
                    }
                }
            }
        }
    }

    expect($errors)->toBeEmpty(implode("\n", $errors));
});

test('the manifest schema allows only canonical kebab-case module names', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/module.json')), true);

    // Underscore names cannot round-trip through the kebab-case mapping used
    // for dependency matching, so the schema must reject them everywhere a
    // module machine name appears.
    expect($schema['properties']['name']['pattern'])->toBe('^[a-z][a-z0-9-]*$')
        ->and($schema['properties']['requirements']['properties']['modules']['items']['pattern'])->toBe('^[a-z][a-z0-9-]*$');
});

test('the manifest schema accepts the namespaces both module sources scaffold', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/module.json')), true);

    // make:module stamps CustomModules\{Studly} into every scaffolded
    // manifest and references this schema from them, so validating a fresh
    // scaffold against the schema must not flag its own namespace.
    $pattern = '/'.$schema['properties']['namespace']['pattern'].'/';

    expect(preg_match($pattern, 'Modules\CallForwards'))->toBe(1)
        ->and(preg_match($pattern, 'CustomModules\NativeCommandTest'))->toBe(1)
        // Malformed namespaces must still be rejected by the same pattern.
        ->and(preg_match($pattern, 'custommodules\Lowercase'))->toBe(0)
        ->and(preg_match($pattern, 'Modules\lowercase'))->toBe(0)
        ->and(preg_match($pattern, 'ThirdParty\Plugin'))->toBe(0);
});

test('marks the core PBX modules as protected or required', function (): void {
    $core = [
        'admin', 'auth', 'tenant',
        'extensions', 'devices', 'sip-accounts', 'destinations',
        'dialplans', 'dialplan-tools', 'gateways', 'sip-profiles',
        'inbound-routes', 'outbound-routes',
    ];

    foreach ($core as $name) {
        $manifest = json_decode((string) file_get_contents(base_path("app-modules/{$name}/module.json")), true);

        expect(($manifest['protected'] ?? false) || ($manifest['required'] ?? false))
            ->toBeTrue("Module [{$name}] must be protected or required");
    }
});

test('built-in module manifests declare unique priority integers', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));
    expect($manifests)->toHaveCount(60);

    $priorities = [];
    $duplicates = [];

    foreach ($manifests as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        $name = $data['name'];
        $priority = $data['priority'] ?? null;

        if ($priority !== null) {
            $priorities[$priority][] = $name;
            if (count($priorities[$priority]) > 1) {
                $duplicates[$priority] = $priorities[$priority];
            }
        }
    }

    expect($duplicates)->toBeEmpty('Duplicate priorities found: '.json_encode($duplicates));
});

test('every required module manifest also declares protected true', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));
    $violations = [];

    foreach ($manifests as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        if (($data['required'] ?? false) === true && ($data['protected'] ?? false) !== true) {
            $violations[] = $data['name'];
        }
    }

    expect($violations)->toBeEmpty('Required modules must also be protected: '.implode(', ', $violations));
});

test('every required module reference exists on disk and has no dependency cycles', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));
    $allModules = [];

    foreach ($manifests as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        $allModules[$data['name']] = $data;
    }

    $dangling = [];
    foreach ($allModules as $name => $data) {
        $deps = $data['requirements']['modules'] ?? [];
        foreach ($deps as $dep) {
            if (! is_dir(base_path("app-modules/{$dep}"))) {
                $dangling[] = "{$name} -> {$dep}";
            }
        }
    }

    expect($dangling)->toBeEmpty('Dangling module dependencies: '.implode(', ', $dangling));
});

test('manifest priorities are strictly greater than their prerequisites', function () {
    $manifestPaths = glob(base_path('app-modules/*/module.json'));
    $manifests = [];
    foreach ($manifestPaths as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        $manifests[$data['name']] = $data;
    }

    $inversions = [];
    foreach ($manifests as $name => $manifest) {
        $priority = $manifest['priority'] ?? 0;
        $deps = $manifest['requirements']['modules'] ?? [];
        foreach ($deps as $dep) {
            $depPriority = $manifests[$dep]['priority'] ?? 0;
            if ($depPriority >= $priority) {
                $inversions[] = "{$name} ({$priority}) depends on {$dep} ({$depPriority})";
            }
        }
    }

    expect($inversions)->toBeEmpty("Priority inversions found:\n".implode("\n", $inversions));
});

test('topological sorter places prerequisites strictly before dependents', function () {
    $manifestPaths = glob(base_path('app-modules/*/module.json'));
    $manifests = [];
    foreach ($manifestPaths as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        $manifests[$data['name']] = $data;
    }

    $sorted = ModuleDependencySorter::sort($manifests);

    expect(count($sorted))->toBe(60);

    $positions = array_flip(array_keys($sorted));

    // Specifically verify the known prerequisite relationships
    expect($positions['voicemails'])->toBeLessThan($positions['extensions']);
    expect($positions['gateways'])->toBeLessThan($positions['sip-profiles']);
    expect($positions['file-stores'])->toBeLessThan($positions['backups']);
    expect($positions['backups'])->toBeLessThan($positions['admin']);
    expect($positions['devices'])->toBeLessThan($positions['hot-desking']);
    expect($positions['extensions'])->toBeLessThan($positions['hot-desking']);

    // Comprehensively verify ALL requirements across all manifests
    foreach ($sorted as $name => $manifest) {
        $deps = $manifest['requirements']['modules'] ?? [];
        foreach ($deps as $dep) {
            expect($positions[$dep])->toBeLessThan(
                $positions[$name],
                "Prerequisite [{$dep}] must load before dependent [{$name}]"
            );
        }
    }
});

test('topological sorter detects dependency cycles and unknown dependencies', function () {
    // Unknown dependency
    $unknown = [
        'a' => ['name' => 'a', 'requirements' => ['modules' => ['missing']]],
    ];
    expect(fn () => ModuleDependencySorter::sort($unknown))
        ->toThrow(LogicException::class);

    // Direct cycle
    $cycle = [
        'a' => ['name' => 'a', 'requirements' => ['modules' => ['b']]],
        'b' => ['name' => 'b', 'requirements' => ['modules' => ['a']]],
    ];
    expect(fn () => ModuleDependencySorter::sort($cycle))
        ->toThrow(LogicException::class);

    // Self dependency
    $self = [
        'a' => ['name' => 'a', 'requirements' => ['modules' => ['a']]],
    ];
    expect(fn () => ModuleDependencySorter::sort($self))
        ->toThrow(LogicException::class);
});
