# Modular Architecture Rethink — Independent Review & Findings Register

- **Date**: 2026-10-12
- **Reviewed branch / HEAD**: `4.x` @ `b28f8ebd` ("docs: update implementation plan marking tasks 1 and 2 complete")
- **Documents evaluated**:
  - Spec — `docs/superpowers/specs/2026-10-11-modular-architecture-rethink-design.md`
  - Plan — `docs/superpowers/plans/2026-10-11-modular-architecture-rethink.md`
- **Also reviewed**: the implementation state produced by Tasks 1–2 and all plan-related commits (`dfb24332`, `2aafb5e6`, `425a84eb`, `1f488767`, `a9a5ab21`, `17399bdc`, `c8d31284`, `728b1816`, `b28f8ebd`).

## How to use this document

This is a baseline findings register produced by one reviewer. It is designed to be compared against independent reviews from other AI agents so the spec, plan, and code changes can be decided from a merged picture.

- Each finding has a **stable ID** (`F1`–`F12`). Match findings across reviews by ID, not by wording.
- Each finding is tagged **Verified** (observed directly in the repository during this review) or **Analyzed** (derived from reading code/documentation, not executed).
- Each finding lists **Evidence** (reproducible commands / file locations), **Impact**, **Affected artifacts** (Spec section, Plan task, Code files), and **Proposed change**.
- Section 6 maps every finding to a concrete change in the spec document, the plan document, and/or the code, because those change decisions are the point of the comparison.
- Claims in the Evidence column are ground truth to re-verify; treat other agents' findings the same way.

## 1. Overall assessment

The change is **directionally good and worth doing**: removing 60 in-repo Composer path repositories in favor of direct PSR-4 autoloading, establishing a `custom-modules/` extensibility boundary, adding collision guards, and cleaning up Backups ownership are all defensible, industry-aligned decisions for an appliance product (comparable to FusionPBX drop-in apps). The process discipline (TDD steps, granular conventional commits, changelog/docs updates, explicit verification gate, "Review Focus" acceptance criteria) is above average.

However, the execution as of `b28f8ebd` has a structural problem: **the system currently works only because of stale Composer caches that the plan's own remaining tasks will eventually destroy.** Two findings are release-blocking on any fresh installation or cache rebuild (F1, F2), and three plan tasks (3, 5, 6) are under-specified against the actual repository state (F3, F6, F7). None of these invalidate the architecture — they mean the plan must be amended before Tasks 3–7 proceed.

## 2. Severity summary

| ID | Severity | Summary | Primary artifact affected |
| :--- | :--- | :--- | :--- |
| F1 | **Critical** | Composer migration is half-applied: lockfile, `installed.json`, and discovery caches still declare all 60 path packages; Task 1 marked complete prematurely. | Plan (Task 1 + gate) + code/state |
| F2 | **Critical** | `backups` and `email-connector` manifests lack a `providers` key; discovery has no fallback; both modules boot only via stale caches and break the plan's own Task 4. | Plan (Tasks 2/4) + code (manifests, discovery) |
| F3 | High | Task 6 consumes `source`/`category` data that does not exist (DB columns absent; manifest taxonomy mismatched; spec mapping covers only 57 of 60 modules). | Spec §6.1 + Plan Task 6 |
| F4 | High | Spec §4.1 caching story ("cleared by `optimize:clear`, rebuilt by `optimize`/`module:sync`") is not implemented; `make:module` "Instant Activation" is false when a manifest cache exists. | Spec §4.1 + Plan + code |
| F5 | High | Spec §4.1 ("disabled modules are not registered") contradicts the shipped hybrid (all providers register; boot-time registration is gated). | Spec §4.1 |
| F6 | High | Collision guards (spec §8 / Task 3) are under-specified: silent name-drop, empty placeholder, no migrator integration, undefined route-guard mechanism, fail-hard dependencies, regex divergence. | Plan Task 3 + Spec §8 |
| F7 | High | Module lifecycle (Task 5) is still written for the path-repo world: restore is broken for legacy uninstalls, uninstall has an accidental safety net, dead code remains, custom-module deletion semantics undefined. | Plan Task 5 + code |
| F8 | Medium | Spec sections without plan tasks: §7.3 observer decoupling, §2.2 custom install/update lifecycle, §6.2 delete-extension UI, i18n parity for new UI strings. | Plan (missing tasks) |
| F9 | Medium | Documentation rot in commands that still describe Composer-owned autoloading (`ModuleCacheCommand`, `ModuleClearCommand`, `ModuleSyncCommand`). | Code comments |
| F10 | Medium | Disaster-recovery and security blind spots: `custom-modules/` absent from backup scope; backup archives contain secrets by design; plugin trust model unstated. | Spec §5.1 + Plan |
| F11 | Low | Test coverage gaps: no manifest-providers completeness test (the F2 bug class), misleading test name, no cold-state discovery test. | Tests + plan gates |
| F12 | Low | 60 vestigial per-module `composer.json` files remain; manifest schema lacks the fields the new architecture relies on. | Code (files) + schema |

