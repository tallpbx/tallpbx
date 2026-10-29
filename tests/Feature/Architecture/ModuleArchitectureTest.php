<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantEslScoping;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Modules\Admin\Livewire\ProfileEdit;
use Modules\CallRecordings\Services\CallRecordingService;
use Modules\EmailConnector\Livewire\EmailConnectorEdit;
use Modules\Extensions\Models\Extension;
use Modules\Fax\Services\FaxService;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\MusicOnHold\Services\MusicOnHoldService;
use Modules\Recordings\Services\RecordingService;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;
use Modules\VoicemailMessages\Services\VoicemailMessageService;

/**
 * Architecture tests enforcing contract seams, shared kernel boundaries,
 * and explicit module routing rules across the application architecture.
 */
const ALLOWED_SHARED_KERNEL_MODULE_DEPENDENCIES = [
    // 1. Application Composition Root: global boot bindings and guards
    'app/Providers/AppServiceProvider.php' => 'Composition root registering cross-cutting event guards (e.g. BlockFileStoreDeletion)',

    // 2. Tenant Provisioning: bootstrap seeders for newly created tenants
    'app/Services/TenantDefaults/DefaultDialplansProvisioner.php' => 'Tenant bootstrap provisioner creating initial tenant dialplans',
    'app/Services/TenantDefaults/DefaultFeatureCodesProvisioner.php' => 'Tenant bootstrap provisioner creating initial tenant feature codes',
    'app/Services/TenantDefaults/DefaultMediaDirectoriesProvisioner.php' => 'Tenant bootstrap provisioner configuring media directories',
    'app/Services/TenantDefaults/DefaultMusicOnHoldProvisioner.php' => 'Tenant bootstrap provisioner creating initial music on hold streams',
    'app/Services/TenantDefaults/DefaultSipProfilesProvisioner.php' => 'Tenant bootstrap provisioner creating initial SIP profiles',

    // 3. Load Testing & Seeding Harness: administrative benchmarking tools
    'app/Console/Commands/PbxDialplanLoadTestCommand.php' => 'SIP load generator executing dialplan performance benchmarks',
    'app/Console/Commands/PbxLoadTestSeedCommand.php' => 'Load testing seeder generating realistic multi-tenant PBX fixtures',

    // 4. Privileged System CLI Helpers
    'app/Console/Commands/Backups/RestoreOperationCommand.php' => 'CLI entry point invoked by privileged sudoers restore helper script',
    'app/Console/Commands/BroadcastReconcileOutcomesCommand.php' => 'Scheduled background worker reconciling expired broadcast outcomes',
    'app/Support/BroadcastSettlement.php' => 'Settlement rule shared between broadcast CLI sweeper and outcome handling',

    // 5. FreeSWITCH Telephony & XML Core Engine (mandatory mod_xml_curl bindings)
    'app/Services/Xml/DialplanXmlBuilder.php' => 'FreeSWITCH XML handler dialplan generator mapping PBX routing',
    'app/Services/Xml/DirectoryXmlBuilder.php' => 'FreeSWITCH XML handler directory generator for SIP user authentication',
    'app/Services/Xml/SofiaConfigXmlBuilder.php' => 'FreeSWITCH XML handler configuration generator for sofia.conf',
    'app/Services/DestinationResolver.php' => 'Unified PBX destination router resolving extension, ring group, IVR targets',
    'app/Services/TenantEslScoping.php' => 'Core ESL event tenant resolution across PBX entities',
    'app/Services/TenantIdentityResolver.php' => 'SIP account identity resolver for multi-tenant context identification',
    'app/Observers/RoutingCacheObserver.php' => 'Core routing cache invalidation observer clearing cached dialplan XML',
];

