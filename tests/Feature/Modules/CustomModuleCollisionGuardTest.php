<?php

declare(strict_types=1);

use App\Exceptions\MigrationCollisionException;
use App\Models\Module;
use App\Models\Permission;
use App\Providers\ModuleServiceProvider as DiscoveryProvider;
use App\Services\ModuleCollisionGuard;
use App\Services\PermissionService;
use App\Support\MigrationSafetyGuard;
use App\Support\ModuleServiceProvider as ModuleBaseProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// ─── Shared fixture state ────────────────────────────────────────

beforeEach(function (): void {
    // Capture the shared application state this suite mutates so every test
    // restores it — one app instance is shared per parallel worker process.
    $this->originalBasePath = base_path();
    $this->originalManifest = app()->bound('modules.manifest') ? app('modules.manifest') : null;
    $GLOBALS['__cg_fixture_roots'] = [];
    Log::spy();

    // Force the lazy database refresh to run under the REAL base path before
    // any fixture discovery switches basePath: a refresh triggered inside the
    // fixture window would run migrate:fresh against the fixture skeleton's
    // (nonexistent) database/migrations and poison the worker's database.
    Schema::hasTable('modules');

    if (method_exists(ModuleBaseProvider::class, 'clearRootTranslationKeysCache')) {
        ModuleBaseProvider::clearRootTranslationKeysCache();
    }
    // Also reset BEFORE each test: the inter-module key registry is static and
    // would otherwise leak keys from earlier test files into the first test here.
    if (method_exists(ModuleBaseProvider::class, 'clearModuleTranslationKeysCache')) {
        ModuleBaseProvider::clearModuleTranslationKeysCache();
    }
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);

    if ($this->originalManifest !== null) {
        app()->forgetInstance('modules.manifest');
        app()->instance('modules.manifest', $this->originalManifest);
    }

    foreach ($GLOBALS['__cg_fixture_roots'] ?? [] as $root) {
        File::deleteDirectory($root);
    }
    $GLOBALS['__cg_fixture_roots'] = [];

    if (method_exists(ModuleBaseProvider::class, 'clearRootTranslationKeysCache')) {
        ModuleBaseProvider::clearRootTranslationKeysCache();
    }
    if (method_exists(ModuleBaseProvider::class, 'clearModuleTranslationKeysCache')) {
        ModuleBaseProvider::clearModuleTranslationKeysCache();
    }
});

// ─── §1.7.1 Name collisions ──────────────────────────────────────

it('rejects a custom module whose name collides with a core module and logs a descriptive error', function (): void {
    $root = cgFixtureRoot();
    // A minimal core module named 'extensions' stands in for the real one.
    cgWriteCoreManifest($root, 'extensions');
    cgWriteManifest($root, 'extensions'); // custom squatter
    cgWriteManifest($root, 'my-legit-tool'); // control

    $modules = cgRunDiscovery($root);

    expect($modules)->toHaveKey('extensions')
        ->and($modules['extensions']['source'])->toBe('core')
        ->and($modules)->toHaveKey('my-legit-tool')
        ->and($modules['my-legit-tool']['source'])->toBe('custom');

    cgAssertLogged('error', '/extensions/');
});

it('flags name collisions through the ModuleCollisionGuard directly', function (): void {
    $guard = new ModuleCollisionGuard;

    expect($guard->assertNoNameCollision('extensions', ['admin', 'extensions']))->toBeFalse()
        ->and($guard->assertNoNameCollision('my-tool', ['admin', 'extensions']))->toBeTrue()
        ->and($guard->assertNoNameCollision('extensions', ['admin', 'extensions'], 'vendor'))->toBeFalse();

    cgAssertLogged('error', '/extensions/');
    // Vendor-origin collisions must be labelled as vendor, not custom.
    cgAssertLogged('error', '/Vendor module \[extensions\]/');
});