---

## 3. Finding register

### F1 — Composer migration is half-applied; Task 1 completion claim is inaccurate

**Severity**: Critical · **Tag**: Verified

**What was claimed**: Spec §3.1 / Plan Task 1 — "the 60 path repository declarations are completely removed. Autoloading is handled directly by optimized PSR-4 autoloading." Task 1 is marked `(COMPLETED)` including Step 4 verification.

**Evidence** (all verified on `4.x` @ `b28f8ebd`):
- `composer.json`: genuinely clean — no `repositories` block, no `tallpbx/module-*` requires, 60 direct PSR-4 mappings. This part is correct.
- `grep -c '"name": "tallpbx/module' composer.lock` → **60**. The lockfile still pins all 60 path packages.
- `vendor/composer/installed.json` still lists **60** `tallpbx/module-*` packages.
- `ls vendor/tallpbx` → **empty** (0 entries) — sources are gone while metadata says they are installed.
- `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` still register all **60** module providers through Composer package discovery.
- Commit `c8d31284` touched only `composer.json` and `tests/Feature/ModuleAutoloadTest.php` — no lockfile regeneration, no `composer update`.

**Impact**:
- Every fresh installation (one-line installer, CI, another 4.x deployment) reads the stale lock. Composer will warn that the lock is out of date but still resolve the 60 path packages — re-creating `vendor/tallpbx` symlinks and keeping the legacy package-discovery mechanism alive. This must be confirmed with `composer install --dry-run` on a clean checkout; the metadata inconsistency itself is already proven.
- The plan's verification gate ("`composer dump-autoload -o`… with zero path-repository warnings") cannot detect any of this. `composer validate --strict` would fail today.
- Tests pass on this machine because the autoloader currently resolves the modules either way; the failure mode appears on the *next* environment, not this one.

**Affected artifacts**: Plan Task 1 (steps and completion marking), Plan Global Constraints/verification gate, `composer.lock`, `vendor/composer/installed.json`, `bootstrap/cache/packages.php` (regenerable), CHANGELOG wording (nuance below).

**Proposed change**:
- Plan: add a new task (or explicit Task 1 revision) — run `composer update` so the lockfile is regenerated without the 60 packages; add `composer validate --strict` and a "no `vendor/tallpbx` symlinks" assertion to the verification gate; record the expected installer behavior.
- Code/state: regenerate the lock/installed state, run `php artisan optimize:clear`, and re-verify discovery in a truly cold state (also see F4).
- CHANGELOG: the entry "Eliminated all 60 Composer path repositories and `tallpbx/module-*` requirements from root `composer.json`" is true as written (root `composer.json`) but will mislead unless the lockfile step completes in the same release.

---

### F2 — `backups` and `email-connector` lack a `providers` key; discovery has no fallback

**Severity**: Critical · **Tag**: Verified

**What was claimed**: Spec §4.1 — discovery registers "its declared `ModuleServiceProvider` subclass" for each enabled module; Plan Task 4 depends on the Backups provider booting to own its routes, menus, and permissions.

**Evidence**:
- Of the 60 manifests, **58 declare `providers`**; `app-modules/backups/module.json` and `app-modules/email-connector/module.json` do not.
- Discovery registers providers strictly from the manifest: `app/Providers/ModuleServiceProvider.php` iterates `$manifest['providers'] ?? []` with no convention fallback.
- The generated cache confirms the gap: `bootstrap/cache/modules.php` entries for both modules have no `providers` array.
- Both modules currently boot **only** because of the stale Composer caches from F1 (`bootstrap/cache/packages.php` lists `Modules\Backups\…` and `Modules\EmailConnector\Providers\ModuleServiceProvider`). `php artisan route:list` currently shows 4 `panel.backups.*` routes and 6 `panel.email-*` routes — all relying on that legacy path.
- `email-connector` owns its routes (`app-modules/email-connector/routes/web.php`) and has no `AppServiceProvider` workaround — after a clean Composer state it silently loses routes, menu, translations, and Livewire namespace.
- `backups` has a partial workaround in `app/Providers/AppServiceProvider.php` (`loadViewsFrom` + duplicate `BackupServiceInterface` bind, lines ~176–186, comment cites a "sandbox composer double-load edge case"), but its provider (which owns `RestoreCommandRunnerInterface → SymfonyRestoreCommandRunner` via `$bindings`) would never boot. Plan Task 4 then moves `/panel/backups` routes into a provider that never loads — the task cannot work as written. Task 4's file list does not touch `module.json`.