it('forbids direct instantiation and creation of FileStore and MediaAsset outside Modules\FileStores', function (): void {
    $projectRoot = base_path();
    $modulesDir = $projectRoot.'/app-modules';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir)
    );

    $scannedFiles = 0;
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot.'/', '', $filePath);

        if (! str_contains($relativePath, '/src/')
            || str_contains($relativePath, 'app-modules/file-stores/src/')) {
            continue;
        }

        $scannedFiles++;
        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            if (preg_match('/(?:new\s+(?<![a-zA-Z0-9_])(?:FileStore|MediaAsset)\b|(?<![a-zA-Z0-9_])(?:FileStore|MediaAsset)::(?:query\(\)->)?(?:create|firstOrCreate)\b)/', $trimmed)) {
                $violations[] = sprintf(
                    '%s:%d directly instantiates or creates FileStore/MediaAsset [%s]',
                    $relativePath,
                    $lineIndex + 1,
                    $trimmed
                );
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(100)
        ->and($violations)->toBeEmpty(
            "Found unauthorized direct instantiation of FileStore/MediaAsset outside Modules\\FileStores:\n".implode("\n", $violations)
        );
});

it('forbids direct instantiation and creation of Extension outside Modules\Extensions', function (): void {
    $projectRoot = base_path();
    $modulesDir = $projectRoot.'/app-modules';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir)
    );

    $scannedFiles = 0;
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot.'/', '', $filePath);

        if (! str_contains($relativePath, '/src/')
            || str_contains($relativePath, 'app-modules/extensions/src/')) {
            continue;
        }

        $scannedFiles++;
        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            if (preg_match('/(?:new\s+(?<![a-zA-Z0-9_])Extension\b|(?<![a-zA-Z0-9_])Extension::(?:query\(\)->)?(?:create|firstOrCreate)\b)/', $trimmed)) {
                $violations[] = sprintf(
                    '%s:%d directly instantiates or creates Extension [%s]',
                    $relativePath,
                    $lineIndex + 1,
                    $trimmed
                );
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(100)
        ->and($violations)->toBeEmpty(
            "Found unauthorized direct instantiation of Extension outside Modules\\Extensions:\n".implode("\n", $violations)
        );
});

it('enforces that domain media services depend on MediaStorageServiceInterface', function (): void {
    $services = [
        CallRecordingService::class,
        VoicemailMessageService::class,
        RecordingService::class,
        MusicOnHoldService::class,
        FaxService::class,
    ];

    foreach ($services as $serviceClass) {
        $reflection = new ReflectionClass($serviceClass);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull("Service {$serviceClass} must declare a constructor.");

        $hasMediaStorage = false;
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === MediaStorageServiceInterface::class) {
                $hasMediaStorage = true;
                break;
            }
        }

        expect($hasMediaStorage)->toBeTrue(
            "Service {$serviceClass} must inject MediaStorageServiceInterface for managed media lifecycle."
        );
    }
});

it('requires every module to declare an explicit routes/web.php file', function () {
    $missingRouteFiles = [];

    foreach (glob(base_path('app-modules/*'), GLOB_ONLYDIR) as $moduleDir) {
        $moduleName = basename($moduleDir);
        $routeFile = "{$moduleDir}/routes/web.php";

        if (! file_exists($routeFile)) {
            $missingRouteFiles[] = $moduleName;
        }
    }

    expect($missingRouteFiles)->toBeEmpty(
        'All modules must declare an explicit routes/web.php file. Missing in: '.implode(', ', $missingRouteFiles)
    );
});

it('ensures autoRegisterRouteGroups and routeGroups reflection methods are retired', function () {
    $reflector = new ReflectionClass(ModuleServiceProvider::class);

    expect($reflector->hasMethod('autoRegisterRouteGroups'))->toBeFalse(
        'ModuleServiceProvider::autoRegisterRouteGroups() must be retired in favor of explicit routes/web.php files.'
    );

    expect($reflector->hasMethod('routeGroups'))->toBeFalse(
        'ModuleServiceProvider::routeGroups() must be retired in favor of explicit routes/web.php files.'
    );

    expect($reflector->hasMethod('editRouteParameterName'))->toBeFalse(
        'ModuleServiceProvider::editRouteParameterName() must be retired in favor of explicit routes/web.php files.'
    );
});