it('labels vendor-origin name collisions as vendor during discovery', function (): void {
    $root = cgFixtureRoot();
    cgWriteCoreManifest($root, 'extensions');
    // A vendor package whose module.json name shadows the core module — the
    // vendor scan glob is vendor/{vendor}/{package}/module.json (no module-name dir).
    $vendorDir = "{$root}/vendor/vendorname/packagename";
    @mkdir($vendorDir, 0777, true);
    file_put_contents("{$vendorDir}/module.json", (string) json_encode([
        'name' => 'extensions',
        'version' => '1.0.0',
        'namespace' => 'Vendor\\Extensions',
        'display_name' => 'Extensions',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $modules = cgRunDiscovery($root);

    expect($modules['extensions']['source'])->toBe('core');

    cgAssertLogged('error', '/Vendor module \[extensions\]/');
});

// ─── §1.7.6 Fail-soft dependency handling ────────────────────────

it('fails soft when a custom module declares unmet module and package requirements', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'broken-extension', [
        'requirements' => [
            'modules' => [['name' => 'ghost-module', 'version' => '*']],
            'packages' => ['ghost/missing-package'],
        ],
    ]);

    $modules = cgRunDiscovery($root);

    // The module stays discovered but is marked disabled instead of throwing.
    expect($modules)->toHaveKey('broken-extension')
        ->and($modules['broken-extension']['enabled'])->toBeFalse();

    cgAssertLogged('critical', '/broken-extension/');

    // The failure is propagated into the registry so ModuleState agrees.
    $row = Module::query()->where('name', 'broken-extension')->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->enabled)->toBeFalse()
        ->and($row->status)->toBe(Module::StatusDisabled);
});

it('applies fail-soft dependency checks on the cached boot path too', function (): void {
    $root = cgFixtureRoot();
    $cached = [
        'cached-broken' => [
            'name' => 'cached-broken',
            'version' => '1.0.0',
            'namespace' => 'CustomModules\\CachedBroken',
            'display_name' => 'Cached Broken',
            'source' => 'custom',
            'path' => "{$root}/custom-modules/cached-broken",
            'requirements' => ['modules' => [['name' => 'ghost-module', 'version' => '*']]],
        ],
    ];
    @mkdir("{$root}/bootstrap/cache", 0777, true);
    file_put_contents(
        "{$root}/bootstrap/cache/modules.php",
        "<?php\n\nreturn ".var_export($cached, true).";\n",
    );

    $modules = cgRunDiscovery($root);

    expect($modules['cached-broken']['enabled'])->toBeFalse();

    cgAssertLogged('critical', '/cached-broken/');

    $row = Module::query()->where('name', 'cached-broken')->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->enabled)->toBeFalse()
        ->and($row->status)->toBe(Module::StatusDisabled);
});

it('skips registering providers for modules disabled by the fail-soft check', function (): void {
    $root = cgFixtureRoot();
    cgScaffoldProvider($root, 'healthy-extension', 'HealthyExtension');
    cgScaffoldProvider($root, 'broken-extension', 'BrokenExtension', [
        'requirements' => ['packages' => ['ghost/missing-package']],
    ]);

    try {
        app()->setBasePath($root);
        (new DiscoveryProvider(app()))->register();
    } finally {
        app()->setBasePath($this->originalBasePath);
    }

    // The healthy fixture provider boots; the fail-soft disabled one never does.
    expect(app()->bound('fixture.healthy-extension.booted'))->toBeTrue()
        ->and(app()->bound('fixture.broken-extension.booted'))->toBeFalse()
        ->and(app('modules.manifest')['broken-extension']['enabled'])->toBeFalse();
});

it('cascades a fail-soft disabled dependency to dependent modules', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'a-dependent', [
        'requirements' => ['modules' => ['b-dependency']],
    ]);
    cgWriteManifest($root, 'b-dependency', [
        'requirements' => ['packages' => ['ghost/missing-package']],
    ]);

    $modules = cgRunDiscovery($root);

    // The dependent must not stay enabled while its requirement's providers
    // were skipped — that combination crashes at runtime.
    expect($modules['b-dependency']['enabled'])->toBeFalse()
        ->and($modules['a-dependent']['enabled'])->toBeFalse();

    cgAssertLogged('critical', '/a-dependent/');
});

