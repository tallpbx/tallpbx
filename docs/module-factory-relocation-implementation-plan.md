# Module Ownership: Factory Relocation, Uninstall & Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move all 50 module model factories out of the root app's `database/factories/Pbx/` into their owning modules with canonical Laravel factory resolution, and replace the confusing soft-uninstall model with a clean two-state lifecycle: **disable** (non-destructive panel toggle) and **uninstall** (`php artisan module:uninstall` — complete removal of files, Composer entries, central tests, and data) that is **reversible** via `php artisan module:restore`, which brings the module back from its origin (git for first-party modules, Composer for vendor packages) as easily as it was removed. Database data is intentionally not restored. Removal follows a two-tier policy modeled on FreePBX's dependency-resolved Module Admin (https://github.com/freepbx): core PBX modules (extensions, devices, sip-accounts, destinations, dialplans, dialplan-tools, gateways, sip-profiles, inbound-routes, outbound-routes — plus the required admin/auth/tenant) are protected and can never be uninstalled or disabled (FusionPBX's lesson: spine features are configured off, never deleted — https://github.com/fusionpbx/fusionpbx); every other module is removable, but uninstall refuses while any installed module declares a requirement on it, backed by authoritative `requirements.modules` declarations and boundary tests.

**Architecture:** Factories move to `app-modules/{name}/src/Database/Factories/` under `Modules\{Studly}\Database\Factories` (covered by each module's existing `Modules\X\` → `src/` PSR-4 mapping, so no composer.json changes). Each model drops its hand-written `newFactory()` override; Laravel's default resolution (`\Models\` → `\Database\Factories\`) then resolves the same factory. Module-related tests move INTO each module (`app-modules/{name}/tests/`) and are discovered through one glob in `phpunit.xml`, making every module a self-contained package — uninstall removes its tests with the directory, restore returns them with it. `ModuleLifecycleService` is rewritten to own the full uninstall/restore lifecycle: uninstall runs the module's tagged `ModuleUninstaller` (data drop), deletes local files or removes the vendor Composer package, strips Composer entries, and keeps a minimal registry marker row (`status = 'uninstalled'` + recorded `composer_package`) so restore knows the module's origin. Restore re-pulls the module directory (git restore / composer require), re-adds Composer entries, re-enables the registry row, re-runs migrations (empty tables), and re-seeds permissions. The web panel keeps only the enable/disable toggle and shows a CLI restore hint for uninstalled modules. A module-aware test guard (`Tests\Traits\ModuleAwareTestGuard`) marks any test as SKIPPED when a module it references is not installed, so `php artisan test` stays green after uninstalls — never loud failures.

**Tech Stack:** Laravel 13 (PHP 8.3+), Pest 4, `Illuminate\Filesystem\Filesystem`, `Symfony\Component\Process\Process`, existing `App\Models\Module` registry, existing `ModuleUninstaller` / `ModuleTableUninstaller` infrastructure, `Database\Seeders\AdminSeeder`.

**Spec:** This document (decisions fixed with the user on 2026-09-27: factories move + overrides dropped; tests move into modules; naming = disable vs uninstall; uninstall is complete and reversible-from-origin with restore as easy as uninstall; vendor modules handled through Composer; panel keeps only disable/enable; core PBX modules protected and non-disable-able, FreePBX-style — see the External References section below). Companion context: `AGENTS.md` (Modular Architecture), `app/Support/ModuleServiceProvider.php`, `app/Services/ModuleLifecycleService.php`, `app-modules/admin/src/Livewire/ModulesList.php`.

**Audience:** This plan is written for both human engineers and AI agents/harnesses. Humans can skim the Task Index and Review Focus for orientation; agents should execute the numbered tasks top-to-bottom — every step is decision-complete with exact file paths, commands, code, and expected results.

## Task Index

| # | Task | Deliverable |
| --- | --- | --- |
| 1 | Factory Isolation Regression Tests | Two guard tests proving factories resolve inside their modules (written red) |
| 2 | Relocate the 50 Factories and Drop the 49 Overrides | Factories under `app-modules/{name}/src/Database/Factories/`; standard `HasFactory` resolution; `make:module` scaffolds the directory |
| 3 | Registry `composer_package` Column | Migration + mass-assignable column for vendor restore origins |
| 4 | ModuleLifecycleService Rewrite | Full uninstall/restore lifecycle, vendor branch, dependent-refusal, registry marker |
| 5 | Move Module Tests Into Their Modules | 44 test folders under `app-modules/{name}/tests/` + `phpunit.xml` glob discovery |
| 6 | Auto-Registered Module Config | `config/{name}.php` merges over centralized defaults; nothing changes until a module opts in |
| 7 | Core Module Protection & Declared Dependencies | 10 protected telephony modules; `requirements.modules` populated; boundary + acyclicity tests |
| 8 | Module-Aware Test Guard | Uninstalled-module references become skips, never failures |
| 9 | `module:uninstall` / `module:restore` Commands | CLI lifecycle with typed confirmation and restore hints |
| 10 | Panel Simplification | Disable/enable only; protected modules non-disable-able; CLI hint for uninstalled |
| 11 | Documentation and Changelog | AGENTS.md conventions + CHANGELOG entries |
| 12 | New-Module Scaffold Completeness & AI Guidance Documents | make:module scaffolds the full new-module shape; manifest schema fixed; tallpbx-custom + testing-best-practices skills teach the conventions |
| 13 | Full Verification and Release | pint, full parallel suite, command smoke test, plan doc removal |

## Terminology

Shared vocabulary for humans and agents reading or executing this plan:

- **disable / enable** — the non-destructive panel toggle: flips the registry `enabled` flag; files and data are untouched; always reversible.
- **uninstall** — the complete, destructive removal via `php artisan module:uninstall`: deletes the module directory (including its in-module tests), Composer entries, permissions, and data (via the module's uninstall handler when one exists). Keeps a registry marker row (`status = 'uninstalled'` + recorded `composer_package`) so restore knows the origin. Database data is intentionally not restored.
- **restore** — `php artisan module:restore`: reinstalls from git (first-party modules) or Composer (vendor packages), re-adds Composer entries, re-enables the registry row, re-runs migrations into empty tables, and re-seeds permissions. As easy as uninstall, one command, symmetric UX.
- **protected module** — a core PBX module (`extensions`, `devices`, `sip-accounts`, `destinations`, `dialplans`, `dialplan-tools`, `gateways`, `sip-profiles`, `inbound-routes`, `outbound-routes`): never uninstallable, never disable-able in the panel (FreePBX-style core).
- **required module** — `admin`, `auth`, `tenant`: cannot be disabled or uninstalled.
- **core module** — the union of protected and required modules (13 total).
- **vendor module** — a Composer-installed third-party or private package living under `vendor/`; uninstall runs `composer remove`, restore runs `composer require` using the recorded package name.
- **centralized config** — app-level `config/*.php` defaults. A module may ship `config/{name}.php`; it merges over the centralized values under the module's key (module values win on conflicts, all other keys survive).
- **registry marker** — the `modules` table row kept after uninstall (`status = 'uninstalled'`, `composer_package` recorded) so restore can determine where the module came from.
- **in-module tests** — Pest tests inside `app-modules/{name}/tests/`, discovered through the `phpunit.xml` glob; they are deleted with the module on uninstall.
- **requirements.modules** — the module manifest's declared dependency list; made authoritative in Task 7 and enforced by `ModuleBoundaryTest` (undeclared references or cycles fail the suite).

## Global Constraints

- Branch `2.0` is unreleased: no backwards-compatibility shims, aliases, or fallbacks — write everything in modern canonical form. Old soft-uninstall APIs (`ModuleLifecycleService::reinstall()`, panel uninstall/reinstall actions) are removed, not deprecated.
- Every class and method gets a PHPDoc comment in simple language; inline comments where intent is not obvious.
- `declare(strict_types=1);` and native type hints everywhere; PSR-12.
- Pest tests only; run with `php artisan test --compact --parallel`.
- `php artisan optimize:clear` after every code change.
- Granular conventional commits; never commit/push without explicit user approval.
- The confirmation phrase convention for destructive CLI operations: type `UNINSTALL <name>` exactly.
- No placeholder content in code: no TODOs, no dead links.

## External References & Design Rationale

Why the module lifecycle and boundary decisions look the way they do — for agents and harnesses reviewing this plan:

- **FreePBX — https://github.com/freepbx** — the reference model for module dependencies and removal policy. Every module ships a manifest declaring its dependencies (`<depends>` in `module.xml`), and the Module Admin **refuses to uninstall or disable a module while any installed module depends on it**. The `core` and `framework` modules can never be uninstalled or disabled — only upgraded. This plan mirrors that model in Task 7: a protected core set (never uninstallable, never disable-able) plus dependency-guarded removal for everything else, with the dependency graph derived from the code rather than curated by hand.
- **FusionPBX — https://github.com/fusionpbx/fusionpbx** — the cautionary counter-model. FusionPBX is monolithic: it has **no module removal concept**; features are toggled through permissions, menu visibility, and Default Settings. The lesson applied here: core telephony spine features (extensions, dialplans, destinations, sip accounts, devices) must be *configured off* — hidden via permissions — never deleted or disabled. Hence the protected core set in Task 7.
- **Building modular systems in Laravel (Sevalla) — https://sevalla.com/blog/building-modular-systems-laravel/** — the reference for where module code and tests live: module tests inside the module directory, integration tests between modules at the app level. That is the basis for Task 5 (tests move into `app-modules/{name}/tests/`) and the centralized cross-module suites with the skip guard (Task 8).

## Review Focus

1. **Mismatched or missing confirmation phrase** for an uninstall must refuse — never delete anything on a wrong phrase. (Task 4)
2. **Unknown module name / invalid name / non-local non-vendor module** must refuse with a clear reason. (Task 4)
3. **Required or protected modules** must refuse uninstall (checked against both the on-disk manifest and the registry row). (Task 4)
4. **Modules without an uninstall handler** must be uninstallable, but leave database tables intact and warn loudly. (Task 4)
5. **Composer failure** during uninstall must not leave a broken state: the repository and require entries are still stripped and a warning is reported. (Task 4)
6. **Uninstall must retain the registry marker row** (`status = uninstalled`, recorded `composer_package`) — without it, restore cannot know the module's origin. (Task 4)
7. **Restore must refuse when the origin is unknown** — not tracked in git and no recorded Composer package. (Task 4)
8. **Restore must not resurrect data** — migrations re-run into empty tables, and the command output says so plainly. (Tasks 4–9)
9. **A module name present both locally and in vendor** must prefer the local `app-modules` directory. (Task 4)
10. **The panel must expose no destructive buttons** — only enable/disable and a CLI restore hint for uninstalled modules; protected modules cannot be disabled. (Task 10)
11. **Cross-factory imports** inside factory files (e.g. `IvrMenuFactory` using `IvrMenuOptionFactory`) must be rewritten to the new module namespaces — verified by the migration script aborting on unknown targets. (Task 2)
12. **`make:module` scaffold** must create the factory directory so new modules follow the convention from day one. (Task 2)
13. **Module tests ship inside the module directory** — uninstall removes them with the directory, restore returns them with it, and the suite runs them through `phpunit.xml` glob discovery. (Task 5)
14. **The suite must stay green after uninstalls** — every test referencing a removed module must be SKIPPED (never failed); URL-only references get explicit annotations. (Task 8)
15. **Uninstall warnings must say "skipped", not "failed"** — cross-module test hits are reported as tests that will skip until the module is restored. (Task 4)
16. **Centralized config stays authoritative unless a module overrides it** — module config files merge over app-level defaults under the module's key, never replacing them wholesale. (Task 6)
17. **Core modules must be immovable** — protected manifests refuse uninstall, the panel refuses to disable them, and the core set is pinned by a regression test. (Task 7)
18. **Every cross-module class reference is declared** — `requirements.modules` is authoritative; the boundary test fails on undeclared references or cycles. (Task 7)
19. **Uninstall refuses while installed dependents exist** — dependency-guarded removal, not just warnings. (Task 4)

---

## File Structure Map

**Create:**
- `tests/Feature/ModuleFactoryIsolationTest.php` — regression guards for factory placement
- `tests/Traits/ModuleAwareTestGuard.php` — trait that skips tests for uninstalled modules
- `tests/Feature/Testing/ModuleAwareTestGuardTest.php` — guard tests
- `tests/Feature/Modules/CoreModulesProtectedTest.php` — pins the protected core set
- `tests/Feature/Modules/ModuleBoundaryTest.php` — declared dependencies + acyclic graph
- `scripts/migrate-module-requirements.php` — one-shot dependency declaration script (deleted after verification)
- `database/migrations/2026_09_27_000000_add_composer_package_to_modules_table.php` — registry column for install origin
- `tests/Feature/Modules/ModuleComposerPackageMigrationTest.php` — migration test
- `app/Console/Commands/ModuleUninstallCommand.php` — `module:uninstall` CLI
- `app/Console/Commands/ModuleRestoreCommand.php` — `module:restore` CLI
- `tests/Feature/Commands/ModuleUninstallCommandTest.php` — command tests
- `tests/Feature/Commands/ModuleRestoreCommandTest.php` — command tests
- `scripts/migrate-module-factories.php` — one-shot migration script (deleted after verification)

**Move (50 factory files + 44 test folders):**
- `database/factories/Pbx/{X}Factory.php` → `app-modules/{kebab}/src/Database/Factories/{X}Factory.php` (mapping in Task 2)
- `tests/Feature/Modules/{Studly}/*` → `app-modules/{kebab}/tests/*` (script in Task 5)

**Modify:**
- 49 model files under `app-modules/*/src/Models/` — drop `newFactory()` overrides, factory imports, and retype `@use HasFactory<...>` docblocks
- `app/Console/Commands/MakeModuleCommand.php` — scaffold `src/Database/Factories/` and `tests/`
- `phpunit.xml` — Feature suite glob for `app-modules/*/tests`
- `tests/Feature/Commands/NativeModuleCommandTest.php` — assert factory directory + new command names
- `app/Services/ModuleLifecycleService.php` — rewrite: full uninstall + restore (replaces soft uninstall/reinstall)
- 10 core module manifests (`protected: true`) and all 57 manifests (`requirements.modules` populated)
- `app/Models/Module.php` — `$fillable` += `composer_package`
- `app-modules/admin/src/Livewire/ModulesList.php` — remove uninstall/reinstall methods, keep toggle
- `app-modules/admin/resources/views/modules-list.blade.php` — remove modal/buttons, add CLI restore hint
- `lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php` — translation keys
- `tests/Pest.php` — global skip guard hook for the Feature suite
- `tests/TestCase.php` — use the guard trait
- `tests/DuskTestCase.php` — use the guard trait
- `tests/Browser/PanelSmokeTest.php` — per-test module annotations
- `tests/Feature/Livewire/ModulesListTest.php` — rewrite for the new panel behavior
- `tests/Feature/Services/ModuleLifecycleServiceTest.php` — rewrite for the new lifecycle model
- `AGENTS.md` — document factory convention and disable/uninstall/restore lifecycle
- `CHANGELOG.md` — Unreleased entries
- `.agents/skills/tallpbx-custom/SKILL.md` — new-module design conventions for AI agents
- `.agents/skills/testing-best-practices/SKILL.md` — module test location + skip-guard rules
- `resources/schemas/module.json` — `requirements.modules` as a string array

**Delete:**
- `database/factories/Pbx/` (entire directory after the move)

---

## Task 1: Factory Isolation Regression Tests (Red)

**Files:**
- Create: `tests/Feature/ModuleFactoryIsolationTest.php`

**Interfaces:**
- Produces: two tests that must pass after Task 2 and stay green forever after.

- [x] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps module factory classes inside their owning modules', function (): void {
    // Module code must never reference the root app's factory namespace.
    $violations = collect(File::allFiles(base_path('app-modules')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'Database\\Factories'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

it('resolves every module model factory from its own module namespace', function (): void {
    // Every model using HasFactory must yield a factory class that lives in
    // the same Modules\{Studly}\Database\Factories namespace, resolved by
    // Laravel's default factory resolution (no newFactory() overrides).
    $models = collect(File::allFiles(base_path('app-modules')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'use HasFactory'))
        ->map(function ($file): ?string {
            $contents = $file->getContents();

            if (! preg_match('/namespace\s+(Modules\\\\[A-Za-z0-9_]+)\\\\Models;/', $contents, $ns)) {
                return null;
            }

            if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $contents, $class)) {
                return null;
            }

            $fqcn = $ns[1].'\\Models\\'.$class[1];

            return class_exists($fqcn) ? $fqcn : null;
        })
        ->filter();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        $factory = $model::factory();

        expect(get_class($factory))->toStartWith('Modules\\')
            ->and(get_class($factory))->toEndWith('Factory');
    }
});
```

- [x] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=ModuleFactoryIsolationTest`
Expected: FAIL — first test lists 49 files containing `Database\Factories`; second test fails because factories resolve to `Database\Factories\Pbx\...`.

---

## Task 2: Relocate the 50 Factories and Drop the 49 Overrides

**Files:**
- Create: `scripts/migrate-module-factories.php` (temporary, deleted in Step 6)
- Modify: 49 model files (derived by the script from each factory's `$model` declaration)
- Modify: `app/Console/Commands/MakeModuleCommand.php`
- Modify: `tests/Feature/Commands/NativeModuleCommandTest.php`
- Delete: `database/factories/Pbx/` (script rmdir, Step 5)

**Interfaces:**
- Consumes: nothing from Task 1 (its tests stay red until this task completes).
- Produces: `app-modules/{name}/src/Database/Factories/{X}Factory.php` in namespace `Modules\{Studly}\Database\Factories\{X}Factory`; models with no `newFactory()` and no `Database\Factories` imports.

**Factory → module mapping** (50 entries; script verifies each factory's `$model` matches):

```php
$factoryMap = [
    'AccessControlFactory' => 'Acl',
    'AccessControlNodeFactory' => 'Acl',
    'BackupFactory' => 'Backups',
    'BridgeFactory' => 'Bridges',
    'CallBlockFactory' => 'CallBlocks',
    'CallBroadcastFactory' => 'CallBroadcast',
    'CallBroadcastRecipientFactory' => 'CallBroadcast',
    'CallCenterAgentFactory' => 'CallCenters',
    'CallCenterQueueFactory' => 'CallCenters',
    'CallFlowFactory' => 'CallFlows',
    'CallForwardFactory' => 'CallForwards',
    'CallRecordingFactory' => 'CallRecordings',
    'CdrFactory' => 'XmlCdr',
    'ConferenceCenterFactory' => 'ConferenceCenters',
    'ConferenceFactory' => 'Conferences',
    'DestinationFactory' => 'Destinations',
    'DeviceFactory' => 'Devices',
    'DialplanDetailFactory' => 'Dialplans',
    'DialplanFactory' => 'Dialplans',
    'EmailQueueItemFactory' => 'EmailQueue',
    'EmailTemplateFactory' => 'EmailTemplates',
    'EmergencyFactory' => 'Emergency',
    'ExtensionFactory' => 'Extensions',
    'ExtensionSettingFactory' => 'ExtensionSettings',
    'FaxInboxFactory' => 'Fax',
    'FeatureCodeFactory' => 'FeatureCodes',
    'FollowMeFactory' => 'FollowMe',
    'GatewayFactory' => 'Gateways',
    'HotDeskSessionFactory' => 'HotDesking',
    'InboundRouteFactory' => 'InboundRoutes',
    'IvrMenuFactory' => 'IvrMenus',
    'IvrMenuOptionFactory' => 'IvrMenus',
    'MediaAssetFactory' => 'FileStores',
    'MusicOnHoldFactory' => 'MusicOnHold',
    'NumberTranslationFactory' => 'NumberTranslations',
    'OutboundRouteFactory' => 'OutboundRoutes',
    'PinNumberFactory' => 'PinNumbers',
    'ProvisionTemplateFactory' => 'Provision',
    'RecordingFactory' => 'Recordings',
    'RingGroupExtensionFactory' => 'RingGroups',
    'RingGroupFactory' => 'RingGroups',
    'SipAccountFactory' => 'SipAccounts',
    'SipProfileFactory' => 'SipProfiles',
    'SipTrunkFactory' => 'SipTrunks',
    'SpeechConfigFactory' => 'Speech',
    'TenantLimitFactory' => 'TenantLimits',
    'TimeConditionFactory' => 'TimeConditions',
    'TranscriptionFactory' => 'Transcribe',
    'VoicemailFactory' => 'Voicemails',
    'VoicemailMessageFactory' => 'VoicemailMessages',
];
```

- [x] **Step 1: Write the one-shot migration script**

```php
<?php

declare(strict_types=1);

// One-shot migration: move module factories from database/factories/Pbx into
// their owning modules, and strip the now-redundant newFactory() overrides
// from module models. This script is deleted after verification.

$base = dirname(__DIR__);
$factoryDir = $base.'/database/factories/Pbx';

$factoryMap = [/* 50 entries from the mapping table above */];

// Convert a Studly module name into its kebab-case directory (XmlCdr => xml-cdr).
$kebab = fn (string $studly): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));

