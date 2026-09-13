<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffold a new module directory with module.json, composer.json, and src/.
 *
 * Creates the standard module directory structure with all required
 * bootstrap files for a new module, including the service provider
 * and a stub view.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The module name in kebab-case (e.g., "call-forwarding")}
        {--display-name= : Human-readable display name}
        {--description= : Module description}
        {--category= : Module category for UI grouping}';

    protected $description = 'Scaffold a new module directory with module.json, composer.json, and src/';

    /**
     * Execute the console command.
     *
     * Creates the standard module directory structure with all required
     * bootstrap files for a new module.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (! preg_match('/^[a-z][a-z0-9_-]+$/', $name)) {
            $this->components->error('Module name must start with a lowercase letter and contain only lowercase letters, numbers, hyphens, and underscores.');

            return self::FAILURE;
        }

        $moduleDir = base_path("app-modules/{$name}");

        if (is_dir($moduleDir)) {
            $this->components->error("Module [{$name}] already exists at [{$moduleDir}].");

            return self::FAILURE;
        }

        $studlyName = Str::studly(str_replace('-', '_', $name));
        $namespace = "Modules\\{$studlyName}";
        $displayName = $this->option('display-name') ?? Str::title(str_replace(['-', '_'], ' ', $name));

        $this->createDirectories($moduleDir);
        $this->createModuleJson($moduleDir, $name, $namespace, $displayName);
        $this->createComposerJson($moduleDir, $name, $namespace);
        $this->createServiceProvider($moduleDir, $namespace, $name);
        $this->createStubView($moduleDir, $name);

        $this->components->info("Module [{$name}] scaffolded successfully at [{$moduleDir}].");

        $this->components->bulletList([
            'module.json        — module manifest',
            'composer.json      — Composer autoloading',
            'src/Providers/     — ModuleServiceProvider',
            'src/Livewire/      — Livewire component directory',
            'resources/views/   — Livewire view directory',
        ]);

        $this->components->twoColumnDetail('Namespace', $namespace);

        return self::SUCCESS;
    }

    /**
     * Create the module directory structure.
     */
    private function createDirectories(string $moduleDir): void
    {
        mkdir($moduleDir, 0755, true);
        mkdir("{$moduleDir}/src/Providers", 0755, true);
        mkdir("{$moduleDir}/src/Livewire", 0755, true);
        mkdir("{$moduleDir}/resources/views", 0755, true);
        mkdir("{$moduleDir}/database/migrations", 0755, true);
        mkdir("{$moduleDir}/lang/en", 0755, true);
        mkdir("{$moduleDir}/config", 0755, true);
    }

    /**
     * Create the module.json manifest file.
     */
    private function createModuleJson(string $moduleDir, string $name, string $namespace, string $displayName): void
    {
        $description = $this->option('description') ?? '';
        $category = $this->option('category') ?? 'General';

        $content = [
            '$schema' => '../../resources/schemas/module.json',
            'name' => $name,
            'version' => '1.0.0',
            'namespace' => $namespace,
            'display_name' => $displayName,
            'description' => $description,
            'category' => $category,
            'required' => false,
            'protected' => false,
            'priority' => 0,
            'providers' => [
                "{$namespace}\\Providers\\ModuleServiceProvider",
            ],
            'requirements' => [
                'php' => '>=8.3',
                'laravel' => '>=13.0',
                'modules' => [],
                'packages' => [],
            ],
        ];

        file_put_contents(
            "{$moduleDir}/module.json",
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /**
     * Create the per-module composer.json for dependency management.
     */
    private function createComposerJson(string $moduleDir, string $name, string $namespace): void
    {
        $vendorName = str_replace('_', '-', $name);
        $content = [
            'name' => "tallpbx/module-{$vendorName}",
            'type' => 'tallpbx-module',
            'description' => '',
            'require' => [
                'php' => '>=8.3',
            ],
            'autoload' => [
                'psr-4' => [
                    "{$namespace}\\" => 'src/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        "{$namespace}\\Providers\\ModuleServiceProvider",
                    ],
                ],
                'tallpbx' => [
                    'module' => $name,
                ],
            ],
        ];

        file_put_contents(
            "{$moduleDir}/composer.json",
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /**
     * Create the module's ModuleServiceProvider.
     */
    private function createServiceProvider(string $moduleDir, string $namespace, string $name): void
    {
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\Providers;

/**
 * Registers the {$name} module with the TallPBX module system.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Return the module's kebab-case registry name.
     */
    protected function moduleName(): string
    {
        return '{$name}';
    }

    /**
     * Return the module's root PHP namespace.
     */
    protected function moduleNamespace(): string
    {
        return '{$namespace}';
    }
}

PHP;

        file_put_contents("{$moduleDir}/src/Providers/ModuleServiceProvider.php", $content);
    }

    /**
     * Create a stub view to confirm the module is working.
     */
    private function createStubView(string $moduleDir, string $name): void
    {
        $content = <<<'HTML'
<div>
    {{-- List and edit components inheriting TallPBX base components receive these safe operational messages. --}}
    @if (($operationalMessage ?? null) !== null)
        <x-inline-alert :type="$operationalMessageType ?? 'info'">
            {{ $operationalMessage }}
        </x-inline-alert>
    @endif

    <p class="text-gray-600">{{ __('Module loaded successfully.') }}</p>
</div>
HTML;

        file_put_contents("{$moduleDir}/resources/views/index.blade.php", $content);
    }
}