it('logs and records a fail-soft failure only once until the module is re-enabled', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'stuck-extension', [
        'requirements' => ['packages' => ['ghost/missing-package']],
    ]);

    // Two consecutive boots of the same broken module: only the first may
    // write the critical log entry and the registry row.
    cgRunDiscovery($root);
    cgRunDiscovery($root);

    $row = Module::query()->where('name', 'stuck-extension')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(Module::StatusDisabled);

    Log::shouldHaveReceived('critical', [Mockery::pattern('/stuck-extension/')])->once();

    // The message must tell the administrator how to recover.
    cgAssertLogged('critical', '/re-enable/');
});

// ─── §1.7.2 Route prefix & name hijacking ────────────────────────

it('rejects custom routes binding reserved panel URLs or existing route names', function (): void {
    $root = cgFixtureRoot();
    $bad = "{$root}/custom-modules/hijack/routes/web.php";
    @mkdir(dirname($bad), 0777, true);
    file_put_contents($bad, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/panel/login', fn () => 'hijacked')->name('hijack.login');
Route::get('/hijack-tool', fn () => 'ok')->name('hijack.index');
PHP);

    $guard = new ModuleCollisionGuard;
    $descriptors = $guard->extractRouteDescriptors($bad);
    $reserved = [
        'uris' => ModuleCollisionGuard::RESERVED_PANEL_URIS,
        'names' => ['panel.groups.index'],
    ];

    expect($descriptors)->not->toBeEmpty()
        ->and($guard->assertNoRouteCollision([['uri' => '/panel/login']], $reserved))->toBeFalse()
        ->and($guard->assertNoRouteCollision([['name' => 'panel.groups.index']], $reserved))->toBeFalse()
        ->and($guard->assertNoRouteCollision($descriptors, $reserved))->toBeFalse()
        ->and($guard->assertNoRouteCollision([['uri' => '/hijack-tool'], ['name' => 'hijack.index']], $reserved))->toBeTrue();

    cgAssertLogged('error', '/panel\/login/');
});

it('does not load a custom module route file that fails the route collision guard', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'route-hijacker');
    @mkdir("{$root}/custom-modules/route-hijacker/routes", 0777, true);
    file_put_contents("{$root}/custom-modules/route-hijacker/routes/web.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/panel/dashboard', fn () => 'hijacked')->name('hijacker.dashboard');
PHP);

    app()->instance('modules.manifest', ['route-hijacker' => ['source' => 'custom']]);

    $provider = cgAnonymousProvider('route-hijacker', "{$root}/custom-modules/route-hijacker");
    cgInvokePrivate($provider, 'registerRoutes');

    // Fluent ->name() definitions land after the collection add, so refresh
    // the name lookup table before asserting on getByName().
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('hijacker.dashboard'))->toBeNull();

    cgAssertLogged('error', '/panel\/dashboard/');
});

it('loads a custom module route file that passes the route collision guard', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'route-follower');
    @mkdir("{$root}/custom-modules/route-follower/routes", 0777, true);
    file_put_contents("{$root}/custom-modules/route-follower/routes/web.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/follower-tool', fn () => 'ok')->name('follower.index');
PHP);

    app()->instance('modules.manifest', ['route-follower' => ['source' => 'custom']]);

    $provider = cgAnonymousProvider('route-follower', "{$root}/custom-modules/route-follower");
    cgInvokePrivate($provider, 'registerRoutes');

    // Fluent ->name() definitions land after the collection add, so refresh
    // the name lookup table before asserting on getByName().
    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('follower.index'))->not->toBeNull();
});

