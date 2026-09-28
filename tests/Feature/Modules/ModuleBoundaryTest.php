<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('declares every cross-module class reference in requirements.modules', function (): void {
    $violations = [];

    foreach (glob(base_path('app-modules/*')) ?: [] as $moduleDir) {
        $name = basename($moduleDir);
        $manifestPath = "{$moduleDir}/module.json";

        if (! is_file($manifestPath)) {
            continue;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $declared = dependencyNames($manifest['requirements']['modules'] ?? []);
        $own = Str::studly(str_replace('-', ' ', $name));

        foreach (File::allFiles("{$moduleDir}/src") as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/Modules\\\\([A-Za-z0-9_]+)\\\\/', $file->getContents(), $matches);

            foreach ($matches[1] ?? [] as $studly) {
                $kebab = Str::kebab($studly);

                if ($studly !== $own && ! in_array($kebab, $declared, true)) {
                    $violations[] = "{$name} references [{$kebab}] but does not declare it in requirements.modules";
                }
            }
        }
    }

    expect(array_unique($violations))->toBeEmpty();
});

it('keeps the module dependency graph acyclic', function (): void {
    $graph = [];

    foreach (glob(base_path('app-modules/*/module.json')) ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $graph[$manifest['name']] = dependencyNames($manifest['requirements']['modules'] ?? []);
    }

    $seen = [];

    $visit = function (string $module, array $path = []) use (&$visit, &$seen, $graph): array {
        if (in_array($module, $path, true)) {
            return array_merge($path, [$module]);
        }

        if (isset($seen[$module])) {
            return [];
        }

        $seen[$module] = true;

        foreach ($graph[$module] ?? [] as $dep) {
            if (! isset($graph[$dep])) {
                continue; // external/vendor dependency
            }

            $cycle = $visit($dep, array_merge($path, [$module]));

            if ($cycle !== []) {
                return $cycle;
            }
        }

        return [];
    };

    $cycles = [];

    foreach (array_keys($graph) as $module) {
        $cycle = $visit($module);

        if ($cycle !== []) {
            $cycles[] = implode(' -> ', $cycle);
        }
    }

    expect($cycles)->toBeEmpty();
});

/**
 * Normalize requirements.modules entries to plain module names so dependency
 * matching works with the canonical string form and with older object shapes.
 *
 * @param  array<int, mixed>  $entries
 * @return array<int, string>
 */
function dependencyNames(array $entries): array
{
    $names = [];

    foreach ($entries as $entry) {
        $name = is_array($entry) ? ($entry['name'] ?? null) : $entry;

        if (is_string($name)) {
            $names[] = $name;
        }
    }

    return $names;
}
