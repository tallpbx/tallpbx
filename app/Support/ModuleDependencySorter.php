<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Deterministically sorts module manifests in topological dependency order.
 *
 * Enforces that any module listed in another module's requirements.modules
 * is placed strictly before its dependent. When modules have no dependency
 * relationship, priority acts as the tie-breaker (lowest priority loads
 * first), with the canonical module name acting as the secondary tie-breaker.
 */
final class ModuleDependencySorter
{
    /**
     * Sort manifests topologically using Kahn's algorithm.
     *
     * @param  array<string|int, array<string, mixed>>  $manifests
     * @return array<string, array<string, mixed>>
     *
     * @throws LogicException when a dependency cycle or unknown dependency is detected
     */
    public static function sort(array $manifests): array
    {
        $indexed = [];
        foreach ($manifests as $key => $manifest) {
            $name = $manifest['name'] ?? (is_string($key) ? $key : null);
            if ($name === null || $name === '') {
                throw new LogicException('Module manifest must declare a machine name.');
            }
            $indexed[$name] = $manifest;
        }

        $inDegree = [];
        $dependents = [];
        foreach ($indexed as $name => $manifest) {
            $inDegree[$name] = 0;
            $dependents[$name] = [];
        }

        foreach ($indexed as $name => $manifest) {
            if (($manifest['enabled'] ?? true) === false) {
                continue;
            }

            $requirements = $manifest['requirements']['modules'] ?? [];
            if (! is_array($requirements)) {
                $requirements = [];
            }

            $uniqueRequirements = [];
            foreach ($requirements as $dependency) {
                $dependencyName = is_string($dependency) ? $dependency : ($dependency['name'] ?? null);
                if ($dependencyName !== null && $dependencyName !== '') {
                    $uniqueRequirements[$dependencyName] = $dependencyName;
                }
            }

            foreach ($uniqueRequirements as $dependency) {
                if (! isset($indexed[$dependency])) {
                    throw new LogicException("Module [{$name}] requires unknown module [{$dependency}].");
                }

                if ($dependency === $name) {
                    throw new LogicException("Module [{$name}] cannot depend on itself (dependency cycle).");
                }

                $inDegree[$name]++;
                $dependents[$dependency][] = $name;
            }
        }

        $ready = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $ready[] = $name;
            }
        }

        $sorted = [];
        while (! empty($ready)) {
            // Sort ready queue: lowest priority loads first, tie-break by name alphabetically
            usort($ready, function (string $a, string $b) use ($indexed): int {
                $priorityA = $indexed[$a]['priority'] ?? 0;
                $priorityB = $indexed[$b]['priority'] ?? 0;

                if ($priorityA !== $priorityB) {
                    return $priorityA <=> $priorityB;
                }

                return strcmp($a, $b);
            });

            $current = array_shift($ready);
            $sorted[$current] = $indexed[$current];

            foreach ($dependents[$current] as $dependentName) {
                $inDegree[$dependentName]--;
                if ($inDegree[$dependentName] === 0) {
                    $ready[] = $dependentName;
                }
            }
        }

        if (count($sorted) < count($indexed)) {
            $unresolved = array_diff(array_keys($indexed), array_keys($sorted));
            throw new LogicException('Dependency cycle detected involving modules: ['.implode(', ', $unresolved).'].');
        }

        return $sorted;
    }
}