it('removes custom routes that hijack reserved panel URLs through evasive registration styles', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'evasive-hijacker');
    @mkdir("{$root}/custom-modules/evasive-hijacker/routes", 0777, true);
    // Route::match and the case-flipped Route::GET both evade the static
    // pre-flight regexes — the post-load router audit must still remove the
    // hijacked entries and restore the replaced core routes.
    file_put_contents("{$root}/custom-modules/evasive-hijacker/routes/web.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/panel/login', fn () => 'hijacked')->name('evasive.login');
Route::GET('/panel/git-update', fn () => 'hijacked')->name('evasive.git');
PHP);

    app()->instance('modules.manifest', ['evasive-hijacker' => ['source' => 'custom']]);

    $coreLogin = Route::getRoutes()->getByName('panel.login');
    $coreGitUpdate = Route::getRoutes()->getByName('panel.git-update');

    $provider = cgAnonymousProvider('evasive-hijacker', "{$root}/custom-modules/evasive-hijacker");
    cgInvokePrivate($provider, 'registerRoutes');

    Route::getRoutes()->refreshNameLookups();

    // Request matching must find the core route objects, not the hijackers,
    // and the hijackers' own names must be gone.
    expect(Route::getRoutes()->get('GET')['panel/login'] ?? null)->toBe($coreLogin)
        ->and(Route::getRoutes()->get('GET')['panel/git-update'] ?? null)->toBe($coreGitUpdate)
        ->and(Route::getRoutes()->getByName('evasive.login'))->toBeNull()
        ->and(Route::getRoutes()->getByName('evasive.git'))->toBeNull();

    cgAssertLogged('error', '/panel\/login/');
});

it('removes custom routes that hijack core route names through variable names', function (): void {
    $root = cgFixtureRoot();
    cgWriteManifest($root, 'name-hijacker');
    @mkdir("{$root}/custom-modules/name-hijacker/routes", 0777, true);
    // The route name is assigned through a variable, which the static
    // pre-flight cannot lift — the post-load router audit must still detect
    // that a core route name now resolves to the custom route.
    file_put_contents("{$root}/custom-modules/name-hijacker/routes/web.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

$target = 'panel.groups.index';
Route::get('/name-hijack-tool', fn () => 'ok')->name($target);
PHP);

    app()->instance('modules.manifest', ['name-hijacker' => ['source' => 'custom']]);

    $coreGroupsIndex = Route::getRoutes()->getByName('panel.groups.index');

    $provider = cgAnonymousProvider('name-hijacker', "{$root}/custom-modules/name-hijacker");
    cgInvokePrivate($provider, 'registerRoutes');

    expect(Route::getRoutes()->getByName('panel.groups.index'))->toBe($coreGroupsIndex);
});

it('does not audit a compiled route collection on cached-route boots', function (): void {
    // On a route:cache boot the router holds a CompiledRouteCollection whose
    // objects are re-materialized per access and whose internals differ —
    // the reflection-based audit must leave it untouched instead of crashing.
    $collection = new class([], []) extends CompiledRouteCollection
    {
        /**
         * Return one offender-shaped route so the pre-gate audit would try
         * reflection surgery on this collection type.
         */
        public function getRoutes(): array
        {
            return [Route::getRoutes()->getRoutes()[0]];
        }
    };

    $router = new class(app('events'), app(), $collection) extends Router
    {
        public function __construct($events, $container, private readonly RouteCollectionInterface $fixtureCollection)
        {
            parent::__construct($events, $container);
        }

        public function getRoutes(): RouteCollectionInterface
        {
            return $this->fixtureCollection;
        }
    };

    $originalRouter = Route::getFacadeRoot();

    try {
        // Swap through the facade: the facade caches its resolved instance,
        // so a container rebind alone would leave the old router in place.
        Route::swap($router);
        $guard = new ModuleCollisionGuard;

        expect($guard->enforceRouteBoundaries([]))->toBe(0);
    } finally {
        Route::swap($originalRouter);
    }
});

// ─── §1.7.3 Database schema collisions ───────────────────────────

