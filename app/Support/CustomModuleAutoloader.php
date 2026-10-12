<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Dynamic runtime autoloader for third-party and community modules
 * located in the custom-modules/ directory.
 *
 * Resolves PSR-4 classes under CustomModules\{Domain}\ (or Modules\{Domain}\)
 * directly to custom-modules/{domain-slug}/src/ without mutating root composer.json.
 */
class CustomModuleAutoloader
{
    private static bool $registered = false;

    private static ?string $basePath = null;

    /**
     * Explicit namespace prefix mappings (e.g. 'CustomModules\\MyCrm\\' => '/path/to/src/').
     *
     * @var array<string, string>
     */
    private static array $prefixes = [];

    /**
     * Register the SPL autoloader for custom modules.
     */
    public static function register(?string $basePath = null): void
    {
        self::$basePath = $basePath ?? base_path('custom-modules');

        if (self::$registered) {
            return;
        }

        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }

    /**
     * Unregister the SPL autoloader (primarily for test teardown).
     */
    public static function unregister(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'loadClass']);
            self::$registered = false;
        }
        self::$prefixes = [];
        self::$basePath = null;
    }

    /**
     * Register an explicit namespace prefix and its source directory.
     */
    public static function addModule(string $namespace, string $srcDir): void
    {
        $prefix = trim($namespace, '\\').'\\';
        self::$prefixes[$prefix] = rtrim($srcDir, '/\\');
    }

    /**
     * Autoload callback invoked by PHP's engine.
     */
    public static function loadClass(string $class): void
    {
        // 1. Check registered explicit prefixes first
        foreach (self::$prefixes as $prefix => $srcDir) {
            if (str_starts_with($class, $prefix)) {
                $relative = substr($class, strlen($prefix));
                $file = $srcDir.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }

        // 2. Convention fallback: CustomModules\{Studly}\... or Modules\{Studly}\...
        $rootPrefix = null;
        if (str_starts_with($class, 'CustomModules\\')) {
            $rootPrefix = 'CustomModules\\';
        } elseif (str_starts_with($class, 'Modules\\')) {
            $rootPrefix = 'Modules\\';
        }

        if ($rootPrefix === null) {
            return;
        }

        $base = self::$basePath ?? base_path('custom-modules');
        if (! is_dir($base)) {
            return;
        }

        $relativeClass = substr($class, strlen($rootPrefix));
        $parts = explode('\\', $relativeClass);

        if (empty($parts[0])) {
            return;
        }

        $moduleStudly = array_shift($parts);
        $moduleKebab = Str::kebab($moduleStudly);

        $subPath = implode(DIRECTORY_SEPARATOR, $parts).'.php';
        $file = $base.DIRECTORY_SEPARATOR.$moduleKebab.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$subPath;

        if (is_file($file)) {
            require $file;
        }
    }
}