$errors = [];
$moved = [];

foreach ($factoryMap as $factory => $studly) {
    $src = "{$factoryDir}/{$factory}.php";
    $contents = file_get_contents($src);

    if ($contents === false) {
        $errors[] = "Missing factory file: {$src}";
        continue;
    }

    // Verify the declared model belongs to the mapped module.
    if (! preg_match('/protected \$model = (Modules\\\\[A-Za-z0-9_]+)\\\\Models\\\\[A-Za-z0-9_]+::class;/', $contents, $m)) {
        $errors[] = "{$factory}: no model declaration found";
        continue;
    }

    if ($m[1] !== "Modules\\{$studly}") {
        $errors[] = "{$factory} declares {$m[1]} but is mapped to Modules\\{$studly}";
        continue;
    }

    // Rewrite the factory's own namespace and any cross-factory imports.
    $contents = str_replace('namespace Database\\Factories\\Pbx;', "namespace Modules\\{$studly}\\Database\\Factories;", $contents);
    $contents = preg_replace_callback(
        '/use Database\\\\Factories\\\\Pbx\\\\([A-Za-z0-9_]+)Factory;/',
        function (array $match) use ($factoryMap, &$errors): string {
            $target = $factoryMap[$match[1].'Factory'] ?? null;

            if ($target === null) {
                $errors[] = "Unknown cross-factory import: {$match[1]}";
                return $match[0];
            }

            return "use Modules\\{$target}\\Database\\Factories\\{$match[1]}Factory;";
        },
        $contents,
    );

    // Move the factory into the module's src/Database/Factories directory.
    $destDir = "{$base}/app-modules/".$kebab($studly).'/src/Database/Factories';

    if (! is_dir($destDir)) {
        $errors[] = "Destination directory missing: {$destDir}";
        continue;
    }

    file_put_contents("{$destDir}/{$factory}.php", $contents);
    unlink($src);
    $moved[] = $factory;
}

// Strip newFactory() overrides from every model that imported the old factories.
foreach ($factoryMap as $factory => $studly) {
    $dir = "{$base}/app-modules/".$kebab($studly).'/src/Models';
    $modelShort = str_replace('Factory', '', $factory);
    $modelFile = "{$dir}/{$modelShort}.php";

    if (! is_file($modelFile)) {
        $errors[] = "Expected model file missing: {$modelFile}";
        continue;
    }

    $contents = file_get_contents($modelFile);

    // Remove the import of the old factory location.
    $contents = str_replace("use Database\\Factories\\Pbx\\{$factory};\n", '', $contents);

    // Point the @use HasFactory docblock at the module-local factory class.
    $contents = str_replace(
        "/** @use HasFactory<{$factory}> */",
        "/** @use HasFactory<Modules\\{$studly}\\Database\\Factories\\{$factory}> */",
        $contents,
    );

    // Remove the override in its known shapes; anything else must fail loudly.
    $shapes = [
        "\n    protected static function newFactory(): {$factory}\n    {\n        return {$factory}::new();\n    }",
        "\n    protected static function newFactory()\n    {\n        return {$factory}::new();\n    }",
    ];

    $removed = false;

    foreach ($shapes as $shape) {
        if (str_contains($contents, $shape)) {
            $contents = str_replace($shape, '', $contents);
            $removed = true;
        }
    }

    // Remove a docblock immediately preceding an untyped override (Backup style).
    $docblockShape = "#\n    /\*\*.*?\*/\n    protected static function newFactory\(\)\n    \{\n        return {$factory}::new\(\);\n    \}#s";

    if (preg_match($docblockShape, $contents)) {
        $contents = preg_replace($docblockShape, '', $contents);
        $removed = true;
    }

    if (! $removed || str_contains($contents, 'newFactory')) {
        $errors[] = "Could not fully remove newFactory() from {$modelFile}";
        continue;
    }

    if (str_contains($contents, 'Database\\Factories')) {
        $errors[] = "Leftover Database\\Factories reference in {$modelFile}";
        continue;
    }

    file_put_contents($modelFile, $contents);
}