it('halts custom migrations that create existing tables or alter protected core tables', function (): void {
    $root = cgFixtureRoot();
    // An existing, non-protected table that a custom migration tries to re-create.
    Schema::create('plain_existing_table', fn (Blueprint $table): mixed => $table->increments('id'));

    $createUsers = cgWriteMigration($root, 'create_users_attempt', <<<'BODY'
Schema::create('users', function (Blueprint $table): void {
    $table->increments('id');
});
BODY);
    $alterPermissions = cgWriteMigration($root, 'alter_permissions_attempt', <<<'BODY'
Schema::table('permissions', function (Blueprint $table): void {
    $table->string('squatter')->nullable();
});
BODY);
    $createExisting = cgWriteMigration($root, 'create_existing_attempt', <<<'BODY'
Schema::create('plain_existing_table', function (Blueprint $table): void {
    $table->increments('id');
});
BODY);
    $createFresh = cgWriteMigration($root, 'create_fresh_attempt', <<<'BODY'
Schema::create('brand_new_custom_table', function (Blueprint $table): void {
    $table->increments('id');
});
BODY);

    try {
        app()->setBasePath($root);
        $guard = app(MigrationSafetyGuard::class);
        $connection = DB::connection();

        expect(fn () => $guard->assertNoCustomTableCollisions([$createUsers], $connection))
            ->toThrow(MigrationCollisionException::class)
            ->and(fn () => $guard->assertNoCustomTableCollisions([$alterPermissions], $connection))
            ->toThrow(MigrationCollisionException::class)
            ->and(fn () => $guard->assertNoCustomTableCollisions([$createExisting], $connection))
            ->toThrow(MigrationCollisionException::class)
            ->and(fn () => $guard->assertNoCustomTableCollisions([$createFresh], $connection))
            ->not->toThrow(MigrationCollisionException::class);
    } finally {
        app()->setBasePath($this->originalBasePath);
    }

    $collisionGuard = new ModuleCollisionGuard;

    expect($collisionGuard->assertNoTableCollision('users', ModuleCollisionGuard::PROTECTED_CORE_TABLES))->toBeFalse()
        ->and($collisionGuard->assertNoTableCollision('brand_new_custom_table', ModuleCollisionGuard::PROTECTED_CORE_TABLES))->toBeTrue();
});

it('blocks SafeMigrator runs containing colliding custom migrations', function (): void {
    $root = cgFixtureRoot();
    $file = cgWriteMigration($root, 'create_users_attempt', <<<'BODY'
Schema::create('users', function (Blueprint $table): void {
    $table->increments('id');
});
BODY);

    try {
        app()->setBasePath($root);
        $migrator = app('migrator');

        expect(fn () => $migrator->run([dirname($file)]))->toThrow(MigrationCollisionException::class);
    } finally {
        app()->setBasePath($this->originalBasePath);
    }
});

it('does not flag query-builder access to protected tables inside custom migrations', function (): void {
    $root = cgFixtureRoot();
    $benign = cgWriteMigration($root, 'benign_query_builder', <<<'BODY'
DB::table('users')->where('id', 1)->update(['name' => 'renamed']);
BODY);

    try {
        app()->setBasePath($root);
        $guard = app(MigrationSafetyGuard::class);

        // A query-builder read/update on a core table is data access, not a
        // schema alteration — the static token scan must not halt it.
        expect(fn () => $guard->assertNoCustomTableCollisions([$benign], DB::connection()))
            ->not->toThrow(MigrationCollisionException::class);
    } finally {
        app()->setBasePath($this->originalBasePath);
    }
});

