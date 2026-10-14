<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Scaffold a new custom module directory with module.json and src/.
 *
 * Creates the standard module directory structure under custom-modules/
 * for 3rd-party and community extensions, without modifying root composer.json.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The module name in kebab-case (e.g., "call-forwarding")}
        {--display-name= : Human-readable display name}
        {--description= : Module description}
        {--category= : Module category for UI grouping}';

    protected $description = 'Scaffold a new custom module in custom-modules/ with module.json and src/';

    /**
     * Execute the console command.
     *
     * Creates the standard module directory structure with all required
     * bootstrap files for a custom module.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        // Canonical kebab-case only: module names must round-trip through the
        // Str::kebab namespace mapping used by dependency matching and the
        // module-aware test guard, which underscores would break.
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            $this->components->error('Module name must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens.');

            return self::FAILURE;
        }

        $moduleDir = base_path("custom-modules/{$name}");

        if (is_dir($moduleDir) || is_dir(base_path("app-modules/{$name}"))) {
            $this->components->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $studlyName = Str::studly(str_replace('-', '_', $name));
        $namespace = "CustomModules\\{$studlyName}";
        $displayName = $this->option('display-name') ?? Str::title(str_replace(['-', '_'], ' ', $name));

        $this->createDirectories($moduleDir);
        $this->createModuleJson($moduleDir, $name, $namespace, $displayName);
        $this->createServiceProvider($moduleDir, $namespace, $name);
        $this->createStubView($moduleDir, $name);
        $this->createLanguageFiles($moduleDir, $name, $displayName);

        // Rebuild the module manifest cache in the same step so the scaffolded
        // extension is discoverable immediately and the cache never serves a
        // pre-scaffold snapshot.
        Artisan::call('module:cache');

        $this->components->info("Custom module [{$name}] scaffolded successfully at [{$moduleDir}].");

        $this->components->bulletList([
            'module.json        — module manifest (source: custom)',
            'src/Providers/     — ModuleServiceProvider',
            'src/Livewire/      — Livewire component directory',
            'src/Database/Factories/ — model factory directory',
            'resources/views/   — Livewire view directory',
            'tests/             — Pest tests discovered by the host suite',
            'database/migrations/ — module migrations',
            'lang/{en,es,fr}/   — translation files with collision prevention header',
            'config/            — module config merged over centralized defaults',
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
        mkdir("{$moduleDir}/src/Database/Factories", 0755, true);
        mkdir("{$moduleDir}/resources/views", 0755, true);
        mkdir("{$moduleDir}/database/migrations", 0755, true);
        mkdir("{$moduleDir}/lang/en", 0755, true);
        mkdir("{$moduleDir}/lang/es", 0755, true);
        mkdir("{$moduleDir}/lang/fr", 0755, true);
        mkdir("{$moduleDir}/config", 0755, true);
        mkdir("{$moduleDir}/tests", 0755, true);
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
            'source' => 'custom',
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

    /**
     * Create stub translation files for en, es, and fr with collision prevention docblock.
     */
    private function createLanguageFiles(string $moduleDir, string $name, string $displayName): void
    {
        $entityPrefix = str_replace('-', '_', $name);
        $content = <<<PHP
<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: {$displayName} ({$name})
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. '{$entityPrefix}_*', 'create_{$entityPrefix}', 'no_{$entityPrefix}_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    //
];

PHP;

        foreach (['en', 'es', 'fr'] as $locale) {
            file_put_contents("{$moduleDir}/lang/{$locale}/admin.php", $content);
        }
    }
}