if ($errors !== []) {
    fwrite(STDERR, "Migration aborted with errors:\n".implode("\n", $errors)."\n");
    exit(1);
}

// Remove the now-empty Pbx factory directory.
rmdir($factoryDir);

echo 'Moved '.count($moved).' factories into their modules.'."\n";
```

- [x] **Step 2: Run the migration script**

Run: `php scripts/migrate-module-factories.php`
Expected: `Moved 50 factories into their modules.` — any error means a pattern did not match; fix that file's shape and re-run (already-moved factories no longer have a source file, so remove the missing-file error path if re-running is needed).

- [x] **Step 3: Update the make:module scaffold (TDD: assertion first)**

In `tests/Feature/Commands/NativeModuleCommandTest.php`, inside the `scaffolds modules using current TallPBX conventions` test, extend the directory assertions:

```php
    expect($modulePath.'/src/Livewire')->toBeDirectory()
        ->and($modulePath.'/src/Database/Factories')->toBeDirectory()
        ->and($modulePath.'/src/Providers/ModuleServiceProvider.php')->toBeFile()
```

Run: `php artisan test --compact --parallel --filter=NativeModuleCommandTest`
Expected: FAIL — `src/Database/Factories` is not scaffolded yet.

Then in `app/Console/Commands/MakeModuleCommand.php` `createDirectories()` add:

```php
        mkdir("{$moduleDir}/src/Database/Factories", 0755, true);
```

and add the bullet `'src/Database/Factories/ — model factory directory'` to the `bulletList()` scaffold summary. Re-run the test: PASS.

- [x] **Step 4: Refresh autoload and caches**

Run: `composer dump-autoload` (the backups module uses classmap autoloading; new classes must be discoverable) then `php artisan optimize:clear`.

- [x] **Step 5: Verify no stragglers**

Run: `grep -r "Database\\\\Factories" app-modules database --include="*.php" || echo "CLEAN"`
Expected: `CLEAN` (the `database/factories/` root keeps only the 7 app-level factories: Admin, Group, Menu, Permission, Tenant, TenantDomain, User — these do not live under Pbx and are untouched).

- [x] **Step 6: Run the guard tests and delete the script**

Run: `php artisan test --compact --parallel --filter=ModuleFactoryIsolationTest`
Expected: PASS. Then delete `scripts/migrate-module-factories.php`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: move module factories into their owning modules" -m "Relocate 50 factories from database/factories/Pbx into each module's src/Database/Factories namespace and drop the 49 newFactory() overrides so models use Laravel's standard factory resolution. Add isolation guard tests and scaffold the factory directory in make:module."
```

---

## Task 3: Registry `composer_package` Column (TDD)

**Files:**
- Create: `tests/Feature/Modules/ModuleComposerPackageMigrationTest.php`
- Create: `database/migrations/2026_09_27_000000_add_composer_package_to_modules_table.php`
- Modify: `app/Models/Module.php`

**Interfaces:**
- Produces: `modules.composer_package` nullable string column, mass-assignable on `Module`. Task 4 records it at uninstall and reads it at restore.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Support\Facades\Schema;

it('stores the composer package on module registry rows', function (): void {
    expect(Schema::hasColumn('modules', 'composer_package'))->toBeTrue();

    Module::create([
        'name' => 'private-module',
        'display_name' => 'Private Module',
        'version' => '1.0.0',
        'enabled' => true,
        'composer_package' => 'acme/private-module',
    ]);

    expect(Module::where('name', 'private-module')->value('composer_package'))->toBe('acme/private-module');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=ModuleComposerPackageMigrationTest`
Expected: FAIL — `composer_package` column does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the Composer package a module was installed from so
     * module:restore can reinstall vendor modules after an uninstall.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('composer_package')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migration by dropping the recorded package column.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('composer_package');
        });
    }
};
```

- [ ] **Step 4: Allow mass assignment on the model**

In `app/Models/Module.php` add `'composer_package'` to `$fillable`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=ModuleComposerPackageMigrationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: record composer package origin on module registry rows" -m "Add a nullable composer_package column to the modules table so module:restore can reinstall vendor modules after a complete uninstall."
```

---

## Task 4: ModuleLifecycleService Rewrite — Uninstall & Restore (TDD)

**Files:**
- Modify: `tests/Feature/Services/ModuleLifecycleServiceTest.php` (full rewrite)
- Modify: `app/Services/ModuleLifecycleService.php`

**Interfaces:**
- Produces `App\Services\ModuleLifecycleService` with:
  - `confirmationPhrase(string $name): string` — returns `UNINSTALL {name}`
  - `previewUninstall(string $name): array` — `['can_uninstall' => bool, 'reason' => ?string, 'module_kind' => 'local'|'vendor'|null, 'module_dir' => ?string, 'vendor_package' => ?string, 'composer_package' => string, 'repository_entry' => bool, 'registry_row' => bool, 'permission_count' => int, 'uninstall_items' => array<int,string>, 'uninstall_available' => bool, 'restore_hint' => string, 'display_name' => string, 'version' => string, 'warnings' => array<int,string>]`
  - `uninstall(string $name, string $confirmation): array` — `['module_kind' => string, 'module_dir_deleted' => bool, 'composer_package_removed' => bool, 'repository_entry_removed' => bool, 'require_entry_removed' => bool, 'permissions_deleted' => int, 'uninstall_ran' => bool, 'restore_hint' => string, 'warnings' => array<int,string>]`
  - `previewRestore(string $name): array` — `['can_restore' => bool, 'reason' => ?string, 'source' => 'git'|'composer'|null, 'module_dir' => string, 'composer_package' => string, 'registry_row' => bool, 'items' => array<int,string>, 'data_notice' => string]`
  - `restore(string $name): array` — `['source' => string, 'files_restored' => bool, 'data_notice' => string]`
- Both destructive entry points throw `Illuminate\Validation\ValidationException` on refusal. The class must NOT be final.
- Constructor: `(Application $app, Filesystem $files, ?string $basePath = null, ?Closure $composerRunner = null, ?Closure $gitRunner = null)` — auto-resolvable by the container, no provider binding needed. Runners: `Closure(array<int, string> $args): bool`.

- [ ] **Step 1: Write the failing service tests**

```php
<?php

declare(strict_types=1);

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Build a hermetic sandbox that mirrors the project layout, so tests
    // never touch the real app-modules/ tree or composer.json.
    $this->sandbox = sys_get_temp_dir().'/pbx-lifecycle-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module',
        'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule',
        'display_name' => 'Demo Module',
        'required' => false,
        'protected' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    File::put($this->sandbox.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => 'app-modules/demo-module']],
        'require' => ['tallpbx/module-demo-module' => '*'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->composerCalls = [];
    $this->gitCalls = [];

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: true);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('exposes the uninstall confirmation phrase', function (): void {
    expect($this->service->confirmationPhrase('demo-module'))->toBe('UNINSTALL demo-module');
});

it('refuses modules that are neither local nor vendor', function (): void {
    expect(fn () => $this->service->uninstall('ghost-module', 'UNINSTALL ghost-module'))
        ->toThrow(ValidationException::class);
});

it('refuses invalid module names', function (): void {
    expect(fn () => $this->service->uninstall('../evil', 'UNINSTALL ../evil'))
        ->toThrow(ValidationException::class);
});

it('refuses required or protected modules', function (): void {
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => true,
    ]));

    expect(fn () => $this->service->uninstall('demo-module', 'UNINSTALL demo-module'))
        ->toThrow(ValidationException::class);
});

it('refuses a mismatched confirmation phrase', function (): void {
    expect(fn () => $this->service->uninstall('demo-module', 'not the phrase'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('uninstalls a local module completely but keeps the registry marker', function (): void {
    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);
    Permission::create(['name' => 'demo-module.view', 'module' => 'demo-module']);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['module_kind'])->toBe('local')
        ->and($report['module_dir_deleted'])->toBeTrue()
        ->and($report['composer_package_removed'])->toBeTrue()
        ->and($report['repository_entry_removed'])->toBeTrue()
        ->and($report['require_entry_removed'])->toBeTrue()
        ->and($report['permissions_deleted'])->toBe(1)
        ->and($report['uninstall_ran'])->toBeFalse()
        ->and($this->composerCalls)->toBe(['remove tallpbx/module-demo-module --no-interaction'])
        ->and(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeFalse()
        ->and(Permission::where('module', 'demo-module')->exists())->toBeFalse()
        ->and($report['warnings'])->not->toBeEmpty()
        ->and($report['restore_hint'])->toContain('module:restore demo-module');

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect($composer['repositories'])->toBe([])
        ->and($composer['require'])->not->toHaveKey('tallpbx/module-demo-module');

    // The registry marker row is what makes restore possible.
    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
        'composer_package' => 'tallpbx/module-demo-module',
    ]);
});

it('runs the module uninstall handler when one is registered', function (): void {
    $uninstaller = testDemoUninstaller();
    registerDemoUninstaller($uninstaller);
    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['uninstall_ran'])->toBeTrue()
        ->and($uninstaller->uninstalled)->toBeTrue();
});

it('uninstalls a vendor module through Composer without touching host files', function (): void {
    File::deleteDirectory($this->sandbox.'/app-modules/demo-module');
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));

    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);
    Permission::create(['name' => 'demo-module.view', 'module' => 'demo-module']);

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['module_kind'])->toBe('vendor')
        ->and($report['module_dir_deleted'])->toBeFalse()
        ->and($report['repository_entry_removed'])->toBeFalse()
        ->and($this->composerCalls)->toBe(['remove acme/demo-package --no-interaction'])
        // Vendor files belong to Composer: the service leaves them for composer remove.
        ->and(File::exists($this->sandbox.'/vendor/acme/demo-package/module.json'))->toBeTrue();

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect($composer['repositories'])->toHaveCount(1)
        ->and($composer['require'])->toHaveKey('tallpbx/module-demo-module');

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'status' => Module::StatusUninstalled,
        'composer_package' => 'acme/demo-package',
    ]);
});

it('prefers the local directory when a module exists both locally and in vendor', function (): void {
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
    ]));

    $preview = $this->service->previewUninstall('demo-module');

    expect($preview['module_kind'])->toBe('local')
        ->and($preview['composer_package'])->toBe('tallpbx/module-demo-module');
});

it('refuses to uninstall while installed modules require it', function (): void {
    File::makeDirectory($this->sandbox.'/app-modules/dependent-module', 0755, true);
    File::put($this->sandbox.'/app-modules/dependent-module/module.json', json_encode([
        'name' => 'dependent-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DependentModule', 'display_name' => 'Dependent Module',
        'required' => false, 'protected' => false,
        'requirements' => ['modules' => ['demo-module']],
    ]));

    try {
        $this->service->uninstall('demo-module', 'UNINSTALL demo-module');
        $this->fail('Uninstall should have been refused.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->first())->toContain('dependent-module');
    }

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('restores a local module from git with empty tables and its central tests', function (): void {
    $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    $report = $this->service->restore('demo-module');

    expect($report['source'])->toBe('git')
        ->and($report['files_restored'])->toBeTrue()
        ->and($this->gitCalls)->toContain('restore --source=HEAD -- app-modules/demo-module')
        ->and(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue();

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect(collect($composer['repositories'])->pluck('url'))->toContain('app-modules/demo-module')
        ->and($composer['require'])->toHaveKey('tallpbx/module-demo-module')
        // Migrations re-ran into a fresh (empty) table.
        ->and(Schema::hasTable('demo_restore'))->toBeTrue();
});

it('refuses to restore when the origin is unknown', function (): void {
    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    expect(fn () => $this->service->restore('demo-module'))
        ->toThrow(ValidationException::class);
});

it('restores a vendor module through Composer', function (): void {
    File::deleteDirectory($this->sandbox.'/app-modules/demo-module');
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
    ]));

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    $report = $this->service->restore('demo-module');

    expect($report['source'])->toBe('composer')
        ->and($this->composerCalls)->toContain('require acme/demo-package --no-interaction');

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);
});

function registerDemoUninstaller(ModuleUninstaller $uninstaller): void
{
    $binding = 'tests.module-lifecycle.uninstaller';

    app()->instance($binding, $uninstaller);
    app()->tag([$binding], 'module.uninstallers');
}