it('halts raw and dynamic DDL against protected core tables at migration runtime', function (): void {
    $root = cgFixtureRoot();
    // Each scenario lives in its own module directory — a migrator run loads
    // every pending migration file in the given directory.
    $raw = cgWriteMigration($root, 'raw_alter', <<<'BODY'
DB::statement('ALTER TABLE permissions ADD squatter TEXT');
BODY, 'raw-alter-module');
    $dynamic = cgWriteMigration($root, 'dynamic_create', <<<'BODY'
$target = 'menus';
Schema::create($target, function (Blueprint $table): void {
    $table->increments('id');
});
BODY, 'dynamic-create-module');

    try {
        app()->setBasePath($root);
        $migrator = app('migrator');

        // Raw SQL statements and variable table names are invisible to static
        // analysis — the runtime DDL guard must halt them during execution.
        expect(fn () => $migrator->run([dirname($raw)]))->toThrow(MigrationCollisionException::class)
            ->and(fn () => $migrator->run([dirname($dynamic)]))->toThrow(MigrationCollisionException::class);
    } finally {
        app()->setBasePath($this->originalBasePath);
    }
});

// ─── §1.7.4 Language dictionary collisions ───────────────────────

it('revokes the cascading translation path when a custom module collides with another module', function (): void {
    $root = cgFixtureRoot();
    app()->setBasePath($root);

    // An earlier (core-order) module cascades its keys into the global dictionary first.
    cgWriteLang($root, 'alpha-module', 'admin', ['shared_heading' => 'Alpha heading', 'alpha_only' => 'Alpha']);
    cgInvokePrivate(cgAnonymousProvider('alpha-module', "{$root}/custom-modules/alpha-module"), 'registerTranslations');

    // The custom module declares a colliding key.
    cgWriteLang($root, 'beta-module', 'admin', ['shared_heading' => 'Beta heading', 'beta_only' => 'Beta']);
    app()->instance('modules.manifest', ['beta-module' => ['source' => 'custom']]);
    cgInvokePrivate(cgAnonymousProvider('beta-module', "{$root}/custom-modules/beta-module"), 'registerTranslations');

    $paths = cgLoaderPaths();

    expect($paths)->toContain("{$root}/custom-modules/alpha-module/lang")
        ->and($paths)->not->toContain("{$root}/custom-modules/beta-module/lang")
        ->and(trans('beta-module::admin.shared_heading'))->toBe('Beta heading')
        ->and(trans('beta-module::admin.beta_only'))->toBe('Beta');

    cgAssertLogged('critical', '/beta-module/');
});

it('keeps the cascading translation path for custom modules without colliding keys', function (): void {
    $root = cgFixtureRoot();
    app()->setBasePath($root);

    cgWriteLang($root, 'alpha-module', 'admin', ['shared_heading' => 'Alpha heading']);
    cgInvokePrivate(cgAnonymousProvider('alpha-module', "{$root}/custom-modules/alpha-module"), 'registerTranslations');

    cgWriteLang($root, 'gamma-module', 'gamma', ['gamma_only' => 'Gamma']);
    app()->instance('modules.manifest', ['gamma-module' => ['source' => 'custom']]);
    cgInvokePrivate(cgAnonymousProvider('gamma-module', "{$root}/custom-modules/gamma-module"), 'registerTranslations');

    expect(cgLoaderPaths())->toContain("{$root}/custom-modules/gamma-module/lang");
});

// ─── §1.7.5 Permission ownership ─────────────────────────────────

it('blocks custom modules from claiming permissions owned by another module', function (): void {
    app()->instance('modules.manifest', ['custom-squatter' => ['source' => 'custom']]);

    $service = new PermissionService;
    $service->register('extensions', ['extensions.edit' => 'Edit extensions']);
    $service->register('custom-squatter', ['extensions.edit' => 'Squatted claim', 'custom-squatter.own' => 'Own permission']);

    $grouped = $service->grouped();
    $customNames = array_column($grouped['custom-squatter'], 'name');
    $coreNames = array_column($grouped['extensions'], 'name');

    expect($coreNames)->toContain('extensions.edit')
        ->and($customNames)->toContain('custom-squatter.own')
        ->and($customNames)->not->toContain('extensions.edit');

    cgAssertLogged('warning', '/extensions\.edit/');
});