**Impact**: The single most dangerous finding: it passes all current tests and works on this machine, but breaks Task 4 and regresses `email-connector` the moment the Composer state is cleaned (F1) or the discovery caches are rebuilt.

**Affected artifacts**: Plan Tasks 2/4 (scope), `app-modules/backups/module.json`, `app-modules/email-connector/module.json`, `app/Providers/ModuleServiceProvider.php` (discovery), Spec §3.2/§4.1 (manifest contract).

**Proposed change**:
- Code: add the `providers` array to both manifests; add a **convention fallback** in discovery — if the manifest lacks `providers` and `{namespace}\Providers\ModuleServiceProvider` exists, register it (and log a deprecation/warning steering authors to declare it).
- Code/tests: add a manifest-completeness test asserting that every module shipping `src/Providers/ModuleServiceProvider.php` declares it in `module.json` (this is the exact bug class that exists today; the current `ModuleAutoloadTest` only checks `class_exists` autoloadability, not registration).
- Plan: extend Task 2/4 scope explicitly; note the Task 4 precondition in its Interfaces section.
- Spec: state the manifest contract (providers declared, fallback behavior) in §3.2/§4.1.

---

### F3 — Task 6's data model does not exist (source/category columns, taxonomy, coverage)

**Severity**: High · **Tag**: Verified

**What was claimed**: Plan Task 6 — "Consumes: `Module` records with `source`, `category`, `enabled`, `protected`, `required`" and groups modules "based on each module's declared `category` / menu parent" into the nine nav-parity subcategories.

**Evidence**:
- The `modules` table (`database/migrations/2026_06_29_000001_create_modules_table.php`) has **no `source` column and no `category` column** (columns: id, name, composer_package, source_ref, display_name, version, enabled, status, protected, required, priority, settings, timestamps).
- Manifests use a **different taxonomy** than the spec's nine groups: observed categories are "Advanced" (13), "Call Features" (14), "Monitoring" (8), "PBX" (16), "PBX Features" (1), "Provisioning" (1), "System" (5) — 58 manifests declare one; `backups` and `email-connector` declare none.
- `ModulesList` reads from the **database** (`Module::orderBy('priority')…`), which carries no category and no source.
- Spec §6.1's mapping covers **57 of 60 modules**: `click-to-call`, `destinations`, and `extension-settings` appear in no bucket.
- Task 6 Step 3 says "based on each module's declared `category` / menu parent" — neither source yields the promised grouping (menu parent data lives in provider `menuItems()`, not on the DB record).

**Impact**: Task 6 cannot be implemented as written; the implementer will have to invent a data path, and the "navigation parity" promise (the point of the task) is unverifiable without a canonical mapping.

**Affected artifacts**: Plan Task 6 (step 1 tests and step 3 implementation), Spec §6.1 (mapping completeness), `resources/schemas/module.json`, `modules` migration, `ModuleSyncCommand`, `ModulesList`.