function testDemoUninstaller(): ModuleUninstaller
{
    return new class implements ModuleUninstaller
    {
        public bool $uninstalled = false;

        public function moduleName(): string
        {
            return 'demo-module';
        }

        public function canUninstall(Module $module): bool
        {
            return true;
        }

        public function previewUninstall(Module $module): array
        {
            return ['Drop demo-module tables'];
        }

        public function uninstall(Module $module): void
        {
            $this->uninstalled = true;
        }
    };
}
```

The sandbox helper lives at the bottom of the test file:

```php
function sandboxService(
    string $sandbox,
    array &$composerCalls,
    array &$gitCalls,
    bool $gitTracked,
): ModuleLifecycleService {
    return new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $sandbox,
        // Record every composer invocation; vendor reinstall re-creates the package.
        function (array $args) use (&$composerCalls, $sandbox): bool {
            $composerCalls[] = implode(' ', $args);

            if ($args[0] === 'require') {
                $package = $args[1];
                [$vendorName, $packageName] = explode('/', $package);

                File::makeDirectory("{$sandbox}/vendor/{$vendorName}/{$packageName}/database/migrations", 0755, true);
                File::put("{$sandbox}/vendor/{$vendorName}/{$packageName}/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                    'required' => false, 'protected' => false,
                ]));
                File::put(
                    "{$sandbox}/vendor/{$vendorName}/{$packageName}/database/migrations/2026_01_01_000001_create_demo_restore_table.php",
                    demoRestoreMigration(),
                );
            }

            return true;
        },
        // Record git invocations; simulate a restore by re-creating module files.
        function (array $args) use (&$gitCalls, $sandbox, $gitTracked): bool {
            $gitCalls[] = implode(' ', $args);

            if ($args[0] === 'restore') {
                File::makeDirectory("{$sandbox}/app-modules/demo-module/database/migrations", 0755, true);
                File::put("{$sandbox}/app-modules/demo-module/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                    'required' => false, 'protected' => false,
                ]));
                File::put(
                    "{$sandbox}/app-modules/demo-module/database/migrations/2026_01_01_000001_create_demo_restore_table.php",
                    demoRestoreMigration(),
                );

                return true;
            }

            return $gitTracked; // answers 'cat-file -e' existence checks
        },
    );
}

function demoRestoreMigration(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_restore', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_restore');
    }
};
PHP;
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=ModuleLifecycleServiceTest`
Expected: FAIL — the service still has the old soft-uninstall API.

- [ ] **Step 3: Implement the rewritten service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Manages the destructive module lifecycle: complete uninstall and restore.
 *
 * An uninstall drops the module's data (through its tagged uninstall
 * handler when one exists), deletes its files and Composer entries, and
 * keeps a minimal registry marker so the module can be restored later.
 * A restore brings the module back from its origin — the git repository
 * for first-party modules, or Composer for vendor packages — re-runs its
 * migrations, and re-seeds its permissions. Database data is intentionally
 * not restored.
 */
class ModuleLifecycleService
{
    /**
     * Create the lifecycle service.
     *
     * The base path and process runners are injectable so tests can run the
     * service against a hermetic sandbox instead of the real installation.
     *
     * @param  Closure(array<int, string>): bool|null  $composerRunner  test seam that performs Composer operations
     * @param  Closure(array<int, string>): bool|null  $gitRunner       test seam that performs git operations
     */
    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly ?string $basePath = null,
        private readonly ?Closure $composerRunner = null,
        private readonly ?Closure $gitRunner = null,
    ) {}

    // ─── Uninstall ──────────────────────────────────────────────────────

    /**
     * Build the exact phrase required to destructively uninstall a module.
     */
    public function confirmationPhrase(string $name): string
    {
        return 'UNINSTALL '.$name;
    }

    /**
     * Return a human-readable uninstall preview for a module.
     *
     * @return array<string, mixed>
     */
    public function previewUninstall(string $name): array
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            return $this->refusal('Invalid module name.');
        }

        // Local app-modules directories take precedence over vendor packages.
        $moduleDir = $this->moduleDir($name);
        $vendor = $moduleDir === null ? $this->vendorModuleInfo($name) : null;

        if ($moduleDir === null && $vendor === null) {
            return $this->refusal("Module [{$name}] is neither a local app-modules directory nor an installed vendor package.");
        }

        $manifest = $this->manifestFor($moduleDir ?? $vendor['dir']);

        if ($manifest === null) {
            return $this->refusal("Module [{$name}] has no readable module.json manifest.");
        }

        $registryRow = Module::where('name', $name)->first();

        // Both the on-disk manifest and the registry row carry protection
        // flags; either one refusing is enough to refuse removal.
        if (($manifest['protected'] ?? false) || ($manifest['required'] ?? false)
            || ($registryRow?->protected ?? false) || ($registryRow?->required ?? false)) {
            return $this->refusal('Required and protected modules cannot be uninstalled.');
        }

        $uninstallItems = [];
        $uninstallAvailable = false;

        // Only drop data when a handler exists; otherwise tables are left
        // alone and the operator is warned below.
        if ($registryRow !== null) {
            $uninstaller = $this->uninstallerFor($name);

            if ($uninstaller !== null && $uninstaller->canUninstall($registryRow)) {
                $uninstallAvailable = true;
                $uninstallItems = $uninstaller->previewUninstall($registryRow);
            }
        }

        $isVendor = $moduleDir === null;

        $dependents = $this->installedDependents($name);

        if ($dependents !== []) {
            return $this->refusal('Modules ['.implode(', ', $dependents).'] require this module; uninstall them first.');
        }

        $warnings = [];
        $this->appendCrossTestWarnings($name, $warnings);

        if (! $uninstallAvailable) {
            $warnings[] = "Module [{$name}] has no uninstall handler; its database tables (if any) will be left intact.";
        }

        return [
            'can_uninstall' => true,
            'reason' => null,
            'module_kind' => $isVendor ? 'vendor' : 'local',
            'module_dir' => $moduleDir,
            'vendor_package' => $isVendor ? $vendor['package'] : null,
            'composer_package' => $isVendor ? $vendor['package'] : "tallpbx/module-{$name}",
            'repository_entry' => ! $isVendor && $this->hasRepositoryEntry($name),
            'registry_row' => $registryRow !== null,
            'permission_count' => Permission::where('module', $name)->count(),
            'uninstall_items' => $uninstallItems,
            'uninstall_available' => $uninstallAvailable,
            'restore_hint' => "Restore later with: php artisan module:restore {$name} (database data is not restored)",
            'display_name' => $manifest['display_name'] ?? $name,
            'version' => $manifest['version'] ?? '0.0.0',
            'warnings' => $warnings,
        ];
    }

    /**
     * Permanently uninstall a module after an exact confirmation phrase match.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function uninstall(string $name, string $confirmation): array
    {
        $preview = $this->previewUninstall($name);

        if (! $preview['can_uninstall']) {
            throw ValidationException::withMessages(['module' => $preview['reason'] ?? 'This module cannot be uninstalled.']);
        }

        if ($confirmation !== $this->confirmationPhrase($name)) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation phrase did not match.']);
        }

        $uninstallRan = false;
        $permissionsDeleted = Permission::where('module', $name)->count();

        DB::transaction(function () use ($name, $preview, &$uninstallRan): void {
            // Drop module-owned data first when the module provides a handler.
            if ($preview['uninstall_available']) {
                $this->uninstallerFor($name)?->uninstall(Module::where('name', $name)->firstOrFail());
                $uninstallRan = true;
            }

            // Permission rows always belong to the uninstalled module.
            Permission::query()->where('module', $name)->delete();
        });

        $isVendor = $preview['module_kind'] === 'vendor';
        $moduleDirDeleted = false;

        if (! $isVendor) {
            // Only local modules own files in the installation tree. Vendor
            // packages belong to Composer, which deletes them itself.
            $moduleDirDeleted = $this->files->deleteDirectory((string) $preview['module_dir']);
        }

        $composerRemoved = $this->runComposer(['remove', $preview['composer_package'], '--no-interaction']);

        if (! $composerRemoved) {
            $preview['warnings'][] = "Composer could not remove [{$preview['composer_package']}]; run composer update manually to finish.";
        }

        $repositoryEntryRemoved = false;
        $requireEntryRemoved = false;

        if (! $isVendor) {
            // Composer remove handles the lockfile; strip the JSON entries
            // ourselves so the state is complete even if Composer failed.
            $repositoryEntryRemoved = $this->stripRepositoryEntry($name);
            $requireEntryRemoved = $this->stripRequireEntry($preview['composer_package']);
        }

        // Keep a minimal registry marker so restore knows what to bring back.
        $this->recordUninstallMarker($name, $preview);

        Artisan::call('optimize:clear');

        Log::notice('Module uninstalled.', ['module' => $name]);

        return [
            'module_kind' => $preview['module_kind'],
            'module_dir_deleted' => $moduleDirDeleted,
            'composer_package_removed' => $composerRemoved,
            'repository_entry_removed' => $repositoryEntryRemoved,
            'require_entry_removed' => $requireEntryRemoved,
            'permissions_deleted' => $permissionsDeleted,
            'uninstall_ran' => $uninstallRan,
            'restore_hint' => $preview['restore_hint'],
            'warnings' => $preview['warnings'],
        ];
    }

    // ─── Restore ────────────────────────────────────────────────────────

    /**
     * Return a human-readable restore plan for a previously uninstalled module.
     *
     * @return array<string, mixed>
     */
    public function previewRestore(string $name): array
    {
        $empty = [
            'can_restore' => false,
            'reason' => null,
            'source' => null,
            'module_dir' => '',
            'composer_package' => '',
            'registry_row' => false,
            'items' => [],
            'data_notice' => '',
        ];

        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            return array_merge($empty, ['reason' => 'Invalid module name.']);
        }

        $registryRow = Module::where('name', $name)->first();
        $isLocal = $this->isLocalModule($name);

        if (! $isLocal && ($registryRow?->composer_package === null || $registryRow?->composer_package === '')) {
            return array_merge($empty, [
                'reason' => "Module [{$name}] is not tracked in this repository and has no recorded Composer package to restore from.",
                'registry_row' => $registryRow !== null,
            ]);
        }

        return [
            'can_restore' => true,
            'reason' => null,
            'source' => $isLocal ? 'git' : 'composer',
            'module_dir' => $this->basePath()."/app-modules/{$name}",
            'composer_package' => $isLocal ? "tallpbx/module-{$name}" : $registryRow->composer_package,
            'registry_row' => $registryRow !== null,
            'items' => $isLocal
                ? [
                    "Restore the module directory (code, migrations, factories, and tests) from git: app-modules/{$name}",
                    'Re-add the Composer path-repository and require entries',
                ]
                : [
                    "Reinstall the Composer package: {$registryRow->composer_package}",
                ],
            'data_notice' => 'The module returns with empty tables; uninstalled data is not restored.',
        ];
    }

    /**
     * Restore a previously uninstalled module from its origin.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function restore(string $name): array
    {
        $preview = $this->previewRestore($name);

        if (! $preview['can_restore']) {
            throw ValidationException::withMessages(['module' => $preview['reason'] ?? 'This module cannot be restored.']);
        }

        $restored = false;

        if ($preview['source'] === 'git') {
            // The module directory contains its code, migrations, factories,
            // views, AND tests — one restore brings everything back.
            $restored = $this->runGit(['restore', '--source=HEAD', '--', "app-modules/{$name}"]);

            if (! $restored) {
                throw ValidationException::withMessages([
                    'module' => "Could not restore module files from git. Check that [app-modules/{$name}] is committed and the installation is a git checkout.",
                ]);
            }

            $this->addRepositoryEntry($name);
            $this->addRequireEntry($preview['composer_package']);
        } else {
            $restored = $this->runComposer(['require', $preview['composer_package'], '--no-interaction']);

            if (! $restored) {
                throw ValidationException::withMessages([
                    'module' => "Composer could not reinstall [{$preview['composer_package']}].",
                ]);
            }
        }

        // Refresh the registry row from the restored manifest and re-enable it.
        $this->refreshRegistryRow($name);

        // Recreate the module's tables (empty) by re-running its migrations.
        $this->runModuleMigrations($name);

        // Re-seed permission rows for every installed module (idempotent).
        Artisan::call('db:seed', ['--class' => \Database\Seeders\AdminSeeder::class, '--force' => true]);

        Artisan::call('optimize:clear');

        Log::notice('Module restored.', ['module' => $name]);

        return [
            'source' => $preview['source'],
            'files_restored' => $restored,
            'data_notice' => $preview['data_notice'],
        ];
    }

    // ─── Private helpers ────────────────────────────────────────────────

    /**
     * Build a preview that refuses uninstall with a human-readable reason.
     *
     * @return array<string, mixed>
     */
    private function refusal(string $reason): array
    {
        return [
            'can_uninstall' => false,
            'reason' => $reason,
            'module_kind' => null,
            'module_dir' => null,
            'vendor_package' => null,
            'composer_package' => '',
            'repository_entry' => false,
            'registry_row' => false,
            'permission_count' => 0,
            'uninstall_items' => [],
            'uninstall_available' => false,
            'restore_hint' => '',
            'display_name' => '',
            'version' => '',
            'warnings' => [],
        ];
    }

    /**
     * Resolve the working root for paths, falling back to the real project.
     */
    private function basePath(): string
    {
        return $this->basePath ?? base_path();
    }

    /**
     * Resolve the local app-modules directory for a module name.
     */
    private function moduleDir(string $name): ?string
    {
        $dir = $this->basePath()."/app-modules/{$name}";

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Resolve a vendor-installed package that provides the given module name.
     *
     * @return array{package: string, dir: string}|null
     */
    private function vendorModuleInfo(string $name): ?array
    {
        foreach (glob($this->basePath().'/vendor/*/*/module.json') ?: [] as $path) {
            $manifest = $this->manifestFor(dirname($path));

            if ($manifest === null || ($manifest['name'] ?? null) !== $name) {
                continue;
            }

            // Composer lays packages out as vendor/{vendor-name}/{package-name}.
            $segments = explode('/', ltrim(str_replace($this->basePath(), '', dirname($path)), '/'));

            if (count($segments) !== 3) {
                continue;
            }

            return ['package' => $segments[1].'/'.$segments[2], 'dir' => dirname($path)];
        }

        return null;
    }

    /**
     * Read and validate a module's manifest file.
     *
     * @return array<string, mixed>|null
     */
    private function manifestFor(string $dir): ?array
    {
        $contents = @file_get_contents("{$dir}/module.json");

        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Find a tagged uninstaller for the given module name.
     */
    private function uninstallerFor(string $module): ?ModuleUninstaller
    {
        foreach ($this->app->tagged('module.uninstallers') as $uninstaller) {
            if ($uninstaller instanceof ModuleUninstaller && $uninstaller->moduleName() === $module) {
                return $uninstaller;
            }
        }

        return null;
    }

    /**
     * Whether the module is a first-party module tracked in this git checkout.
     */
    private function isLocalModule(string $name): bool
    {
        return $this->runGit(['cat-file', '-e', "HEAD:app-modules/{$name}/module.json"]);
    }

    /**
     * Run a Composer command, or the injected test seam.
     *
     * @param  array<int, string>  $arguments
     */
    private function runComposer(array $arguments): bool
    {
        if ($this->composerRunner !== null) {
            return ($this->composerRunner)($arguments);
        }

        $process = new Process(array_merge(['composer'], $arguments), $this->basePath());
        $process->setTimeout(600);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Run a git command, or the injected test seam.
     *
     * @param  array<int, string>  $arguments
     */
    private function runGit(array $arguments): bool
    {
        if ($this->gitRunner !== null) {
            return ($this->gitRunner)($arguments);
        }

        $process = new Process(array_merge(['git'], $arguments), $this->basePath());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Read the root composer.json into an associative array.
     *
     * @return array<string, mixed>
     */
    private function readComposer(): array
    {
        $contents = @file_get_contents($this->basePath().'/composer.json');

        if ($contents === false) {
            return [];
        }

        $composer = json_decode($contents, true);

        return is_array($composer) ? $composer : [];
    }

    /**
     * Persist the root composer.json after a change.
     *
     * @param  array<string, mixed>  $composer
     */
    private function writeComposer(array $composer): void
    {
        $this->files->put(
            $this->basePath().'/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * Whether the root composer.json declares this module as a path repository.
     */
    private function hasRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        return collect($composer['repositories'] ?? [])
            ->contains(fn (array $repo): bool => ($repo['url'] ?? '') === "app-modules/{$name}");
    }

    /**
     * Remove the module's path-repository entry from the root composer.json.
     */
    private function stripRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        $composer['repositories'] = collect($composer['repositories'] ?? [])
            ->reject(fn (array $repo): bool => ($repo['url'] ?? '') === "app-modules/{$name}")
            ->values()
            ->all();

        $this->writeComposer($composer);

        return true;
    }

    /**
     * Remove the module's require entry from the root composer.json.
     */
    private function stripRequireEntry(string $package): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        if (! isset($composer['require'][$package])) {
            return true;
        }

        unset($composer['require'][$package]);
        $this->writeComposer($composer);

        return true;
    }

    /**
     * Re-add the module's path-repository entry to the root composer.json.
     */
    private function addRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        $repositories = $composer['repositories'] ?? [];

        foreach ($repositories as $repo) {
            if (is_array($repo) && ($repo['url'] ?? '') === "app-modules/{$name}") {
                return true; // already present
            }
        }

        $repositories[] = ['type' => 'path', 'url' => "app-modules/{$name}"];
        $composer['repositories'] = $repositories;

        $this->writeComposer($composer);

        return true;
    }

    /**
     * Re-add the module's Composer require entry to the root composer.json.
     */
    private function addRequireEntry(string $package): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        if (isset($composer['require'][$package])) {
            return true; // already present
        }

        $composer['require'][$package] = '*';
        $this->writeComposer($composer);

        return true;
    }

    /**
     * Keep or create a registry row marking the module as uninstalled so the
     * restore command can determine where the module comes from.
     *
     * @param  array<string, mixed>  $preview
     */
    private function recordUninstallMarker(string $name, array $preview): void
    {
        $attributes = [
            'enabled' => false,
            'status' => Module::StatusUninstalled,
            'composer_package' => $preview['composer_package'],
        ];

        $registryRow = Module::where('name', $name)->first();

        if ($registryRow !== null) {
            $registryRow->update($attributes);

            return;
        }

        // A module that was never synced still needs a marker so restore can
        // recover its origin (especially vendor package names).
        Module::create(array_merge([
            'name' => $name,
            'display_name' => $preview['display_name'],
            'version' => $preview['version'],
        ], $attributes));
    }

    /**
     * Sync the registry row from the restored on-disk manifest and re-enable it.
     */
    private function refreshRegistryRow(string $name): void
    {
        $dir = $this->moduleDir($name);

        if ($dir === null) {
            $dir = $this->vendorModuleInfo($name)['dir'] ?? null;
        }

        $manifest = $dir !== null ? $this->manifestFor($dir) : null;

        Module::updateOrCreate(
            ['name' => $name],
            [
                'display_name' => $manifest['display_name'] ?? $name,
                'version' => $manifest['version'] ?? '0.0.0',
                'enabled' => true,
                'status' => Module::StatusEnabled,
                'protected' => $manifest['protected'] ?? false,
                'required' => $manifest['required'] ?? false,
                'priority' => $manifest['priority'] ?? 0,
            ],
        );
    }

    /**
     * Resolve the installed modules that declare a requirement on this module.
     *
     * @return array<int, string>
     */
    private function installedDependents(string $name): array
    {
        $dependents = [];

        foreach (array_merge(
            glob($this->basePath().'/app-modules/*/module.json') ?: [],
            glob($this->basePath().'/vendor/*/*/module.json') ?: [],
        ) as $path) {
            $manifest = $this->manifestFor(dirname($path));

            if ($manifest === null || ($manifest['name'] ?? null) === $name) {
                continue;
            }

            $deps = $manifest['requirements']['modules'] ?? [];

            if (in_array($name, $deps, true)) {
                $dependents[] = $manifest['name'];
            }
        }

        return $dependents;
    }

    /**
     * Warn about centralized tests outside the removed module's own folder
     * that still reference its classes.
     *
     * @param  array<int, string>  $warnings
     */
    private function appendCrossTestWarnings(string $name, array &$warnings): void
    {
        $studly = Str::studly($name);
        $needle = "Modules\\{$studly}\\";
        $ownDirs = [
            $this->basePath()."/tests/Feature/Modules/{$studly}",
            $this->basePath()."/app-modules/{$name}",
        ];
        $hits = [];

        // Cross-module references can live in the central tests tree OR inside
        // another module's own tests — scan both.
        $scanRoots = array_filter([
            $this->basePath().'/tests',
            $this->basePath().'/app-modules',
        ], 'is_dir');

        foreach ($this->files->allFiles($scanRoots) as $file) {
            $path = $file->getPathname();

            foreach ($ownDirs as $ownDir) {
                if (str_starts_with($path, $ownDir)) {
                    continue 2;
                }
            }

            if (str_contains((string) $file->getContents(), $needle)) {
                $hits[] = str_replace($this->basePath().'/', '', $path);
            }
        }

        if ($hits !== []) {
            $warnings[] = 'Cross-module tests reference this module and will be skipped until it is restored: '.implode(', ', array_slice($hits, 0, 10)).(count($hits) > 10 ? ' ('.count($hits).' total)' : '');
        }
    }

    /**
     * Locate the module migration directory from local or vendor modules.
     */
    private function migrationPathFor(string $name): ?string
    {
        $modulePath = $this->moduleDir($name);

        if ($modulePath === null) {
            $modulePath = $this->vendorModuleInfo($name)['dir'] ?? null;
        }

        if ($modulePath === null) {
            return null;
        }

        $migrationPath = $modulePath.'/database/migrations';

        return is_dir($migrationPath) ? $migrationPath : null;
    }

    /**
     * Run migrations for a restored module so its tables come back empty.
     */
    private function runModuleMigrations(string $name): void
    {
        $path = $this->migrationPathFor($name);

        if ($path === null) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter=ModuleLifecycleServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: rewrite module lifecycle as complete uninstall and restore" -m "Replace the soft uninstall/reinstall model with a two-state lifecycle: uninstall deletes files, Composer entries, central tests and data while keeping a registry marker; restore brings the module back from git or Composer with empty tables. Fully tested against a hermetic sandbox."
```