it('verifies all registered module routes have named routes', function () {
    $routes = Route::getRoutes()->getRoutes();
    $unnamedModuleRoutes = [];

    foreach ($routes as $route) {
        $action = $route->getAction();
        $uses = $action['controller'] ?? ($action['uses'] ?? '');

        if (is_string($uses) && str_starts_with($uses, 'Modules\\')) {
            if ($route->getName() === null) {
                $unnamedModuleRoutes[] = $route->uri();
            }
        }
    }

    expect($unnamedModuleRoutes)->toBeEmpty(
        'All module routes must have an explicit route name. Unnamed routes: '.implode(', ', $unnamedModuleRoutes)
    );
});

it('forbids shared kernel classes in app/ from importing Modules outside the documented allowlist', function (): void {
    $projectRoot = base_path();
    $appDir = $projectRoot.'/app';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir)
    );

    $scannedFiles = 0;
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot.'/', '', $filePath);
        $scannedFiles++;

        $content = (string) file_get_contents($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            if (preg_match('/^use\s+Modules\\\\([^;]+);/i', $trimmed, $matches)) {
                $importedClass = 'Modules\\'.$matches[1];

                if (! array_key_exists($relativePath, ALLOWED_SHARED_KERNEL_MODULE_DEPENDENCIES)) {
                    $violations[] = sprintf(
                        '%s:%d imports [%s]',
                        $relativePath,
                        $lineIndex + 1,
                        $importedClass
                    );
                }
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(50)
        ->and($violations)->toBeEmpty(
            "Found unauthorized module imports in app/:\n".implode("\n", $violations)
        );
});

it('ensures all files in the shared kernel allowlist exist on disk', function (): void {
    $projectRoot = base_path();

    foreach (ALLOWED_SHARED_KERNEL_MODULE_DEPENDENCIES as $relativePath => $justification) {
        expect(file_exists($projectRoot.'/'.$relativePath))
            ->toBeTrue("Allowlisted file [{$relativePath}] does not exist on disk.");
        expect($justification)->not->toBeEmpty();
    }
});

it('verifies that FreeSWITCH domain listeners have been migrated out of app/Listeners', function (): void {
    $projectRoot = base_path();
    $fsListenersDir = $projectRoot.'/app/Listeners/FreeSwitch';

    expect(is_dir($fsListenersDir))->toBeFalse(
        'app/Listeners/FreeSwitch directory must not exist; domain listeners must be owned by their respective modules.'
    );

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot.'/app/Listeners')
    );

    $appListeners = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $appListeners[] = $file->getFilename();
        }
    }

    sort($appListeners);
    expect($appListeners)->toEqual([
        'BroadcastDashboardStatsOnFreeSwitchEvent.php',
        'BroadcastTelephonyOnFreeSwitchEvent.php',
    ]);
});

it('ensures all record-backed Livewire edit components lock their primary record identifier', function (): void {
    $exemptions = [
        ProfileEdit::class,
        EmailConnectorEdit::class,
    ];

    $files = glob(base_path('app-modules/*/src/Livewire/*Edit.php'));
    $testedClasses = 0;
    $missingLocked = [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (! preg_match('/namespace\s+([^;]+);/', $content, $nsMatches) ||
            ! preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            continue;
        }

        $fqcn = $nsMatches[1].'\\'.$classMatches[1];

        if (in_array($fqcn, $exemptions, true)) {
            continue;
        }

        $testedClasses++;
        $reflection = new ReflectionClass($fqcn);
        $lockedProps = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attrs = $property->getAttributes(Locked::class);
            if (! empty($attrs)) {
                $lockedProps[] = $property->getName();
            }
        }

        $shortName = $reflection->getShortName();

        // Must lock primary identifier (Id/Uuid, including editingId, tenantId, menuUuid)
        $hasLockedId = false;
        foreach ($lockedProps as $propName) {
            if (! preg_match('/(Id|Uuid)$/', $propName)) {
                continue;
            }

            // Exclude modal state
            if ($propName === 'pendingDeletionId') {
                continue;
            }

            // Exclude foreign-key selectors
            if ($propName === 'tenantId' && $shortName !== 'TenantsEdit') {
                continue;
            }
            if ($propName === 'extensionId' && $shortName !== 'ExtensionsEdit') {
                continue;
            }
            if ($propName === 'fileStoreId' && $shortName !== 'FileStoresEdit') {
                continue;
            }
            if ($propName === 'destinationId' && $shortName !== 'DestinationsEdit') {
                continue;
            }
            if ($propName === 'sipAccountId' && $shortName !== 'SipAccountsEdit') {
                continue;
            }
            if ($propName === 'gatewayId' && $shortName !== 'GatewaysEdit') {
                continue;
            }

            $hasLockedId = true;
            break;
        }

        if (! $hasLockedId) {
            $missingLocked[] = $fqcn.' (locked properties: '.implode(', ', $lockedProps).')';
        }
    }

    expect($testedClasses)->toBe(43)
        ->and($missingLocked)->toBeEmpty(
            "The following edit components must have #[Locked] on their primary record identifier:\n".implode("\n", $missingLocked)
        );
});

