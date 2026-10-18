<?php

declare(strict_types=1);

/**
 * Architecture test: Shared Kernel Boundary & Modular Discipline (Arch Audit M5).
 *
 * The shared application kernel (app/) must not depend on or import domain module classes (Modules\*)
 * outside an explicit, documented allowlist. Domain-specific event listeners, background handlers,
 * and logic must live within their respective module boundaries (app-modules/*).
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

            // Detect import statements matching `use Modules\`
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

    // Ensure all listeners remaining in app/Listeners are strictly shared telemetry broadcasters
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