---

## Task 5: Move Module Tests Into Their Modules

**Files:**
- Create: `scripts/migrate-module-tests.php` (temporary, deleted after verification)
- Modify: `phpunit.xml` (Feature suite glob)
- Modify: `tests/Pest.php` (bind TestCase to app-modules tests)
- Modify: `tests/Feature/Commands/NativeModuleCommandTest.php` (scaffold assertion)
- Modify: `app/Console/Commands/MakeModuleCommand.php` (scaffold `tests/`)
- Move: `tests/Feature/Modules/{Studly}/*` → `app-modules/{kebab}/tests/*` (44 folders)

**Interfaces:**
- Produces: module tests discovered via `<directory suffix="Test.php">app-modules/*/tests</directory>` in the Feature suite; `pest()->extend(TestCase::class)...->in('Feature', 'app-modules')` binds the Laravel test case and the skip guard to them.

- [ ] **Step 1: Write the one-shot move script**

```php
<?php

declare(strict_types=1);

// One-shot migration: move each module's central test folder into the module
// itself, so app-modules are fully self-contained. Deleted after verification.

$base = dirname(__DIR__);
$errors = [];
$moved = [];

foreach (glob($base.'/tests/Feature/Modules/*', GLOB_ONLYDIR) as $dir) {
    $studly = basename($dir);
    $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    $target = $base.'/app-modules/'.$kebab.'/tests';

    if (! is_dir($base.'/app-modules/'.$kebab)) {
        $errors[] = "No app-modules home for {$studly} (kebab: {$kebab})";
        continue;
    }

    if (is_dir($target)) {
        $errors[] = "Target already exists: {$target}";
        continue;
    }

    rename($dir, $target);
    $moved[] = $studly;
}

// Remove the now-empty parent folder.
if ($errors === []) {
    rmdir($base.'/tests/Feature/Modules');
}

if ($errors !== []) {
    fwrite(STDERR, "Migration aborted with errors:\n".implode("\n", $errors)."\n");
    exit(1);
}

echo 'Moved '.count($moved).' module test folders into their modules.'."\n";
```

- [ ] **Step 2: Run the move script**

Run: `php scripts/migrate-module-tests.php`
Expected: `Moved 44 module test folders into their modules.`

- [ ] **Step 3: Wire suite discovery (TDD: tests disappear first, then return)**

Before wiring, run: `php artisan test --compact --parallel --filter=ModuleFactoryIsolationTest` — still passes (central file). Then run: `php artisan test --compact --parallel --filter="extensions"` — the module's tests no longer run (not discovered). That is the red state for this step.

Add the glob to `phpunit.xml`'s Feature testsuite:

```xml
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
            <directory suffix="Test.php">app-modules/*/tests</directory>
        </testsuite>
```

In `tests/Pest.php`, extend the Feature bind so module tests get the Laravel test case and the skip guard:

```php
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->skipWhenReferencedModuleUninstalled();
    })
    ->in('Feature', 'app-modules');
```

