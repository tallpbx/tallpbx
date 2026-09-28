<?php

declare(strict_types=1);

use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Support\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-module-config-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-config/config', 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('merges module settings over centralized defaults when the module ships a config file', function (): void {
    File::put($this->sandbox.'/app-modules/demo-config/config/demo-config.php', <<<'PHP'
<?php

return [
    'overridden' => 'module-value',
    'module_only' => 'present',
];
PHP);

    // Centralized defaults live app-level under the same key.
    Config::set('demo-config', [
        'overridden' => 'central-value',
        'central_only' => 'kept',
    ]);

    (new DemoConfigProvider(app(), $this->sandbox.'/app-modules/demo-config'))
        ->boot(app(MenuService::class), app(PermissionService::class), app(ModuleState::class));

    // Module settings override where present; centralized settings survive.
    expect(config('demo-config.overridden'))->toBe('module-value')
        ->and(config('demo-config.module_only'))->toBe('present')
        ->and(config('demo-config.central_only'))->toBe('kept');
});

it('leaves centralized settings untouched when the module ships no config file', function (): void {
    Config::set('demo-config', ['central_only' => 'kept']);

    (new DemoConfigProvider(app(), $this->sandbox.'/app-modules/demo-config'))
        ->boot(app(MenuService::class), app(PermissionService::class), app(ModuleState::class));

    expect(config('demo-config.central_only'))->toBe('kept')
        ->and(config('demo-config.overridden'))->toBeNull();
});

/**
 * Test-only module provider pointing at the sandbox directory.
 */
class DemoConfigProvider extends ModuleServiceProvider
{
    /**
     * Bind the provider to an explicit sandbox module directory.
     */
    public function __construct(Application $app, private readonly string $sandboxPath)
    {
        parent::__construct($app);
    }

    /**
     * Return the sandbox module's kebab-case name.
     */
    protected function moduleName(): string
    {
        return 'demo-config';
    }

    /**
     * Return the sandbox module's PHP namespace.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\\DemoConfig';
    }

    /**
     * Point path resolution at the sandbox instead of a real module.
     */
    protected function modulePath(): string
    {
        return $this->sandboxPath;
    }
}