**Proposed change**:
- Decide ONE canonical source of the UI taxonomy (see Open Decisions #1) — recommended: add `ui_group` (one of nine stable slugs) to `module.json`, keep a single mapping class for fallback, sync the value into the DB (new column + migration + `module:sync` update), and render from the DB.
- Fix spec §6.1 to list all 60 modules exactly once (add `click-to-call`, `destinations`, `extension-settings`).
- Task 6 tests: assert 60/60 coverage, correct group assignment, and unknown-group fallback behavior.

---

### F4 — The caching story is unimplemented and internally inconsistent

**Severity**: High · **Tag**: Verified

**What was claimed**: Spec §4.1 — "Cache is cleared on `php artisan optimize:clear` and rebuilt on `php artisan optimize` or `php artisan module:sync`." Spec §9 — `make:module` gives "Instant Activation: Immediately discovered and autoloaded at runtime."

**Evidence**:
- `optimize:clear` does **not** remove `bootstrap/cache/modules.php`; the only `CommandFinished` listener in `AppServiceProvider` repairs permissions and nothing else.
- `module:sync` writes the **database** only; `module:cache` is the only writer of `modules.php`; `module:clear` the only deleter; a repo-wide grep shows neither `module:clear` nor `module:cache` is invoked by any other code path.
- `make:module` performs **no cache invalidation** (no Artisan calls at all), so "Instant Activation" is false on any system where `bootstrap/cache/modules.php` already exists — which is every production system.
- Root maintainability theme: there are now **three sources of truth** (filesystem `module.json` files, `bootstrap/cache/modules.php`, and the `modules` DB table) with three commands and no reconciliation flow. A module present in the DB but absent from a stale cache never boots its provider; toggling it in the panel then has no observable runtime effect.

**Impact**: Stale-cache support incidents; confusing enable/disable behavior; documentation that sets wrong operator expectations.

**Affected artifacts**: Spec §4.1 and §9, Plan (missing cache-wiring task), `MakeModuleCommand`, `ModuleSyncCommand`, `ModuleCacheCommand`, `optimize:clear` integration.

**Proposed change**:
- Code: make `module:sync` rebuild (or invalidate) `modules.php` after a successful sync; make `make:module` invalidate it (or print the exact follow-up commands); optionally hook `module:clear` into the panel's post-uninstall/restore flow (the lifecycle service already runs `optimize:clear` — that does not cover this).
- Plan: add these to an explicit task; correct spec §4.1/§9 to describe the real flow.
- Decide and document the reconciliation contract between the DB table and the manifest cache (Open Decisions #6).

---

### F5 — Spec §4.1 contradicts the shipped provider-registration model

**Severity**: High · **Tag**: Verified

**What was specified**: "For each discovered module: Check `ModuleState::isEnabled($name)`. If enabled: register its provider. If disabled: **do not register the provider**."

**What exists**:
- `app/Providers/ModuleServiceProvider.php` registers **all** providers unconditionally at `register()` time.
- `app/Support/ModuleServiceProvider.php::boot()` then gates the boot-time registrations on `ModuleState::isEnabled()` — routes, Livewire, menu, permissions, listeners, commands are skipped when disabled; views, translations, migrations, and config **always** load (correct behavior for schema integrity per spec §7.1).
- `ModuleState::isEnabled()` is intentionally fail-open when the registry is unavailable (fresh DB, pre-migration boot).

**Impact**: The hybrid design is sound (container bindings from disabled modules remain available — the documented `BlockFileStoreDeletion` guard in `AppServiceProvider` even *depends* on the disabled-module semantics), but a Task 3+ implementer "fixing" the code toward the spec text would break it. Documentation drift on a load-bearing architectural decision.

**Affected artifacts**: Spec §4.1 (correction needed); no code change required.

**Proposed change**: Rewrite spec §4.1 to describe the actual model: providers always register; boot-time registrations gated by `ModuleState`; views/translations/migrations/config always load; fail-open when the registry is unavailable; disabled modules keep container bindings. Add the rationale.

---

### F6 — Collision guards are conceptually right but under-specified and partially self-defeating

**Severity**: High · **Tag**: Verified (current behavior) + Analyzed (guard mechanisms)

**What was specified**: Spec §8 (five guard families) and Plan Task 3.

**Evidence / problems**:
1. **Name collision — silent drop, not the specified error.** Cross-source collisions are resolved by `$modules[$name] ??= $manifest` in discovery: the custom module is silently discarded. Spec §8.1 requires a descriptive rejection message. `validateModuleNames()` is an **empty placeholder** with a misleading comment ("Names are already unique since we use name as array key") that Task 3 never says to remove.
2. **Dependency failure bricks the appliance.** `checkDependencies()` throws `RuntimeException` during `register()` — one bad third-party manifest takes down the entire panel and every Artisan command. Additionally, the **cached path skips dependency validation entirely** — the cold and warm paths enforce different rules.
3. **Table-collision integration point missing.** Task 3's file list does not include the natural enforcement point — the existing `SafeMigrator` decorator (`AppServiceProvider` `extend('migrator', …)`). "Inspecting table creation calls" by text-parsing migration files is fragile; the migrator decorator can enforce `assertNoTableCollision` at runtime with `Schema::hasTable` checks.
4. **Route-collision mechanism undefined.** "Rejected before route compilation" has no described mechanism. Text-scanning route files is brittle; audit-after-registration (name/prefix check with console-fail / production-log-and-skip semantics) needs to be specified.
5. **Naming-rule divergence.** Manifest schema and `ModuleSyncCommand` enforce `^[a-z][a-z0-9-]*$` (kebab), but discovery's `loadManifest` accepts underscores (`^[a-z][a-z0-9_-]*$`). Same file class, two rules.
6. Translation-fallback policy divergence is intended (custom modules degrade gracefully; core modules with root collisions hard-fail in local/testing), but the plan text does not say the policy is intentionally source-dependent — worth one explicit sentence so it is not "fixed" later.

**Impact**: Guards as planned either do not block what they promise or block it in a way that can brick a production PBX.

**Affected artifacts**: Plan Task 3 (scope, files, tests), Spec §8.1–8.5, `app/Providers/ModuleServiceProvider.php`, `app/Services/PermissionService.php`, `SafeMigrator`.

**Proposed change**:
- Plan Task 3: add `validateModuleNames()` removal/replacement; specify log-critical + refuse-to-register for name collisions (never throw at boot); change missing-dependency handling to fail-soft (mark disabled, log critical) and apply it consistently on cached and cold paths; add `SafeMigrator` to the file list with a dedicated test using a sandboxed/mocked migrator; specify the route-collision audit mechanism precisely; unify the name regex.
- Spec §8: add the descriptive-error wording (already present) plus explicit degrade semantics and the source-dependent translation policy.

---

### F7 — Module lifecycle (Task 5) is still designed for the path-repo world

**Severity**: High · **Tag**: Verified (code paths) + Analyzed (runtime outcome, not executed)

**What was specified**: Plan Task 5 — refuse destructive operations on `source === 'core'`; allow deletion only for `custom` (directory) and `vendor` (composer).

**Evidence / problems in `app/Services/ModuleLifecycleService.php`**:
1. **Restore is broken for legacy uninstalls.** `restore()` for a git-sourced local module runs `composer require tallpbx/module-{name}` to "re-link the path package" (and `addRepositoryEntry`/`addRequireEntry` re-add path repos). Post-Task-1, no repository defines those packages — the step fails, and restore dies at "Module files were restored, but Composer could not re-link…". `previewRestore()`'s item list still instructs operators to "Re-add the Composer path-repository and require entries". Restore for core modules should reduce to `git restore` plus a cache rebuild; none of the Composer manipulation is needed (or possible) anymore.
2. **Uninstall has an accidental safety net.** `uninstall()` runs `composer remove tallpbx/module-{name}` first and aborts if it fails. Since Task 1 removed the require entries, `composer remove` of a package that is no longer required fails — so uninstalling a non-protected core module (e.g. `backups`, which is neither `protected` nor `required`) aborts with a confusing "Composer could not remove" message instead of the intended explicit refusal. Task 5 should make the refusal explicit and first-class (and is a genuine behavioral improvement over both the old destructive path and today's confusing failure).
3. **Dead code and obsolete semantics.** `stripRepositoryEntry`/`stripRequireEntry`/`addRepositoryEntry`/`addRequireEntry` and the `composer_package = "tallpbx/module-{name}"` reporting become fiction for local/core modules. For `source === 'custom'`, the plan says "remove only the untracked directory" — but the service currently has no custom-source branch at all, and deletion of a git-ignored directory is **irreversible** (no git restore, no composer reinstall). Messaging, typed confirmation, and an explicit "cannot be restored" statement are needed to match the project's destructive-action conventions.
4. **Vestigial files**: all 60 modules still ship a `composer.json` (`type: tallpbx-module`, `extra.laravel.providers`, classmap autoload). Post-Task-1 these are inert; they are also what the stale Composer metadata was built from, adding confusion during audit. Decide: delete (recommended; `module.json` is the source of truth) or keep with a documented purpose.

**Impact**: `module:restore` is now a broken recovery path; uninstall UX is contradictory; dead code misleads future maintainers.

**Affected artifacts**: Plan Task 5 (expand scope), `ModuleLifecycleService`, `ModuleUninstallCommand`, `app-modules/*/composer.json` (60 files), tests for the service.

**Proposed change**:
- Plan Task 5: rewrite to reconcile the full lifecycle with the no-path-repos architecture: explicit core refusal (no composer attempt), custom = delete directory + DB cleanup with irreversible-deletion messaging and typed confirmation, vendor = composer-only, restore = `git restore` + cache rebuild for core, remove all path-repo manipulation code and obsolete preview copy, update `ModuleUninstallCommand` and tests accordingly.
- Code: delete the dead Composer-entry helpers; decide the fate of the 60 module `composer.json` files.

---

### F8 — Spec sections without corresponding plan tasks

**Severity**: Medium · **Tag**: Verified (spec text vs plan task list)

**Missing task coverage**:
1. **Spec §7.3** — "Move telephony model registration out of the monolithic `AppServiceProvider`" (`RoutingCacheObserver`). No plan task; `AppServiceProvider::registerRoutingCacheObservers()` still holds the ~29-model list.
2. **Spec §2.2, Custom Extensions row** — lifecycle actions "Install, Update, Enable, Disable, Delete". No task implements upload/install/update of custom modules; if deferred, the plan should say so.
3. **Spec §6.2** — custom modules "show a dropdown menu with 'Delete Extension'". Task 6's steps never implement or test it.
4. **New UI strings** (nine group labels, "Custom" badge, tooltips) — no lang-file updates in Task 6, yet the project enforces 100% `en/es/fr` parity via `TranslationParityTest`.
5. **Browser-test expectation** — AGENTS.md requires browser coverage for UI changes; Task 6 only lists a Livewire feature test. Either add a Playwright browser test or justify omission.

**Impact**: Spec/plan coverage mismatch invites "silent" scope loss; the parity test may fail during Task 6 if strings are added without lang updates.

**Affected artifacts**: Plan (add tasks or explicit deferral notes), Spec §2.2/§6.2/§7.3 (or plan-side annotation).

---

### F9 — Documentation rot in module commands

**Severity**: Medium · **Tag**: Verified

**Evidence**:
- `ModuleCacheCommand` docblock: "…Composer remains responsible for autoloading modules through path or VCS repositories." (false post-Task-1).
- `ModuleClearCommand` docblock: "Composer is responsible for module autoloading." (false).
- `ModuleSyncCommand` docblock: "Scans module.json files in app-modules/ and vendor/ directories" (omits `custom-modules/`, which the body does scan).

**Impact**: Directly contradicts the architecture this plan establishes; violates the project's own comment-accuracy expectations.

**Affected artifacts**: The three command files; sweep during Tasks 3–7.

---

### F10 — Disaster recovery and security blind spots

**Severity**: Medium · **Tag**: Analyzed (spec/plan text review)

**Gaps**:
1. **`custom-modules/` is not in any backup scope.** Spec §5.1 declares Backups responsible for "the entire appliance", but custom modules are untracked, git-ignored code (`custom-modules/` is in `.gitignore` with a `.gitkeep`). A disk loss destroys them permanently unless the backup scope explicitly includes the directory.
2. **Backup archives contain secrets by design** (spec §5.1 lists "encryption keys, environment secrets, and credentials"). Archive encryption and key custody (the decryption key must not live only inside the backup) need an explicit statement.
3. **Plugin trust model is unstated.** Anything writable into `custom-modules/` executes with web-server privileges by design — collision guards prevent accidents, not malware. If a zip-upload/admin updater ever lands, it needs zip-slip protection and integrity verification. One sentence in the spec would set expectations.
4. **Dual-autoloader edge**: custom modules may declare `CustomModules\{Name}` (preferred) or `Modules\{Name}` (per spec §3.2/§9); Composer's PSR-4 for `Modules\` is registered before the custom autoloader, so mixed-name resolution works but is subtle. Document the precedence rule to prevent future confusion.

**Impact**: Unrecoverable loss scenarios that the "system superadmin backups" section implicitly promises to cover.

**Affected artifacts**: Spec §5.1 (scope + encryption statement), Plan (backup-scope task or note), `docs/modular-architecture.md` (Task 7 content).

---

### F11 — Test coverage gaps

**Severity**: Low · **Tag**: Verified (test file review; suites pass)

**Observed**:
- Passing today: `ModuleAutoloadTest` 10 passed / 132 assertions; `ModuleServiceProviderTest` 11 passed / 31 assertions; `NativeModuleCommandTest` 6 passed / 60 assertions (verified during this review, parallel mode).
- **No test asserts that a module shipping a provider file declares it in `module.json`** — the F2 bug class passes the entire current suite.
- `ModuleServiceProviderTest` "discovers all 15 local modules" checks a representative 15-item list; the name implies completeness it does not provide.
- No test covers cold-state discovery (no `bootstrap/cache/modules.php`), double-registration safety (composer package discovery + manifest discovery both active), or `module:cache`/`module:sync` consistency.

**Affected artifacts**: Tests; plan verification gates.

**Proposed change**: add the manifest-completeness test (F2), a cold-state discovery test that deletes/injects the cache, a double-registration guard test, and rename/extend the representative-list test.

---

### F12 — Vestigial module `composer.json` files and schema gaps

**Severity**: Low · **Tag**: Verified

**Evidence / problems**:
- All 60 modules still contain `composer.json` (some with classmap autoload and `extra.laravel.providers`). Post-Task-1 they are inert artifacts that mislead audits (they helped sustain F1's stale metadata).
- `resources/schemas/module.json` does not model the new architecture's needs: no `source` property, no UI-group field (F3), `providers` optional (F2), and `category` remains a freeform string with default "General" while the product needs a fixed nine-slug vocabulary.

**Affected artifacts**: 60 `composer.json` files, manifest JSON schema, Plan (cleanup task or explicit "keep" note).

---

## 4. Commit review (plan-related)

| Commit | Message | Assessment |
| :--- | :--- | :--- |
| `dfb24332` | docs: add 4.x modular architecture rethink design specification | First spec revision. Conventional, focused. |
| `2aafb5e6` | docs: document Option A flat module structure decision in 4.x spec | Good: ADR-style decision recording (flat layout + metadata grouping rationale). |
| `425a84eb` | docs: add custom module collision guards section to 4.x spec | Good idea; the gaps now live in Task 3 (F6) and spec §8 refinement. |
| `1f488767` | docs: add 4.x modular architecture implementation plan | Initial plan. |
| `a9a5ab21` | docs: establish DDD modular monolith architecture and scaffolding in docs, skills, and plans | Broad docs/skill sync; appropriate scope. |
| `17399bdc` | docs: update spec and plan for custom-module exclusive scaffolding | Good iteration discipline. |
| `c8d31284` | refactor: replace 60 composer path repos with direct psr-4 autoloading | Solid test for what it covers (0 repos/requires, 60 mappings). **Incomplete**: lockfile/vendor/installed state untouched (F1). Message is accurate; completeness claim in plan is not. |
| `728b1816` | feat: implement multi-source module discovery, dynamic booting, and custom module scaffolding with documentation | Good implementation quality (clean classes, docblocks, tests green). Touched **14 files** vs. the plan's 9-file list (plan understates scope). The two missing `providers` keys (F2) slipped through. |
| `b28f8ebd` | docs: update implementation plan marking tasks 1 and 2 complete | Task 2 completion defensible with the noted gaps; **Task 1 completion inaccurate** while the lockfile state lags (F1). |

General observation: commit granularity, conventional-message format, and the docs-with-code habit all match the project conventions well.

---

## 5. Verification evidence log

Commands executed during this review (read-only; no code or Composer state was modified):

| Check | Command (abridged) | Observed result |
| :--- | :--- | :--- |
| Branch/HEAD | `git branch --show-current` / `git log --oneline` | `4.x` @ `b28f8ebd` |
| composer.json clean | read `composer.json` | no `repositories`; 60 PSR-4 mappings; 0 module requires |
| Lockfile stale | `grep -c '"name": "tallpbx/module' composer.lock` | **60** |
| Installed metadata stale | parse `vendor/composer/installed.json` | **60** `tallpbx/module-*` packages |
| Vendor sources gone | `ls vendor/tallpbx \| wc -l` | **0** |
| Discovery caches stale | `grep -c "…ModuleServiceProvider" bootstrap/cache/packages.php` | **60** |
| Missing providers keys | loop `grep -q '"providers"' app-modules/*/module.json` | `backups`, `email-connector` missing |
| Cache lacks providers | inspect `bootstrap/cache/modules.php` for both modules | no `providers` key in either entry |
| Missing category | loop `grep -q '"category"' app-modules/*/module.json` | `backups`, `email-connector` missing (58/60 declare one) |
| Category vocabulary | `grep -h '"category"' app-modules/*/module.json \| sort \| uniq -c` | 7 legacy values (Advanced/Call Features/Monitoring/PBX/PBX Features/Provisioning/System) |
| Backups routes today | `php artisan route:list --name=panel.backups` | 4 routes (via legacy discovery caches) |
| Email routes today | `php artisan route:list --name=panel.email` | 6 routes (via legacy discovery caches) |
| Cache wiring | grep `modules.php`/`module:clear` references repo-wide | only the commands themselves; not wired into `optimize:clear` |
| Targeted tests | `php artisan test --compact --parallel <file>` | ModuleAutoloadTest 10/132; ModuleServiceProviderTest 11/31; NativeModuleCommandTest 6/60 — all pass |
| modules table columns | read migration `2026_06_29_000001_create_modules_table.php` | no `source`, no `category` columns |
| Lifecycle paths | read `ModuleLifecycleService.php` | composer-first uninstall; path-repo-era restore |
| Schema contract | read `resources/schemas/module.json` | `providers` optional; no source/ui_group; `category` freeform |

**Not done (out of scope for this review)**: running `composer update`/`composer install --dry-run`, any destructive lifecycle command, the full test suite, or any code modification. Runtime outcomes marked "Analyzed" (F7 items 1–2) should be confirmed empirically when the fixes are implemented.

---

## 6. Proposed change matrix (spec vs. plan vs. code)

| Finding | Spec document change | Plan document change | Code change |
| :--- | :--- | :--- | :--- |
| F1 | Note lockfile regeneration in the migration section | New task / Task 1 revision + stronger verification gate (`composer validate --strict`, no-symlink assertion) | Regenerate `composer.lock`/installed state; cold-state re-verification |
| F2 | Manifest contract in §3.2/§4.1 (providers + fallback) | Amend Tasks 2/4 scope; Task 4 precondition | Add `providers` to both manifests; discovery fallback; completeness test |
| F3 | Fix §6.1 to cover all 60 modules exactly once | Redesign Task 6 around an explicit taxonomy source | `ui_group` in manifests/schema + DB column/migration + `module:sync` + `ModulesList` + tests |
| F4 | Correct §4.1/§9 caching claims | Add cache-consistency task | Wire `module:sync`/`make:module` to cache invalidation; consider `optimize:clear` hook |
| F5 | Rewrite §4.1 to match the hybrid model | — (no plan change) | None (document current behavior) |
| F6 | Refine §8 degrade semantics; source-dependent translation policy sentence | Expand Task 3 (files, mechanisms, tests) | Remove placeholder; fail-soft dependencies; `SafeMigrator` guard; unify regex; route-audit |
| F7 | Align §5 narrative if referenced | Expand Task 5 to full lifecycle reconciliation | Rewrite lifecycle service; delete dead code; custom-deletion messaging + typed confirmation; decide 60 `composer.json` files |
| F8 | — (task-side gap) | Add tasks or explicit deferral notes (§2.2, §6.2, §7.3, lang, browser test) | Implement delete-extension UI + lang keys (if kept in scope) |
| F9 | — | Sweep during Tasks 3–7 | Fix three command docblocks |
| F10 | Extend §5.1 (custom-modules scope, encryption, trust model) | Backup-scope task or note | Include `custom-modules/` in backup/restore scope |
| F11 | — | Add gate items | Manifest-completeness, cold-state, double-registration tests |
| F12 | — | Cleanup task or "keep" note | Remove (or document) 60 module `composer.json` files; extend schema |

---

## 7. Open decisions required

1. **UI taxonomy source of truth (F3)**: `ui_group` in `module.json` synced to a DB column (recommended) vs. hardcoded mapping class vs. deriving from menu parents. Must be exactly one, with a 60/60 coverage test.
2. **Manifest `providers` contract (F2)**: make `providers` schema-required vs. convention fallback vs. both (recommended: fallback + completeness test + warning log).
3. **Fate of the 60 per-module `composer.json` files (F7/F12)**: delete (recommended) vs. keep for future standalone publishing (then document they are inert to the appliance).
4. **Custom modules in system backups (F10)**: include the `custom-modules/` directory in backup/restore scope (recommended) with size/security considerations, or explicitly exclude and document the risk.
5. **Custom module install/update lifecycle (F8)**: implement upload/update in 4.x or explicitly defer (§2.2 currently implies it exists).
6. **Cache reconciliation contract (F4)**: does `module:sync` become the single "make filesystem, cache, and DB agree" command? Does `make:module` auto-run it? Should `optimize:clear` include `module:clear`?
7. **Fail-soft dependency policy (F6)**: should a missing dependency disable only the affected module (recommended, log critical) for **all** sources, and apply identically on cold and cached paths?
8. **Legacy restore semantics (F7)**: how to restore modules already uninstalled under the old path-repo model before the lifecycle rewrite ships.

---

## 8. Review limitations

- Read-only review: no Composer operations, no destructive lifecycle commands, no code changes were executed; runtime failure modes marked "Analyzed" are logic-path conclusions that should be confirmed when fixed.
- Targeted test executions only (three relevant files); the full suite and browser suite were not run during this review.
- Line-level references reflect `4.x` @ `b28f8ebd`; subsequent commits may shift line numbers while findings remain valid if the underlying code is unchanged.
- This document deliberately does not modify the spec or the plan — it is the baseline for a cross-agent comparison before amendments are made.