Run again: `php artisan test --compact --parallel --filter="extensions"`
Expected: the module's tests run again and pass from their new home. Verify paratest and Pest honor the glob at this step; if a framework quirk appears, fall back to explicit module directories in `phpunit.xml`.

- [ ] **Step 4: Scaffold `tests/` in make:module (TDD)**

In `tests/Feature/Commands/NativeModuleCommandTest.php` scaffold test add `->and($modulePath.'/tests')->toBeDirectory();` — run: FAIL.

Then in `MakeModuleCommand::createDirectories()` add `mkdir("{$moduleDir}/tests", 0755, true);` and the bullet `'tests/ — Pest tests discovered by the host suite'`. Re-run: PASS.

- [ ] **Step 5: Verify no stragglers and delete the script**

Run: `ls tests/Feature/Modules 2>/dev/null || echo "GONE"` — Expected: `GONE`. Delete `scripts/migrate-module-tests.php`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: move module tests into their owning modules" -m "Relocate each module's central test folder into app-modules/{name}/tests and discover them through a phpunit.xml glob, making modules fully self-contained packages."
```

---

## Task 6: Auto-Registered Module Config with Centralized Fallback (TDD)

**Files:**
- Modify: `app/Support/ModuleServiceProvider.php` (config auto-registration)
- Create: `tests/Feature/Support/ModuleConfigMergeTest.php`

**Interfaces:**
- Produces: the provider base merges `app-modules/{name}/config/{name}.php` under the `{name}` config key when the file exists. Centralized app-level settings under the same key keep working and are overridden only where the module file defines values.

- [ ] **Step 1: Write the failing tests**

```php
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
    public function __construct(Application $app, private readonly string $sandboxPath)
    {
        parent::__construct($app);
    }

    protected function moduleName(): string
    {
        return 'demo-config';
    }

    protected function moduleNamespace(): string
    {
        return 'Modules\\DemoConfig';
    }

    protected function modulePath(): string
    {
        return $this->sandboxPath;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=ModuleConfigMergeTest`
Expected: FAIL — no config merging happens, so `overridden` stays `central-value`.

- [ ] **Step 3: Implement the base-provider hook**

In `app/Support/ModuleServiceProvider.php`, call the new registration in `boot()` right after `registerMigrations()`:

```php
        $this->registerViews();
        $this->registerTranslations();
        $this->registerMigrations();
        $this->registerConfig();
```

And add the two methods (next to `hasTranslations()`):

```php
    /**
     * Whether this module ships its own configuration file.
     * Checks for the existence of a config/{moduleName}.php file.
     */
    protected function hasConfig(): bool
    {
        return is_file($this->modulePath().'/config/'.$this->moduleName().'.php');
    }

    /**
     * Register the module's configuration file when one exists.
     *
     * Module settings are merged over the centralized app-level defaults
     * under the module's own key, so existing config/<module>.php files
     * keep working and are only overridden where the module defines values.
     */
    private function registerConfig(): void
    {
        if ($this->hasConfig()) {
            $this->mergeConfigFrom(
                $this->modulePath().'/config/'.$this->moduleName().'.php',
                $this->moduleName(),
            );
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter=ModuleConfigMergeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: auto-register module config with centralized fallback" -m "Merge app-modules/{name}/config/{name}.php under the module's config key when it exists; centralized app-level settings keep working and are overridden only where the module defines values."
```

---

## Task 7: Core Module Protection & Declared Dependencies

> **Rationale (see the External References section):** the protected core set mirrors FreePBX's unremovable `core`/`framework` (https://github.com/freepbx), and the non-disable-able rule follows FusionPBX's stance that spine features are hidden via permissions, never deleted (https://github.com/fusionpbx/fusionpbx). Dependency-guarded removal is FreePBX's Module Admin behavior: refuse while dependents exist.

**Files:**
- Modify: 10 core module manifests — `"protected": true` on `extensions`, `devices`, `sip-accounts`, `destinations`, `dialplans`, `dialplan-tools`, `gateways`, `sip-profiles`, `inbound-routes`, `outbound-routes`
- Modify: `app-modules/admin/src/Livewire/ModulesList.php` (disable toggle refuses protected)
- Modify: `tests/Feature/Livewire/ModulesListTest.php` (protected disable test)
- Create: `tests/Feature/Modules/CoreModulesProtectedTest.php`
- Create: `tests/Feature/Modules/ModuleBoundaryTest.php`
- Create: `scripts/migrate-module-requirements.php` (temporary, deleted after verification)
- Modify: all 57 module manifests (`requirements.modules` populated by the script)

**Interfaces:**
- Produces: a protected core set (never uninstallable, never disable-able); authoritative `requirements.modules` declarations; an acyclic declared dependency graph. Uninstall refusal for installed dependents is already implemented in Task 4 and becomes effective once the declarations are populated here.

- [ ] **Step 1: Write the failing core-protection test**

`tests/Feature/Modules/CoreModulesProtectedTest.php`:

```php
<?php

declare(strict_types=1);

it('marks the core PBX modules as protected or required', function (): void {
    $core = [
        'admin', 'auth', 'tenant',
        'extensions', 'devices', 'sip-accounts', 'destinations',
        'dialplans', 'dialplan-tools', 'gateways', 'sip-profiles',
        'inbound-routes', 'outbound-routes',
    ];

    foreach ($core as $name) {
        $manifest = json_decode((string) file_get_contents(base_path("app-modules/{$name}/module.json")), true);

        expect(($manifest['protected'] ?? false) || ($manifest['required'] ?? false))
            ->toBeTrue("Module [{$name}] must be protected or required");
    }
});
```

Run: `php artisan test --compact --parallel --filter=CoreModulesProtectedTest`
Expected: FAIL — the ten telephony modules are neither protected nor required.

- [ ] **Step 2: Mark the core modules protected**

Add `"protected": true` to the ten manifests listed above. Re-run the test: PASS. (Uninstall refusal for protected modules is already covered by Task 4's tests; `module:sync` copies the flag into the registry row.)

- [ ] **Step 3: Make the panel refuse to disable protected modules (TDD)**

Add to `tests/Feature/Livewire/ModulesListTest.php`:

```php
it('prevents disabling protected modules', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true, 'protected' => true]);

    Artisan::shouldReceive('call')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
    ]);
});
```

Run: FAIL (protected modules can currently be disabled). Then in `ModulesList::toggleEnabled()` change the guard:

```php
        // Required and protected modules cannot be disabled
        if (($module->required || $module->protected) && $module->enabled) {
            return;
        }
```

Re-run: PASS.

- [ ] **Step 4: Write the failing boundary tests**

`tests/Feature/Modules/ModuleBoundaryTest.php`:

```php
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
        $declared = $manifest['requirements']['modules'] ?? [];
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
        $graph[$manifest['name']] = $manifest['requirements']['modules'] ?? [];
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
```

Run: `php artisan test --compact --parallel --filter=ModuleBoundaryTest`
Expected: FAIL — declarations are empty and cycles are possible.

- [ ] **Step 5: Populate the declarations and resolve any cycles**

Create and run `scripts/migrate-module-requirements.php`:

```php
<?php

declare(strict_types=1);

// One-shot migration: populate each module's requirements.modules from its
// actual cross-module class references. Deleted after verification.

$base = dirname(__DIR__);

foreach (glob($base.'/app-modules/*/module.json') as $manifestPath) {
    $moduleDir = dirname($manifestPath);
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $ownStudly = str_replace(' ', '', ucwords(str_replace('-', ' ', $manifest['name'])));

    $referenced = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir.'/src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/Modules\\\\([A-Za-z0-9_]+)\\\\/', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] ?? [] as $studly) {
            if ($studly !== $ownStudly) {
                $referenced[strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $studly))] = true;
            }
        }
    }

    $manifest['requirements']['modules'] = array_keys($referenced);
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

echo "Populated requirements.modules for all local modules.\n";
```

Then re-run `--filter=ModuleBoundaryTest`. If the acyclicity test reports cycles, resolve them in this order of preference: (1) move the referenced code into the module that owns the dependency so the edge disappears; (2) flip the declared direction if the real ownership is reversed; (3) if neither applies, keep the edge but document in the manifest why a shared cycle is acceptable — the failing cycle list names the exact modules. Expected after resolution: PASS.

- [ ] **Step 6: Delete the script and commit**

Delete `scripts/migrate-module-requirements.php`, then:

```bash
git add -A
git commit -m "feat: protect core modules and enforce declared module dependencies" -m "Mark the ten core PBX modules protected (no uninstall, no panel disable), populate requirements.modules from actual cross-module references, and add boundary tests enforcing declared acyclic dependencies so uninstall can refuse while dependents exist."
```

---

## Task 8: Module-Aware Test Guard — Skipped, Never Failed (TDD)

**Files:**
- Create: `tests/Traits/ModuleAwareTestGuard.php`
- Create: `tests/Feature/Testing/ModuleAwareTestGuardTest.php`
- Modify: `tests/TestCase.php`
- Modify: `tests/DuskTestCase.php`
- Modify: `tests/Pest.php`
- Modify: `tests/Browser/PanelSmokeTest.php`

**Interfaces:**
- Produces `Tests\Traits\ModuleAwareTestGuard` with protected methods:
  - `skipWhenModuleUninstalled(string $module): void`
  - `skipWhenReferencedModuleUninstalled(): void`
  - `moduleIsInstalled(string $name): bool` (kebab name; checks `app-modules/*/module.json` and `vendor/*/*/module.json`, cached per process)
  - `referencedModuleNames(): array`
  - `extractModuleNames(string $contents): array`
- Consumes: nothing. Works after Tasks 3–4 land because it only inspects the filesystem.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Testing/ModuleAwareTestGuardTest.php`:

```php
<?php

declare(strict_types=1);

it('maps module references to kebab-case module names', function (): void {
    $names = $this->extractModuleNames(
        'use Modules\\Extensions\\Models\\Extension; new Modules\\SipAccounts\\Models\\SipAccount();',
    );

    expect($names)->toBe(['extensions', 'sip-accounts']);
});

it('knows which modules are installed in the checkout', function (): void {
    expect($this->moduleIsInstalled('extensions'))->toBeTrue()
        ->and($this->moduleIsInstalled('definitely-not-a-module'))->toBeFalse();
});

it('skips the current test when its module is not installed', function (): void {
    $this->skipWhenModuleUninstalled('definitely-not-a-module');

    $this->fail('The test should have been skipped.');
});
```

The third test demonstrates the behavior by being reported as SKIPPED.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=ModuleAwareTestGuardTest`
Expected: FAIL — the trait does not exist.

- [ ] **Step 3: Implement the trait**

```php
<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Str;

/**
 * Marks tests as skipped when they depend on a module that is not installed.
 *
 * Uninstalling a module removes its source files and deletes its central
 * test folder. Any remaining test that references the module would fail
 * with class-not-found errors; this trait turns those into skipped tests
 * so the suite stays green while the module is gone.
 */
trait ModuleAwareTestGuard
{
    /**
     * Skip the current test when the given kebab-case module is not installed.
     */
    protected function skipWhenModuleUninstalled(string $module): void
    {
        if (! $this->moduleIsInstalled($module)) {
            $this->markTestSkipped("Module [{$module}] is not installed.");
        }
    }

    /**
     * Skip the current test when any module referenced by its file is gone.
     */
    protected function skipWhenReferencedModuleUninstalled(): void
    {
        foreach ($this->referencedModuleNames() as $module) {
            $this->skipWhenModuleUninstalled($module);
        }
    }

    /**
     * Extract kebab-case module names from the calling test file's source.
     *
     * @return array<int, string>
     */
    protected function referencedModuleNames(): array
    {
        static $cache = [];

        $file = $this->currentTestFile();

        if (! isset($cache[$file])) {
            $contents = @file_get_contents($file);
            $cache[$file] = $contents === false ? [] : $this->extractModuleNames($contents);
        }

        return $cache[$file];
    }