it('enforces PHP 8.5 floor across composer.json and all module manifests', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    expect($composer['require']['php'] ?? null)->toBe('^8.5');

    $manifests = glob(base_path('app-modules/*/module.json'));
    expect($manifests)->not->toBeEmpty();

    $invalid = [];
    foreach ($manifests as $manifestPath) {
        $data = json_decode((string) file_get_contents($manifestPath), true);
        $module = basename(dirname($manifestPath));
        $php = $data['requirements']['php'] ?? null;
        if ($php !== '>=8.5') {
            $invalid[$module] = $php;
        }
    }

    expect($invalid)->toBeEmpty('Modules failing PHP 8.5 requirement floor: '.json_encode($invalid));
});

it('ensures SecurityConfigGenerator does not expose dead or unguarded writeActive method', function (): void {
    expect(method_exists(SecurityConfigGenerator::class, 'writeActive'))->toBeFalse();
});

it('verifies TenantEslScoping filters conferences by member caller_id using native array_any', function (): void {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    Extension::factory()->create(['tenant_id' => $tenant->id, 'extension_number' => '1001']);
    Extension::factory()->create(['tenant_id' => $otherTenant->id, 'extension_number' => '2001']);

    $scoping = app(TenantEslScoping::class);

    $conferences = [
        [
            'name' => 'conf-matching',
            'members_list' => [
                ['caller_id' => '1001', 'uuid' => 'm1'],
            ],
        ],
        [
            'name' => 'conf-other-tenant',
            'members_list' => [
                ['caller_id' => '2001', 'uuid' => 'm2'],
            ],
        ],
        [
            'name' => 'conf-no-members',
            'members_list' => [],
        ],
    ];

    $filtered = $scoping->filterConferencesByTenant($conferences, (int) $tenant->id);

    expect(array_column($filtered, 'name'))->toBe(['conf-matching']);
});

it('forbids raw tenant_id writes using $this->tenantId across all Livewire form components', function (): void {
    $projectRoot = base_path();
    $livewireDirs = glob($projectRoot.'/app-modules/*/src/Livewire');

    $scannedFiles = 0;
    $violations = [];

    foreach ($livewireDirs as $livewireDir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($livewireDir)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();
            $relativePath = str_replace($projectRoot.'/', '', $filePath);
            $scannedFiles++;

            $content = (string) file_get_contents($filePath);
            $lines = explode("\n", $content);

            foreach ($lines as $lineIndex => $line) {
                $trimmed = trim($line);

                if (preg_match('/[\'"]tenant_id[\'"]\s*(?:=>|\]\s*=)\s*(?:\(int\)\s*)?\$this->tenantId\b/', $trimmed)) {
                    $violations[] = sprintf(
                        '%s:%d uses raw $this->tenantId [%s]',
                        $relativePath,
                        $lineIndex + 1,
                        $trimmed
                    );
                }
            }
        }
    }

    expect($scannedFiles)->toBeGreaterThan(30)
        ->and($violations)->toBeEmpty(
            "Found raw \$this->tenantId payload assignment in Livewire form components. Use \$this->resolveTenantId() instead:\n".implode("\n", $violations)
        );
});