it('blocks custom modules from claiming permissions owned in the database by unregistered modules', function (): void {
    app()->instance('modules.manifest', ['custom-squatter' => ['source' => 'custom']]);

    $service = new PermissionService;
    $service->register('extensions', ['extensions.edit' => 'Edit extensions']);
    $service->syncToDatabase();

    // A disabled module's provider never boots, so its permission exists only
    // in the database — the synced ownership guard must still protect it.
    Permission::create([
        'name' => 'ghost-mod.permission',
        'module' => 'ghost-mod',
        'description' => 'Owned by a disabled module',
    ]);

    $service->register('custom-squatter', ['ghost-mod.permission' => 'Squatted claim', 'custom-squatter.own' => 'Own permission']);

    expect(Permission::query()->where('name', 'ghost-mod.permission')->value('module'))->toBe('ghost-mod');

    cgAssertLogged('warning', '/ghost-mod\.permission/');
});

// ─── Manifest name regex unification ─────────────────────────────

it('rejects underscore module names during manifest loading', function (): void {
    $root = cgFixtureRoot();
    $bad = cgWriteManifest($root, 'bad_name');
    $good = cgWriteManifest($root, 'good-name');

    $provider = new DiscoveryProvider(app());

    expect(cgInvokePrivate($provider, 'loadManifest', [$bad]))->toBeNull()
        ->and(cgInvokePrivate($provider, 'loadManifest', [$good]))->toBeArray();

    cgAssertLogged('warning', '/invalid name/');
});

// ─── Fixture helpers ─────────────────────────────────────────────

/**
 * Assert that the log spy recorded a message of the given level matching
 * the given regular expression pattern.
 */
function cgAssertLogged(string $level, string $pattern): void
{
    // Fully qualified: this file has no namespace, so a use-statement alias
    // for the root-level Mockery class would be a no-op warning.
    Log::shouldHaveReceived($level, [Mockery::pattern($pattern)]);
}

/**
 * Create an isolated fixture root mimicking a minimal TallPBX install
 * skeleton (app-modules/, custom-modules/, vendor/, bootstrap/cache/).
 */
function cgFixtureRoot(): string
{
    $root = storage_path('framework/testing/collision-'.uniqid('', true));

    foreach (['app-modules', 'custom-modules', 'vendor', 'bootstrap/cache'] as $dir) {
        @mkdir("{$root}/{$dir}", 0777, true);
    }

    // Tracked in a global registry (not a TestCase property) so the plain
    // helper functions below can register roots for afterEach cleanup.
    $GLOBALS['__cg_fixture_roots'][] = $root;

    return $root;
}

/**
 * Write a module.json manifest into the fixture custom-modules directory.
 */
function cgWriteManifest(string $root, string $name, array $overrides = []): string
{
    return cgWriteModuleFile($root, 'custom-modules', $name, $overrides);
}

/**
 * Write a module.json manifest into the fixture app-modules directory.
 */
function cgWriteCoreManifest(string $root, string $name, array $overrides = []): string
{
    return cgWriteModuleFile($root, 'app-modules', $name, array_merge([
        'namespace' => 'Modules\\'.Str::studly(str_replace('-', ' ', $name)),
    ], $overrides));
}

/**
 * Write a module.json manifest under the given fixture top-level directory.
 */