    /**
     * Find the test file that invoked the guard via the backtrace.
     */
    private function currentTestFile(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? '';

            if (str_contains($file, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)
                && ! str_contains($file, 'ModuleAwareTestGuard.php')) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Extract kebab-case module names from test source text.
     *
     * @return array<int, string>
     */
    protected function extractModuleNames(string $contents): array
    {
        preg_match_all('/Modules\\\\([A-Za-z0-9_]+)\\\\/', $contents, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $studly): string => Str::kebab($studly))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a module is installed in this checkout (local or vendor).
     */
    protected function moduleIsInstalled(string $name): bool
    {
        static $installed = null;

        if ($installed === null) {
            // Scan once per process; each test process runs on one checkout.
            $installed = collect(array_merge(
                glob(base_path('app-modules/*/module.json')) ?: [],
                glob(base_path('vendor/*/*/module.json')) ?: [],
            ))->map(function (string $path): ?string {
                $manifest = json_decode((string) @file_get_contents($path), true);

                return is_array($manifest) ? ($manifest['name'] ?? null) : null;
            })->filter()->flip();
        }

        return $installed->has($name);
    }
}
```

- [ ] **Step 4: Wire the trait into the test cases**

In `tests/TestCase.php` add `use Tests\Traits\ModuleAwareTestGuard;` inside the class. Do the same in `tests/DuskTestCase.php`.

- [ ] **Step 5: Wire the global hook for the Feature suite**

In `tests/Pest.php`, extend the existing Feature declaration:

```php
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->skipWhenReferencedModuleUninstalled();
    })
    ->in('Feature');
```

(Only Feature gets the automatic file-level hook; Unit tests referencing modules are rare and get explicit `$this->skipWhenModuleUninstalled(...)` annotations where Step 7's grep finds them.)

- [ ] **Step 6: Annotate module-page Dusk smoke tests**

In `tests/Browser/PanelSmokeTest.php`, add `$this->skipWhenModuleUninstalled('<kebab-module>');` as the first line of every test that visits a module's panel page (e.g. the extensions page test gets `'extensions'`). Browser tests need per-test annotations because one file covers many modules — file-level detection would skip everything when any one module is gone.

- [ ] **Step 7: Find URL-only stragglers**

Run:
```bash
grep -rln "Modules\\\\" tests/Unit --include="*.php"
grep -rn "get('/panel/\|visit('/panel/" tests --include="*.php" | grep -v "Modules"
```
For any Unit test or URL-only Feature/Browser test that depends on a removable module without a `Modules\` import, add an explicit `$this->skipWhenModuleUninstalled('<module>');` annotation.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter=ModuleAwareTestGuardTest`
Expected: PASS (one test reported as skipped).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "test: skip tests whose referenced modules are uninstalled" -m "Add a module-aware test guard trait wired into the Feature suite and Dusk smoke tests, so removing a module skips dependent tests instead of failing them."
```

---

## Task 9: module:uninstall and module:restore Commands (TDD)

**Files:**
- Create: `tests/Feature/Commands/ModuleUninstallCommandTest.php`
- Create: `tests/Feature/Commands/ModuleRestoreCommandTest.php`
- Create: `app/Console/Commands/ModuleUninstallCommand.php`
- Create: `app/Console/Commands/ModuleRestoreCommand.php`
- Modify: `tests/Feature/Commands/NativeModuleCommandTest.php` (command name list)

**Interfaces:**
- Consumes: `ModuleLifecycleService` from Task 4.
- Produces: Artisan commands `module:uninstall {name} {--confirm=}` and `module:restore {name}`.

- [ ] **Step 1: Write the failing command tests**

In `tests/Feature/Commands/NativeModuleCommandTest.php`, extend the command name assertions:

```php
        ->and($commands)->toContain('module:uninstall')
        ->and($commands)->toContain('module:restore')
```

`tests/Feature/Commands/ModuleUninstallCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-uninstall-cmd-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));
    File::put($this->sandbox.'/composer.json', json_encode(['repositories' => [], 'require' => []]));

    $this->composerCalls = [];

    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        fn (array $args): bool => (bool) ($this->composerCalls[] = implode(' ', $args)),
        fn (array $args): bool => false,
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('uninstalls a module with the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')
        ->expectsOutputToContain('Uninstalled module [demo-module]')
        ->expectsOutputToContain('php artisan module:restore demo-module')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeFalse();
});

