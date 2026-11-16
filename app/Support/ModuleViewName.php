<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Resolves a module Livewire component to its namespaced Blade view name.
 *
 * Both base components call this one implementation. The legacy branch stays:
 * custom modules under `custom-modules/<name>/src/Livewire` rely on the same
 * unanchored match, and older trees put components under `src/Http/Livewire`.
 */
final class ModuleViewName
{
    /**
     * Resolve the view name for a component class.
     *
     * @param  class-string  $className
     *
     * @throws InvalidArgumentException When the class has no source file.
     */
    public static function for(string $className): string
    {
        $filePath = (new ReflectionClass($className))->getFileName();

        if ($filePath === false) {
            throw new InvalidArgumentException("Cannot resolve a view name for [{$className}].");
        }

        return self::fromPath($filePath);
    }

    /**
     * Resolve the view name from a component's file path.
     *
     * Example: app-modules/bridges/src/Livewire/BridgesEdit.php
     *   → bridges::bridges-edit
     */
    public static function fromPath(string $filePath): string
    {
        // Capture groups: 1 = module directory, 2 = legacy path marker, 3 = class name.
        preg_match('#(?:app-)?modules/([^/]+)/src/(Http/)?Livewire/(.+)\.php$#', $filePath, $matches);

        $moduleDir = $matches[1] ?? '';
        $isLegacy = ($matches[2] ?? '') !== '';
        $className = $matches[3] ?? '';

        $viewName = Str::of($className)->snake('-')->toString();

        // Legacy modules keep their views under resources/views/livewire/.
        $prefix = $isLegacy ? 'livewire.' : '';

        return "{$moduleDir}::{$prefix}{$viewName}";
    }
}