function cgWriteModuleFile(string $root, string $topDir, string $name, array $overrides = []): string
{
    $manifest = array_merge([
        'name' => $name,
        'version' => '1.0.0',
        'namespace' => 'CustomModules\\'.Str::studly(str_replace('-', ' ', $name)),
        'display_name' => $name,
    ], $overrides);

    $dir = "{$root}/{$topDir}/{$name}";
    @mkdir($dir, 0777, true);

    $path = "{$dir}/module.json";
    file_put_contents($path, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

/**
 * Scaffold a custom fixture module with a real bootable service provider.
 *
 * The provider marks itself booted via a container binding so tests can
 * observe whether the discovery engine registered it.
 */
function cgScaffoldProvider(string $root, string $name, string $studly, array $overrides = []): void
{
    $namespace = 'CustomModules\\'.$studly;

    cgWriteManifest($root, $name, array_merge([
        'namespace' => $namespace,
        'providers' => [$namespace.'\\Providers\\ModuleServiceProvider'],
    ], $overrides));

    $dir = "{$root}/custom-modules/{$name}/src/Providers";
    @mkdir($dir, 0777, true);

    $php = <<<PHP
<?php

declare(strict_types=1);

namespace CustomModules\\{$studly}\\Providers;

use App\\Services\\MenuService;
use App\\Services\\ModuleState;
use App\\Services\\PermissionService;

/**
 * Fixture provider that marks itself booted in the container.
 *
 * The parent is referenced by its fully qualified name because a use
 * statement alias would collide with this class's own short name.
 */
class ModuleServiceProvider extends \App\\Support\\ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return '{$name}';
    }

    protected function moduleNamespace(): string
    {
        return '{$namespace}';
    }

    public function boot(MenuService \$menu, PermissionService \$permission, ModuleState \$modules): void
    {
        parent::boot(\$menu, \$permission, \$modules);

        app()->instance('fixture.{$name}.booted', true);
    }
}
PHP;

    file_put_contents("{$dir}/ModuleServiceProvider.php", $php);
}

/**
 * Run a discovery scan against a fixture root via reflection, restoring
 * the real base path afterwards.
 *
 * @return array<string, array<string, mixed>>
 */
function cgRunDiscovery(string $root): array
{
    app()->setBasePath($root);
    $provider = new DiscoveryProvider(app());

    try {
        $method = new ReflectionMethod($provider, 'discoverModules');

        return $method->invoke($provider);
    } finally {
        app()->setBasePath(test()->originalBasePath);
    }
}

/**
 * Build a module-base provider instance bound to a fixture module path.
 */
function cgAnonymousProvider(string $name, string $path): ModuleBaseProvider
{
    return new class(app(), $name, $path) extends ModuleBaseProvider
    {
        /**
         * Bind the fixture module identity into the anonymous provider.
         */
        public function __construct($app, private readonly string $fixtureName, private readonly string $fixturePath)
        {
            parent::__construct($app);
        }

        protected function moduleName(): string
        {
            return $this->fixtureName;
        }

        protected function moduleNamespace(): string
        {
            return 'CustomModules\\Fixture';
        }

        protected function modulePath(): string
        {
            return $this->fixturePath;
        }
    };
}

/**
 * Invoke a private/protected method on an object via reflection.
 */
function cgInvokePrivate(object $target, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invokeArgs($target, $args);
}

/**
 * Read the translation loader's cascading path list via reflection.
 *
 * @return array<int, string>
 */
function cgLoaderPaths(): array
{
    $loader = app('translation.loader');
    $property = new ReflectionProperty($loader, 'paths');

    return $property->getValue($loader);
}

/**
 * Write a language file into a fixture module's lang/en directory.
 *
 * @param  array<string, string>  $lines
 */
function cgWriteLang(string $root, string $module, string $group, array $lines): string
{
    $dir = "{$root}/custom-modules/{$module}/lang/en";
    @mkdir($dir, 0777, true);

    $path = "{$dir}/{$group}.php";
    file_put_contents($path, "<?php\n\nreturn ".var_export($lines, true).";\n");

    return $path;
}

/**
 * Write a fixture migration file under the fixture custom-modules tree.
 * Only the source is inspected by the guards — the class never loads.
 */
function cgWriteMigration(string $root, string $name, string $upBody, string $module = 'migration-fixture'): string
{
    $dir = "{$root}/custom-modules/{$module}/database/migrations";
    @mkdir($dir, 0777, true);

    $path = "{$dir}/2026_10_14_000000_{$name}.php";
    file_put_contents($path, <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    /**
     * Fixture migration body under collision-guard inspection.
     */
    public function up(): void
    {
        {$upBody}
    }
};
PHP);

    return $path;
}