it('refuses without the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="nope"')->assertFailed();

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('refuses unknown modules', function (): void {
    $this->artisan('module:uninstall ghost-module --confirm="UNINSTALL ghost-module"')->assertFailed();
});
```

`tests/Feature/Commands/ModuleRestoreCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-restore-cmd-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));
    File::put($this->sandbox.'/composer.json', json_encode(['repositories' => [], 'require' => []]));

    // The git seam re-creates the module when restore asks git to restore it.
    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        fn (array $args): bool => true,
        function (array $args) use ($sandbox): bool {
            if ($args[0] === 'restore') {
                File::makeDirectory("{$sandbox}/app-modules/demo-module", 0755, true);
                File::put("{$sandbox}/app-modules/demo-module/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                ]));

                return true;
            }

            return true; // git-tracked
        },
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('restores a previously uninstalled module', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')
        ->assertSuccessful();

    $this->artisan('module:restore demo-module')
        ->expectsOutputToContain('Restored module [demo-module]')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=ModuleUninstallCommandTest`
Expected: FAIL — `module:uninstall` is not a registered command.

- [ ] **Step 3: Implement the commands**

`app/Console/Commands/ModuleUninstallCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ModuleLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Permanently uninstall a module from the installation.
 *
 * Deletes the module's files, Composer entries, central test folder,
 * permissions, and data (through its uninstall handler when one exists),
 * keeping a registry marker so module:restore can bring it back.
 */
class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall
        {name : Module name in kebab-case (e.g., "call-broadcast")}
        {--confirm= : Exact confirmation phrase (skips the interactive prompt)}';

    protected $description = 'Permanently uninstall a module (restorable via module:restore)';

    /**
     * Execute the console command.
     *
     * Prints a removal preview, requires the exact confirmation phrase,
     * then hands the destructive work to ModuleLifecycleService.
     */
    public function handle(ModuleLifecycleService $lifecycle): int
    {
        $name = (string) $this->argument('name');
        $preview = $lifecycle->previewUninstall($name);

        if (! $preview['can_uninstall']) {
            $this->components->error($preview['reason'] ?? 'This module cannot be uninstalled.');

            return self::FAILURE;
        }

        $this->printPreview($preview);

        $confirmation = $this->resolveConfirmation($lifecycle, $name);

        if ($confirmation === null) {
            $this->components->error('Uninstall cancelled.');

            return self::FAILURE;
        }

        try {
            $report = $lifecycle->uninstall($name, $confirmation);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'The module could not be uninstalled.');

            return self::FAILURE;
        }

        $this->components->info("Uninstalled module [{$name}].");
        $this->components->info($report['restore_hint']);
        $this->components->note('The module files are now deleted in the working tree, which the Git updater treats as uncommitted changes. Use module:restore to undo, or commit/stash the deletions before updating.');

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * Print the human-readable preview of everything that will be deleted.
     *
     * @param  array<string, mixed>  $preview
     */
    private function printPreview(array $preview): void
    {
        $isVendor = $preview['module_kind'] === 'vendor';

        $this->components->warn('This will permanently delete:');
        $this->components->bulletList([
            $isVendor
                ? "Vendor package: {$preview['vendor_package']} (removed via Composer)"
                : "Module directory: {$preview['module_dir']}",
            ! $isVendor
                ? 'Module tests (inside the module directory): included in the deletion'
                : 'Central tests: none',
            ! $isVendor && $preview['repository_entry']
                ? 'Composer path-repository entry'
                : 'Composer path-repository entry: none',
            "Composer package: {$preview['composer_package']}",
            $preview['registry_row'] ? 'Module registry row (kept as a restore marker)' : 'Module registry row: none',
            "Permissions: {$preview['permission_count']}",
        ]);

        foreach ($preview['uninstall_items'] as $item) {
            $this->line('  - '.$item);
        }

        foreach ($preview['warnings'] as $warning) {
            $this->components->warn($warning);
        }
    }

    /**
     * Get the confirmation phrase from the option, or ask interactively.
     */
    private function resolveConfirmation(ModuleLifecycleService $lifecycle, string $name): ?string
    {
        $phrase = $lifecycle->confirmationPhrase($name);
        $provided = $this->option('confirm');

        if (is_string($provided) && $provided !== '') {
            return $provided;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error("Run with --confirm=\"{$phrase}\" to proceed non-interactively.");

            return null;
        }

        $answer = $this->ask("Type \"{$phrase}\" to confirm permanent uninstall");

        return is_string($answer) ? $answer : null;
    }
}
```

`app/Console/Commands/ModuleRestoreCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ModuleLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Restore a previously uninstalled module from its origin.
 *
 * First-party modules return from the git repository (including their
 * central tests); vendor modules are reinstalled through Composer.
 * Migrations re-run into empty tables and permissions are re-seeded.
 * Database data is not restored.
 */
class ModuleRestoreCommand extends Command
{
    protected $signature = 'module:restore
        {name : Module name in kebab-case (e.g., "call-broadcast")}';

    protected $description = 'Restore a previously uninstalled module from git or Composer';

    /**
     * Execute the console command.
     *
     * Prints the restore plan and hands the work to ModuleLifecycleService.
     */
    public function handle(ModuleLifecycleService $lifecycle): int
    {
        $name = (string) $this->argument('name');
        $preview = $lifecycle->previewRestore($name);

        if (! $preview['can_restore']) {
            $this->components->error($preview['reason'] ?? 'This module cannot be restored.');

            return self::FAILURE;
        }

        $this->components->info('Restore plan:');

        foreach ($preview['items'] as $item) {
            $this->line('  - '.$item);
        }

        $this->components->warn($preview['data_notice']);

        try {
            $report = $lifecycle->restore($name);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'The module could not be restored.');

            return self::FAILURE;
        }

        $this->components->info("Restored module [{$name}] from {$report['source']}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter="ModuleUninstallCommandTest|ModuleRestoreCommandTest|NativeModuleCommandTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add module:uninstall and module:restore commands" -m "Add CLI commands for the complete reversible module lifecycle: module:uninstall requires the exact UNINSTALL confirmation phrase and deletes everything; module:restore brings the module back from git or Composer with empty tables."
```

---

## Task 10: Panel Simplification — Disable Only (TDD)

**Files:**
- Modify: `app-modules/admin/src/Livewire/ModulesList.php` (remove uninstall/reinstall methods; PRESERVE the protected-disable guard added in Task 7)
- Modify: `app-modules/admin/resources/views/modules-list.blade.php` (remove modal/buttons, add CLI restore hint)
- Modify: `lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php` (translation keys)
- Modify: `tests/Feature/Livewire/ModulesListTest.php` (rewrite; keep the required/protected disable tests from Task 7)

**Interfaces:**
- Consumes: `Module::StatusUninstalled` and the registry marker rows left by Task 4's uninstall.
- Produces: a Modules List page with only the enable/disable toggle; uninstalled rows show a CLI restore hint.

- [ ] **Step 1: Update the Livewire tests first (red)**

In `tests/Feature/Livewire/ModulesListTest.php`:
- Delete the `prepares an uninstall preview for modules without handlers` and `reinstalls uninstalled modules from their manifest` tests (those flows no longer exist).
- Add:

```php
it('shows a CLI restore hint for uninstalled modules without destructive buttons', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertSee('php artisan module:restore extensions')
        ->assertDontSee('prepareUninstall')
        ->assertDontSee('reinstallModule');
});
```

Run: `php artisan test --compact --parallel --filter=ModulesListTest`
Expected: FAIL — the old methods still exist and the hint is missing.

- [ ] **Step 2: Simplify the Livewire component**

In `app-modules/admin/src/Livewire/ModulesList.php` remove: the `ModuleLifecycleService` boot injection, the `pendingUninstallModuleId` / `uninstallConfirmation` / `uninstallPreview` properties, and the `prepareUninstall()`, `cancelUninstall()`, `uninstallModule()`, `reinstallModule()` methods. Keep `mount()`, `loadModules()`, `toggleEnabled()`, `render()`. Update the class docblock: the page now offers only enable/disable; uninstall and restore run through the CLI.

- [ ] **Step 3: Simplify the view**

In `app-modules/admin/resources/views/modules-list.blade.php`:
- Delete the entire `@if($pendingUninstallModuleId)` warning/confirmation block (lines with the modal).
- In the actions column, replace the `reinstallModule` button with the hint:

```blade
@if($module->status === \App\Models\Module::StatusUninstalled)
    <span class="text-sm text-base-content/60">
        {{ __('admin.module_restore_hint', ['name' => $module->name]) }}
    </span>
@else
    <div class="flex flex-wrap gap-2">
        @if($module->enabled)
            <button wire:click="toggleEnabled('{{ $module->id }}')"
                    @if($module->required) disabled @endif
                    class="btn btn-error btn-xs"
                    @if($module->required) title="{{ __('admin.module_required') }}" @endif>
                {{ __('admin.disable_module') }}
            </button>
        @else
            <button wire:click="toggleEnabled('{{ $module->id }}')"
                    class="btn btn-success btn-xs">
                {{ __('admin.enable_module') }}
            </button>
        @endif
    </div>
@endif
```

- Remove the now-unused `prepareUninstall` button from the enabled/disabled branch.

- [ ] **Step 4: Update translations**

- Add to `lang/en/admin.php`: `'module_restore_hint' => 'Restore over SSH: php artisan module:restore :name'`.
- Add the same key to `lang/es/admin.php` and `lang/fr/admin.php` with translated text.
- Remove the now-unused keys from all three languages: `uninstall_module`, `uninstall_module_warning`, `uninstall_module_confirmation`, `confirm_uninstall_module`, `reinstall_module` (verify with `grep -rn "uninstall_module\|reinstall_module" app-modules resources lang` that nothing else references them first).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter=ModulesListTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: restrict panel module management to enable and disable" -m "Remove the panel soft-uninstall and reinstall flows; uninstalled modules now show a CLI restore hint. Full removal and restore run via module:uninstall and module:restore as root."
```

---

## Task 11: Documentation and Changelog

**Files:**
- Modify: `AGENTS.md` (Modular Architecture section)
- Modify: `CHANGELOG.md` (Unreleased)

- [ ] **Step 1: Document the conventions in AGENTS.md**

Under `## Modular Architecture`, add after the autoloading bullet:

```markdown
- Model factories live inside their module at `app-modules/ModuleName/src/Database/Factories/` under the `Modules\ModuleName\Database\Factories` namespace, resolved by Laravel's standard `HasFactory` resolution. Never place module factories in the root app's `database/factories/`, and never override `newFactory()` on module models.
- Module tests live inside their module at `app-modules/ModuleName/tests/` and run through the host suite's `app-modules/*/tests` glob. Cross-module tests stay in the central `tests/` tree and are skipped automatically by the module-aware guard when a referenced module is not installed.
- The web panel offers only the non-destructive enable/disable toggle for modules. Complete removal and restoration run from the CLI as root: `php artisan module:uninstall <name>` (exact confirmation phrase required; deletes the module directory — including its in-module tests — Composer entries, permissions, and data while keeping a registry marker) and `php artisan module:restore <name>` (reinstalls from git for first-party modules or Composer for vendor packages; tables return empty, data is not restored). Uninstalling leaves the module's files deleted in the working tree, which the Git updater treats as uncommitted changes — run `module:restore` to undo, or commit/stash the deletions before updating.
```

- [ ] **Step 2: Update CHANGELOG.md**

Under `## [Unreleased]` add:

```markdown
### Added
- Added the `module:uninstall` and `module:restore` Artisan commands: uninstall completely removes a module — its files (including its in-module tests), Composer entries, permissions, and data (via its uninstall handler when present) — while keeping a registry marker; restore reinstalls it from git (first-party) or Composer (vendor) with empty tables and re-seeded permissions. Data is intentionally not restored.
- Added a `composer_package` column to the module registry so vendor modules can be restored after uninstall.
- Marked the core PBX modules (`extensions`, `devices`, `sip-accounts`, `destinations`, `dialplans`, `dialplan-tools`, `gateways`, `sip-profiles`, `inbound-routes`, `outbound-routes`) as protected — they can no longer be uninstalled or disabled (FreePBX-style core protection; see the plan's External References section).
- Populated `requirements.modules` across all modules from their actual cross-module references and added `ModuleBoundaryTest`, which enforces declared dependencies and an acyclic dependency graph, so `module:uninstall` can refuse removal while installed dependents exist.

### Changed
- Moved all module model factories out of the root `database/factories/Pbx/` directory into their owning modules (`app-modules/{name}/src/Database/Factories/`) and removed the per-model `newFactory()` overrides so models use Laravel's standard factory resolution.
- Moved each module's central test folder into the module itself (`app-modules/{name}/tests/`), discovered through a `phpunit.xml` glob so modules are fully self-contained; tests that reference an uninstalled module are skipped automatically.
- The panel Modules page refuses to disable protected modules (previously only required ones).

### Removed
- Removed the web panel's soft-uninstall and reinstall actions; the Modules page now offers only enable/disable, with a CLI hint for uninstalled modules.
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "docs: document module factory and lifecycle conventions" -m "Record the module-local factory convention and the uninstall/restore lifecycle in AGENTS.md, and update the changelog."
```

---

## Task 12: New-Module Scaffold Completeness & AI Guidance Documents

**Files:**
- Modify: `app/Console/Commands/MakeModuleCommand.php` (complete scaffold + bullets; the factories dir from Task 2 and the tests dir from Task 5 remain)
- Modify: `resources/schemas/module.json` (`requirements.modules` becomes a string array)
- Modify: `tests/Feature/Commands/NativeModuleCommandTest.php` (completeness test)
- Modify: `.agents/skills/tallpbx-custom/SKILL.md` (new "Module Design Conventions" section)
- Modify: `.agents/skills/testing-best-practices/SKILL.md` (module test location + guard rules)

**Why this task exists:** every earlier task changes what a well-formed module looks like. `make:module` must scaffold that complete shape so future modules are born correct, and the project's AI skill documents must teach other agents/harnesses the same shape — otherwise new-module work drifts back to the old central factories/tests layout and undeclared dependencies.

- [ ] **Step 1: Completeness test for make:module (TDD)**

Extend the scaffold test in `tests/Feature/Commands/NativeModuleCommandTest.php` with the full new-module layout:

```php
    expect($modulePath.'/src/Livewire')->toBeDirectory()
        ->and($modulePath.'/src/Database/Factories')->toBeDirectory()
        ->and($modulePath.'/tests')->toBeDirectory()
        ->and($modulePath.'/database/migrations')->toBeDirectory()
        ->and($modulePath.'/config')->toBeDirectory()
        ->and($modulePath.'/lang/en')->toBeDirectory()
        ->and($modulePath.'/resources/views')->toBeDirectory()
        ->and($modulePath.'/src/Providers/ModuleServiceProvider.php')->toBeFile();

    $manifest = json_decode((string) file_get_contents($modulePath.'/module.json'), true);

    expect($manifest['protected'])->toBeFalse()
        ->and($manifest['required'])->toBeFalse()
        ->and($manifest['requirements']['modules'])->toBe([])
        ->and($manifest['providers'])->toContain('Modules\\NativeCommandTest\\Providers\\ModuleServiceProvider');
```

Run: `php artisan test --compact --parallel --filter=NativeModuleCommandTest`
Expected: FAIL for any missing piece. Add the missing directories to `MakeModuleCommand::createDirectories()` (the factories dir lands via Task 2 Step 3 and the tests dir via Task 5 Step 4 — this step only adds what those did not) and list every scaffolded path in the `bulletList()` summary. Re-run: PASS.

- [ ] **Step 2: Fix the manifest schema for string module requirements**

**Explanation:** `resources/schemas/module.json` currently models `requirements.modules` items as objects (`{"name", "version"}`). The new authoritative form — populated by Task 7, consumed by `ModuleLifecycleService::installedDependents()` and `ModuleBoundaryTest` — is a plain array of module machine names. The schema must match the code, or manifest validation and the boundary tests disagree. Update the schema:

```json
        "modules": {
            "type": "array",
            "description": "Required TallPBX module machine names (enforced by ModuleBoundaryTest).",
            "items": {
                "type": "string",
                "pattern": "^[a-z][a-z0-9_-]*$"
            }
        }
```

Run `tests/Feature/ModuleManifestTest.php` plus `--filter=ModuleBoundaryTest`: PASS.

- [ ] **Step 3: Teach the tallpbx-custom skill the module design conventions**

Append a section to `.agents/skills/tallpbx-custom/SKILL.md` so every AI agent/harness that works on modules learns the new shape:

```markdown
## Module Design Conventions

Apply when creating or modifying an app-modules module. New modules are
scaffolded with `php artisan make:module <name>` and must keep this shape:

- Code: `src/{Models,Services,Livewire,Support,Database/Factories,Providers}` under `Modules\{Studly}\`.
- Factories: `src/Database/Factories/`, namespace `Modules\{Studly}\Database\Factories`, resolved by standard HasFactory — never `database/factories/Pbx`, never a `newFactory()` override.
- Tests: Pest tests inside `app-modules/{name}/tests/` (`*Test.php`), discovered by the phpunit.xml glob. Cross-module tests stay in the central `tests/` tree; the ModuleAwareTestGuard skips them automatically when a referenced module is missing. Annotate URL-only or Dusk tests with `$this->skipWhenModuleUninstalled('<module>');`.
- Config: ship `config/{name}.php` to override centralized app-level defaults; it merges under the module's key (module values win on conflicts, all other keys survive).
- Dependencies: declare every cross-module class reference in `module.json` → `requirements.modules` (array of module name strings). ModuleBoundaryTest fails on undeclared references or cycles.
- Core modules are protected — never uninstallable, never disable-able: extensions, devices, sip-accounts, destinations, dialplans, dialplan-tools, gateways, sip-profiles, inbound-routes, outbound-routes (plus required admin, auth, tenant).
- Lifecycle: the panel offers enable/disable only. Removal: `php artisan module:uninstall <name>` (typed confirmation; refuses while dependents exist). Restore: `php artisan module:restore <name>` (from git or Composer; database data is not restored).
```

- [ ] **Step 4: Teach the testing-best-practices skill the module test rules**

Append to `.agents/skills/testing-best-practices/SKILL.md`:

```markdown
## Module Tests

- Module-owned tests live inside the module at `app-modules/{name}/tests/` with `*Test.php` filenames; the phpunit.xml Feature suite discovers them through the `app-modules/*/tests` glob.
- Cross-module tests (tenant isolation, dialplan integration, panel smoke) stay in the central `tests/` tree. They are skipped automatically by the ModuleAwareTestGuard when a referenced module is uninstalled — never let a missing module fail the suite.
- For URL-only or Dusk tests that touch a module without importing its classes, add `$this->skipWhenModuleUninstalled('<module>');` as the first line of the test.
- Convention guards run with the suite: ModuleFactoryIsolationTest (factories inside modules), ModuleBoundaryTest (declared, acyclic dependencies), CoreModulesProtectedTest (protected core set).
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: teach new-module conventions to make:module and AI skills" -m "Complete the make:module scaffold for the full new-module layout, align the manifest schema's requirements.modules with the string-array form, and document the module design and testing conventions in the tallpbx-custom and testing-best-practices skills."
```

---

## Task 13: Full Verification and Release

- [ ] **Step 1: Cache clear**

Run: `php artisan optimize:clear`

- [ ] **Step 2: Format**

Run: `vendor/bin/pint --dirty --format agent` — Expected: passed.

- [ ] **Step 3: Full parallel suite**

Run: `php artisan test --compact --parallel`
Expected: all green. The relocated factories must not change any behavior; the lifecycle, command, panel, and guard tests must pass.

- [ ] **Step 4: Commands visible**

Run: `php artisan list | grep module:` — Expected: `module:uninstall`, `module:restore` listed alongside `module:sync`, `module:list`, etc.

- [ ] **Step 5: Manual smoke of uninstall/restore on a copy**

In a scratch clone (never the working tree): `php artisan module:uninstall sip-status --confirm="UNINSTALL sip-status"`, then `php artisan module:restore sip-status`, verifying the module returns and the suite's sip-status tests pass again.

- [ ] **Step 6: Delete this plan document** (completed design docs are removed per project convention) after user approval, then request commit/push approval from the user.

---

## Self-Review Notes

- Spec coverage: factory relocation (Tasks 1–2), registry column (Task 3), lifecycle rewrite with uninstall/restore + vendor branch + dependent-refusal (Task 4), module test relocation + glob discovery (Task 5), module config with centralized fallback (Task 6), core protection + declared dependencies + boundary tests (Task 7), module-aware test guard (Task 8), commands (Task 9), panel simplification + Dusk annotations (Task 10), docs + changelog (Task 11), scaffold completeness + AI skill documents (Task 12), verification (Task 13).
- Type consistency: `ModuleLifecycleService` method signatures and report shapes match between the service, both commands, and every test. Runner seams are `Closure(array<int, string>): bool` for both composer and git. The service class is not final so tests may subclass it if needed.
- Review Focus: all nineteen items are pinned by tests in Tasks 1–10 (guards, refusal paths, uninstaller handling, composer failure warnings, marker retention, unknown-origin refusal, empty-table restore, local precedence, panel button removal, cross-factory imports, scaffold directories, in-module tests, skip-never-fail guard, config fallback, protected core set, declared acyclic dependencies, dependent-refusal).
