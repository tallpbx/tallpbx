# TallPBX Audit Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 28 findings of `docs/gpt-evaluation.md` plus the two code gaps its critical evaluation found inside reviewed modules, in nine independently shippable work packages (0–8). Remaining items that need a product decision, rather than code, are recorded under **Deferred decisions**.

**Architecture:** Keep the modular monolith. Each fix gives one operation one owner: services own complete state changes inside a transaction, middleware guards the authentication boundary once, shell helpers claim work atomically, and every destructive operation writes a journal outside the database it replaces. No new framework, no generic repositories, no compatibility shims for a pre-5.x layout.

**Tech Stack:** PHP 8.5, Laravel 13.42, Livewire 4.4, Pest 4, PHPUnit 12, Symfony Process 8.1, MariaDB 11 / SQLite in tests, Redis, systemd, Bash, Tailwind/DaisyUI 5.

**Spec:** `docs/gpt-evaluation.md` (the audit; every finding ID below refers to it) and the critical evaluation in this conversation, whose corrections are recorded in **Deviations** below. Read both before Task 1.

## Global Constraints

- **Branch:** work on `5.x`. Never branch from or merge into `main`/`master`. No `4.x` compatibility fallbacks.
- **After every code change:** `tpbx optimize:clear`. Root-caused failures in generated files are fixed by `tpbx permissions:repair --scope=generated`.
- **PHP formatting:** `./vendor/bin/pint --dirty` after PHP edits, `--dirty --test` before committing. Never run Pint on Blade, Markdown, JSON, or YAML only.
- **Tests:** targeted Pest file per task. Full suite (`tpbx test --compact --parallel`) once per package, never per step.
- **Panel-route tests:** `$this->artisan('module:sync --only-local'); $this->seed(AdminSeeder::class);` then attach the `Super Administrators` group — never hand-build a permission group.
- **Translations:** any new key must exist in **all** locale directories present in the module (built-in modules: `en`, `es`, `fr`) with identical key sets.
- **Changelog:** one `## [Unreleased]` entry per completed package, under `Added` / `Changed` / `Fixed` / `Security`.
- **PHP conventions:** `declare(strict_types=1)`, native type hints, and a PHPDoc block on every new class and method, with an inline comment wherever the intent is not obvious. PSR-12 throughout. This applies to every file this plan creates, not only to the ones whose steps show a snippet.
- **Never** mutate live `nftables` state. Firewall verification is `nft -c -f <file>` or mocks only.
- **No new Composer dependencies.** The test suite must stay runnable with the committed lockfile.

## Review Focus

Classes of input the spec implies but no single task's tests fully cover. Each line has a test pinned in the named task.

1. A disabled account reconnecting with a valid remember-me cookie, when the account was disabled *after* the cookie was issued — must not reach a panel page. (Task 1.2)
2. A CDR payload whose `tenant_id` channel variable disagrees with its `domain_name` — must never pick one arbitrarily. (Task 1.3)
3. A restore archive carrying a top-level file beside its directories in `app_files/` — must not vanish silently. (Task 2.4)
4. A digest batch where exactly one row violates a database constraint — the other rows must commit and the payload must stay recoverable. (Task 4.4)
5. A Git target that is a tag while the working tree sits on a branch — must update deliberately or refuse, never silently report zero incoming commits. (Task 3.1)

## Deviations from the audit

These are deliberate and supersede the audit's ordering where they conflict.

| Change | Reason |
|---|---|
| **Package 0 added** (T01 isolation + backup credentials) before every code fix | The project verifies on the appliance; today every suite run writes to production Redis DB 3. Building regression tests on top of that is unsafe. |
| **C05 moved from the audit's package 1 to package 4** with C03/C04 | All three edit `ExtensionsEdit.php` and `ExtensionService`. Two packages would edit one file twice. |
| **D01 re-rated P2** (first task of package 6) | It is a documentation/instruction hazard, not a tenant boundary or recovery defect, so it does not meet the audit's own P1 definition. It stays first in its own queue. |
| **Two findings added** the audit missed | Task 0.2: `mysqldump` receives the database password on the command line. Task 2.6: restore swallows every service-restart failure and reports `completed`. Both are inside modules the audit reviewed. |
| **Restore journal (Task 2.1) precedes request claiming (Task 2.2)** | "Already terminal" can only be answered by the journal once the database has been replaced. |
| **Tenant-relative CDR direction deferred** (see Deferred Decisions) | It needs a schema/query decision and a live cross-tenant bridged call to validate; shipping a half-defined direction model would be worse than quarantining. |

## Corrections applied after review

`docs/gemini-report-on-gpt-evaluation-and-deepseek-implementation.md` was checked against this plan and against the code at `80f17e5`. Its reproduced defects are applied below; its non-reproducing claims are recorded so they are not re-litigated.

| Correction | Defect it removed |
|---|---|
| Tasks 2.1 and 2.5 target `app-modules/backups/src/Providers/ModuleServiceProvider.php` | The plan named a provider file that does not exist |
| Task 4.4 also updates `CdrDrainSpoolCommand`, mapping spool files by row id and quarantining rejected ones | The spool drain would have deleted poison records once `write()` stopped throwing |
| Task 0.2 writes `$result->output` for the SQLite branch | SQLite backups would have produced an empty `database.sql` |
| Task 3.1 changes `commitsBehind(): ?int` in place and updates `GitUpdate` and its Blade partial | An invented `incomingCount()` left the original defect in place |
| Tasks 2.1-2.3 seed the journal, install its directory, widen the systemd sandbox, and recover a stale claim | Journal metadata was read but never written; no crash recovery |
| Task 2.3 registers both new commands in the architecture-test allowlist | `ModuleArchitectureTest` would have failed |
| Task 2.6 keeps maintenance mode active when required services fail | `finally` brought the app up over a half-restored runtime |
| Task 2.5 streams the snapshot and the import instead of buffering SQL in memory | Large restores would exhaust `memory_limit` |
| Task 2.7 uses decimal GB | Binary `2 * 1024 ** 3` contradicted the project's unit rule |
| Tasks 1.1-1.2 check the impersonating administrator and replay a recaller cookie | Only the impersonated user and the DB token were covered |
| Task 1.3 adds the dialplan `tenant_id` export and tenant-context parsing | Bridged B-legs and inbound calls would be quarantined |
| Task 4.1 routes writes through the module services and defers cache invalidation | Step 1 and Step 3 contradicted each other |
| Task 4.2 fixes `BaseEditComponent` so all edit forms are covered | Only `ExtensionsEdit` was fixed, while 36 components share the pattern |
| Task 4.3 uses the module dictionary prefix and the `admin.*` namespace | Violated the module translation convention |
| Tasks 6.2, 6.4, 6.5 and 7.3 cover the code fallback, the scaling guide, the seeder default, the remaining broken links and the scaffold test | Documentation remediation was incomplete |

Rejected after verification: that `phpunit.xml` omits `DB_CONNECTION`/`DB_DATABASE` (lines 34-35 force both); that `EnforcePanelLivewireActionPermissions::establishTenantContext()` admits a disabled user (it runs after `authenticate()`, which resolves through `PanelActor`); and that `ModuleServiceProvider::registerLivewire()` duplicates view-name resolution (it calls `Livewire::addNamespace` and resolves no view name). The report also reassigns nine finding IDs to unrelated topics; this plan continues to follow `docs/gpt-evaluation.md` as written.

### Second review round

The report was re-issued against the corrected plan. Its 13 issues were checked against the code at `80f17e5` and all 13 reproduced; they are applied in the tasks below. Three dispositions differ from the report:

| Report issue | Applied as | Difference |
|---|---|---|
| 1 — invalid `resource` type | `mixed $input` + `@param string\|resource\|null` | Consequence is a runtime `TypeError`, not a fatal compile error |
| 2 — scaffold assertions | Test scoped to what `make:module` emits, plus a separate built-in `routes/web.php` assertion | The report's Option B; the command is left unchanged |
| 3 — context naming | `DialplanContext::parseTenantId()` / `::internal()` | Reuses the existing helper; `default` means "no candidate", not `unresolved-context` |
| 4 — Livewire impersonation bypass | `PanelActor::resolve()` validates the origin administrator | As proposed, with a Livewire-level test |
| 5 — `hydrate()` ordering | Adds `updatedTenantId()` beside `hydrate()` | `updating` runs before Livewire assigns the value, so the report's `updatingTenantId()` would be a no-op; `updated` runs immediately after and before the action |
| 6 — reflection, snapshot, test path | Rename propagated, snapshot exclusions added, `tests/Feature/Backups` corrected | As proposed |
| 7 — post-import failure | `catch` writes the journal first, then reconciles in its own `try/catch` | As proposed, plus a regression test |
| 8 — preflight ordering and arity | Space check after the archive check; trailing nullable constructor arguments | As proposed; the reported "test breaks" is a warning, not a failure |
| 9 — pipeline, steps shape, mocks | `runPipeline()` resolves the target; `collect(...)->firstWhere()`; `->once()` mocks updated | As proposed |
| 10 — `BelongsToTenant` context | `TenantManager::setTenantId()` in `beforeEach` | As proposed |
| 11 — `Unit/Config` binding | Extends `tests/Feature/Config/FreeswitchConfigTest.php` | As proposed |
| 12 — `rg` unavailable | `grep -rnE` in all six verification steps | As proposed |
| 13 — link checker | Matches every relative link, resolved per file | As proposed |

Three gaps the report did not list are also closed: the journal directory is added to the upgrade path rather than only to the installer (Task 2.1); Task 6.2 refreshes the existing fallback assertion in the same step; and Task 2.3 uses the project's `#[Signature]`/`#[Description]` command style.

### Third review round

`docs/gpt-report-on-deepseek-implementation-plan.md` reviewed this plan against the agreed evaluation rather than against a previous draft. All six of its findings reproduced, and four defects it did not list were also found. Dispositions that change its proposals:

| Report finding | Applied as | Difference |
|---|---|---|
| 1 — CDR ownership and direction | Task 1.3 drops domain assignment entirely; new Task 1.4 implements tenant-relative direction; Task 4.4 makes `tenant_id` write-once on replay | The report left direction deferred; it is now a task, because C02's acceptance cannot pass without it |
| 2 — restore limits | Task 2.5 adds a whole-operation deadline and removes the idle timeout from file-writing commands; new Task 2.8 implements the 2 GB upload ceiling | The report proposed measuring bytes for progress; the simpler correct fix is to stop using an output-based timer on a command that writes no output |
| 3 — disk preflight | Task 2.7 requires `workingSet + reserve` per affected filesystem | As proposed |
| 4 — journal ownership | Task 2.1 restores a root-only journal; the queue writes marker metadata and the helper seeds the journal | As proposed |
| 5 — service health | Task 2.6 adds expected-state recording and four bounded functional probes | As proposed |
| 6 — verification schedule | Execution section drops the per-package full suite and the Package 6 browser run | As proposed |

The report's claim that `ripgrep` is installed was re-checked and is false in this workspace (`command -v rg` returns nothing; neither `/usr/bin/rg` nor `/usr/local/bin/rg` exists), so the `grep -rnE` steps stay. Four defects it did not list are also fixed: `backups:restore-status` becomes a bootless script because every Artisan command boots `ModuleState` and therefore the database; the dispatcher uses `systemctl list-units` because `is-active` does not accept globs; Task 4.1 no longer names a nonexistent `SipAccountServiceInterface::find`; and the Global Constraints now state the repository's PHP conventions.

## File Structure

| Path | Responsibility | Task |
|---|---|---|
| `phpunit.xml` | Test environment isolation, coverage scope | 0.1, 5.1 |
| `tests/Feature/TestEnvironmentSafetyTest.php` | Tripwire for every forced test setting | 0.1 |
| `app-modules/backups/src/Services/BackupService.php` | Backup archive production; dump must leave credentials off argv | 0.2 |
| `app/Support/PanelActor.php` | Single actor-resolution choke point | 1.1 |
| `app/Http/Middleware/AuthPanelMiddleware.php` | Page-request authentication boundary | 1.1 |
| `app/Observers/AccountEnablementObserver.php` | Remember-token rotation on disable | 1.2 |
| `app/Services/Cdr/CdrPayloadParser.php` | CDR attribution and quarantine | 1.3 |
| `app-modules/backups/src/Services/RestoreJournal.php` | Authoritative restore state outside the database | 2.1 |
| `scripts/resources/tallpbx-restore-dispatch` | Atomic request claiming and per-request failure isolation | 2.2 |
| `app/Console/Commands/Backups/ReconcileRestoreJournalCommand.php` | Journal → `restore_operations` projection | 2.3 |
| `scripts/resources/tallpbx-restore-status` | Database-free restore status for operators (bootless PHP) | 2.3 |
| `app-modules/backups/src/Services/RestoreOperationExecutor.php` | Destructive restore phases | 2.3–2.7 |
| `app-modules/backups/src/Services/RestoreDeadline.php` | Whole-operation time budget | 2.5 |
| `app-modules/backups/src/Services/SymfonyRestoreCommandRunner.php` | Bounded subprocess execution | 2.5 |
| `app/Services/Appliance/RuntimeServiceReconciler.php` | One service inventory for restore and update | 2.6, 3.3 |
| `app/Services/GitUpdateService.php` | Ref resolution, pipeline, rollback | 3.1–3.3 |
| `app-modules/extensions/src/Services/ExtensionSaveService.php` | Transaction-backed extension aggregate | 4.1 |
| `app-modules/extensions/src/Services/ExtensionService.php` | Tenant-scoped uniqueness and membership | 4.2, 4.3 |
| `app/Services/Cdr/CdrBatchWriter.php`, `CdrRedisIngest.php` | Poison-record isolation | 4.4 |
| `app/Console/Commands/CdrDrainSpoolCommand.php` | Spool drain keeps rejected records instead of unlinking them | 4.4 |
| `app/Support/BaseEditComponent.php` | Tenant initialization shared by every edit form | 4.2, 8.1 |
| `app/Services/Xml/DialplanXmlBuilder.php` | Tenant identity export for dialplan-created legs | 1.3 |
| `app/Console/Commands/PbxLoadTestSeedCommand.php` | Seeder realm default | 6.4 |
| `scripts/resources/tall.sh`, `scripts/tallpbx-queue.service` | Journal directory and systemd sandbox | 2.1 |
| `docs/*.md`, `AGENTS.md`, `.agents/skills/*` | Documentation corrections and journeys | 6.x, 7.x |

---

## Package 0 — Verification safety

Do this package first. Nothing else is verified until it lands.

### Task 0.1: Isolate the CDR Redis connection in tests (T01)

**Files:**
- Modify: `phpunit.xml` (`<php>` block)
- Modify: `tests/Feature/TestEnvironmentSafetyTest.php:368-379`
- Modify: `tests/Feature/Console/CdrHealthCommandTest.php:71-90`

**Interfaces:**
- Produces: test runs resolve `config('database.redis.cdr.database') === '12'` and `config('database.redis.default.url') === ''`.

- [ ] **Step 1: Extend the existing Redis tripwire test**

```php
it('runs tests against the dedicated Redis test workspaces', function (): void {
    expect((string) config('database.redis.default.database'))->toBe('15')
        ->and((string) config('database.redis.cache.database'))->toBe('14')
        ->and((string) config('database.redis.session.database'))->toBe('13')
        ->and((string) config('database.redis.cdr.database'))->toBe('12')
        ->and(config('database.redis.default.url'))->toBe('')
        ->and((string) config('session.connection'))->toBe('session')
        ->and((string) config('database.redis.options.prefix'))->toBe('tallpbx-test-');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php --filter="dedicated Redis"`
Expected: FAIL — `cdr.database` is the appliance value (`3`), `url` is `null`.

- [ ] **Step 3: Force the two missing settings in `phpunit.xml`**

```xml
<env name="REDIS_CDR_DB" value="12" force="true"/>
<env name="REDIS_URL" value="" force="true"/>
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php --filter="dedicated Redis"`
Expected: PASS. `RedisManager` only applies a URL when it is truthy (`ConfigurationUrlParser::parseConfiguration` returns early on falsy), so an empty value removes the override without breaking connections.

- [ ] **Step 5: Make the health test clean up on assertion failure**

Wrap the body of `it('fails when the stream backlog crosses the warning threshold', …)` in `try { … } finally { $redis->del($stream); rmdir($spool); }` and move both `xadd` calls inside the `try`.

- [ ] **Step 6: Verify the whole file plus the CDR health suite**

Run: `./vendor/bin/pest tests/Feature/Console/CdrHealthCommandTest.php tests/Feature/TestEnvironmentSafetyTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add phpunit.xml tests/Feature/TestEnvironmentSafetyTest.php tests/Feature/Console/CdrHealthCommandTest.php
git commit -m "test(cdr): isolate the CDR Redis database from the appliance in tests"
```

### Task 0.2: Keep the backup database password off the command line

**Files:**
- Modify: `app-modules/backups/src/Services/BackupServiceInterface.php:50`
- Modify: `app-modules/backups/src/Services/BackupService.php:34-37`, `:251-301`
- Test: `app-modules/backups/tests/BackupServiceTest.php`

**Interfaces:**
- Consumes: `App\Contracts\ProcessRunner::run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): App\Support\ProcessResult` (bound in `AppServiceProvider:185`).
- Produces: `public function buildDumpCommand(?string $destinationPath = null): array` — argv only, never a shell string, never a password.

- [ ] **Step 1: Write the failing test**

```php
it('never puts the database password on the dump command line', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'tallpbx',
            'username' => 'tallpbx', 'password' => 'se cret',
        ],
    ]);

    $argv = app(\Modules\Backups\Services\BackupServiceInterface::class)->buildDumpCommand('/tmp/dump.sql');

    expect($argv[0])->toBe('mysqldump')
        ->and(implode(' ', $argv))->not->toContain('se cret')
        ->and($argv)->toContain('--result-file=/tmp/dump.sql');
});

it('persists the SQLite dump from the command output', function (): void {
    // sqlite3 writes its dump to stdout and accepts no --result-file, so the
    // runner output must become database.sql or the backup is empty.
    config(['database.default' => 'sqlite']);
    $this->app->instance(ProcessRunner::class, new FakeProcessRunner(output: 'CREATE TABLE x (id);'));

    $tempDir = sys_get_temp_dir().'/tallpbx-dump-'.Str::random(8);
    File::ensureDirectoryExists($tempDir);

    app(BackupServiceInterface::class)->dumpDatabase($tempDir);

    expect(file_get_contents($tempDir.'/database.sql'))->toBe('CREATE TABLE x (id);');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest app-modules/backups/tests/BackupServiceTest.php --filter="password on the dump"`
Expected: FAIL — `buildDumpCommand()` returns a string.

- [ ] **Step 3: Change the interface and implementation to argv plus `MYSQL_PWD`**

`buildDumpCommand(?string $destinationPath = null): array` returns `['sqlite3', $dbPath, '.dump']` for sqlite, otherwise `['mysqldump', '--host=…', '--port=…', '--user=…', '--single-transaction', '--quick', '--routines', '--triggers', '--result-file='.$destinationPath, $database]` with no `-p` argument. `dumpDatabase(string $tempDir)` becomes:

```php
$connection = config('database.connections.'.config('database.default'));
$isSqlite = ($connection['driver'] ?? '') === 'sqlite';

$result = $this->runner->run(
    $this->buildDumpCommand($isSqlite ? null : $tempDir.'/database.sql'),
    env: $isSqlite ? [] : ['MYSQL_PWD' => (string) ($connection['password'] ?? '')],
);

if (! $result->ok()) {
    throw new RuntimeException('Database dump failed with exit code '.$result->exitCode);
}

// sqlite3 accepts no --result-file and writes the dump to stdout, so its
// output must be written here. mysqldump already wrote the file itself.
if ($isSqlite) {
    File::put($tempDir.'/database.sql', $result->output);
}
```

Do not write `$result->output` for mysqldump: `--result-file` streams to disk and avoids holding the dump in memory. Do **not** skip it for sqlite — that branch has no file-writing flag at all. Delete the `exec()` call and the string-replacement logic.

- [ ] **Step 4: Add the injection and update the stale test at `BackupServiceTest.php:315`**

Constructor becomes `__construct(FileStoreServiceInterface $fileStoreService, MediaStorageServiceInterface $mediaStorage, private readonly ProcessRunner $runner)`; replace the string assertions with argv assertions.

- [ ] **Step 5: Run the backups suite**

Run: `./vendor/bin/pest app-modules/backups/tests`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app-modules/backups/src/Services/BackupService.php app-modules/backups/src/Services/BackupServiceInterface.php app-modules/backups/tests/BackupServiceTest.php
git commit -m "fix(backups): pass the database password through the environment, not argv"
```

---

## Package 1 — Account and record ownership

### Task 1.1: Fail closed for disabled accounts (C01)

**Files:**
- Modify: `app/Support/PanelActor.php:26-31`
- Modify: `app/Http/Middleware/AuthPanelMiddleware.php:30-51`
- Modify: `app/Http/Middleware/EnforcePanelLivewireActionPermissions.php` (`authenticate()`)
- Test: `tests/Feature/Auth/DisabledAccountEnforcementTest.php` (new)

**Interfaces:**
- Produces: `PanelActor::resolve(): Admin|User|null` returns `null` for a disabled actor, which every gate and policy already consumes.
- Consumes: `ImpersonationServiceInterface::stop(): void`, `ImpersonationService::SESSION_ORIGINAL_ADMIN_ID`.

- [ ] **Step 1: Write the failing tests**

```php
it('rejects a disabled administrator mid-session', function (): void {
    $admin = grantAdminPermissions(permissions: ['panel.dashboard.view']);

    $this->actingAs($admin, 'admin')->get(route('panel.dashboard'))->assertOk();

    $admin->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'))
        ->assertSessionHasErrors('email');
    expect(PanelActor::resolve())->toBeNull();
});

it('rejects a disabled tenant user and makes every permission check fail closed', function (): void {
    // module:sync + AdminSeeder bootstrap, tenant user attached to the tenant.
    $this->actingAs($user, 'web');
    expect(PanelActor::can('extensions.view'))->toBeTrue();

    $user->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
    expect(PanelActor::resolve())->toBeNull()
        ->and(PanelActor::can('extensions.view'))->toBeFalse();
});

it('still lets an administrator stop impersonating a disabled user', function (): void {
    // Administrator impersonates a tenant user, the user is disabled, then the
    // administrator posts to panel.impersonation.stop.
    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);
    $user->update(['enabled' => false]);

    $this->post(route('panel.impersonation.stop'))->assertRedirect();
    expect(Auth::guard('admin')->check())->toBeTrue();
});

it('ends impersonation when the impersonating administrator is disabled', function (): void {
    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $admin->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('admin')->check())->toBeFalse()
        ->and(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID))->toBeNull();
});

it('rejects a disabled impersonating administrator on a Livewire action', function (): void {
    // Livewire updates never pass through AuthPanelMiddleware; they authenticate
    // through PanelActor::resolve(), which must therefore check the origin too.
    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $admin->update(['enabled' => false]);

    expect(PanelActor::resolve())->toBeNull()
        ->and(PanelActor::can('extensions.view'))->toBeFalse();
});
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `./vendor/bin/pest tests/Feature/Auth/DisabledAccountEnforcementTest.php`
Expected: FAIL — a disabled account keeps reaching `panel.dashboard`.

- [ ] **Step 3: Reject disabled actors in `PanelActor`**

`resolve()` is the only path Livewire uses — `EnforcePanelLivewireActionPermissions::authenticate()` calls it directly and never runs `AuthPanelMiddleware` — so the impersonation branch must validate the administrator behind the session, not only the impersonated user:

```php
public static function resolve(): Admin|User|null
{
    if (self::isImpersonating()) {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->enabled) {
            return null;
        }

        // The impersonated user can stay enabled while the supervising
        // administrator is disabled mid-session. Livewire never passes through
        // AuthPanelMiddleware, so the origin is checked here as well.
        $admin = Admin::query()->find(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID));

        return $admin !== null && $admin->enabled ? $user : null;
    }

    $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

    return ($actor instanceof Admin || $actor instanceof User) && $actor->enabled ? $actor : null;
}
```

Leave `isImpersonating()` untouched — it reads the session, not the actor. Do not add a second enablement helper: `resolve() === null` is the contract every caller already honors.

- [ ] **Step 4: Reject disabled sessions in `AuthPanelMiddleware`**

Keep the `panel.impersonation.stop` early-return first, so a supervisor can always end impersonation. Then replace the impersonation early-return with an explicit check that covers **both** actors — the impersonated user *and* the administrator behind the session:

```php
if (app(ImpersonationServiceInterface::class)->isImpersonating()) {
    $originalAdmin = Admin::query()->find(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID));

    // A supervisor disabled mid-session must not keep acting through the
    // impersonated user: end the session instead of continuing as that user.
    if ($originalAdmin === null || ! $originalAdmin->enabled) {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login')->withErrors(['email' => __('auth.disabled')]);
    }

    // A disabled target ends only the impersonation; the administrator
    // continues in their own session.
    if (Auth::guard('web')->user()?->enabled === false) {
        app(ImpersonationServiceInterface::class)->stop();
    } else {
        return app(ScopeTenant::class)->handle($request, $next);
    }
}

foreach (['admin', 'web'] as $guard) {
    $account = Auth::guard($guard)->user();

    if ($account !== null && ! $account->enabled) {
        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login')->withErrors(['email' => __('auth.disabled')]);
    }
}
```

The guard loop must run **after** the impersonation handling (so a disabled administrator is caught before any guard short-circuit) and `PanelActor::resolve()` must apply the same rule, otherwise the Livewire path and every Gate check still see the disabled actor.

- [ ] **Step 5: Reject disabled actors on the Livewire update endpoint**

In `EnforcePanelLivewireActionPermissions::authenticate()`, throw `AuthenticationException` when `PanelActor::resolve()` is `null`, matching the existing null-actor behavior.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Auth/DisabledAccountEnforcementTest.php tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/PanelActor.php app/Http/Middleware/AuthPanelMiddleware.php app/Http/Middleware/EnforcePanelLivewireActionPermissions.php tests/Feature/Auth/DisabledAccountEnforcementTest.php
git commit -m "fix(auth): fail closed for disabled accounts on page and Livewire requests"
```

### Task 1.2: Kill remember-me restoration when an account is disabled (C01)

**Files:**
- Create: `app/Observers/AccountEnablementObserver.php`
- Modify: `app/Providers/AppServiceProvider.php:244-245`
- Test: `tests/Feature/Auth/DisabledAccountEnforcementTest.php`

**Interfaces:**
- Produces: disabling an `Admin` or `User` rotates `remember_token` before the update commits.

- [ ] **Step 1: Write the failing test** (append to Task 1.1's file)

```php
it('rotates the remember token when an account is disabled', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $before = $admin->remember_token;

    $admin->update(['enabled' => false]);

    expect($admin->fresh()->remember_token)->not->toBe($before)
        ->and($admin->fresh()->remember_token)->toHaveLength(60);
});

it('leaves the remember token alone when other fields change', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $before = $admin->remember_token;

    $admin->update(['name' => 'Renamed']);

    expect($admin->fresh()->remember_token)->toBe($before);
});

it('rejects a remember-me cookie issued before the account was disabled', function (): void {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach(Tenant::factory()->create());

    $login = $this->post(route('panel.login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recaller = collect($login->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === Auth::guard('web')->getRecallerName());

    expect($recaller)->not->toBeNull();

    $user->update(['enabled' => false]);

    $this->flushSession()
        ->withCookie($recaller->getName(), $recaller->getValue())
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'));

    expect(Auth::guard('web')->check())->toBeFalse();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Auth/DisabledAccountEnforcementTest.php --filter="remember token"`
Expected: FAIL — the token is unchanged.

- [ ] **Step 3: Implement the observer**

```php
final class AccountEnablementObserver
{
    public function updated(Model $model): void
    {
        if (! $model->wasChanged('enabled') || $model->enabled) {
            return;
        }

        $model->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
```

`saveQuietly()` prevents recursion; `forceFill` writes a non-fillable column.

- [ ] **Step 4: Register it for both guards**

Next to `User::observe(DashboardStatsObserver::class)` in `AppServiceProvider::boot()`:

```php
User::observe(AccountEnablementObserver::class);
Admin::observe(AccountEnablementObserver::class);
```

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Auth/DisabledAccountEnforcementTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/AccountEnablementObserver.php app/Providers/AppServiceProvider.php tests/Feature/Auth/DisabledAccountEnforcementTest.php
git commit -m "fix(auth): rotate remember tokens when an account is disabled"
```

### Task 1.3: Resolve CDR ownership unambiguously (C02)

**Files:**
- Modify: `app/Services/Xml/DialplanXmlBuilder.php`
- Modify: `app/Services/Cdr/CdrPayloadParser.php:66-76`, add `resolveTenantId()`
- Test: `tests/Feature/Services/CdrTenantAttributionTest.php` (new)
- Test: `tests/Feature/Services/DialplanTenantExportTest.php` (new)

**Interfaces:**
- Consumes: two identity sources — the directory user variable (`DirectoryXmlBuilder`, `<variable name="tenant_id" …>`, L241) for registered A-legs, and the dialplan export added in Step 3 for every other leg.
- Produces: `parse()` returns `null` (quarantine) for unknown, ambiguous, or conflicting identity; never selects an arbitrary domain row.

- [ ] **Step 1: Write the failing tests**

```php
dataset('cdr attribution', [
    'captured variable on a shared domain' => [fn () => 2, 2, 2],
    'captured variable on a dedicated domain' => [fn () => 1, 1, 1],
    'shared domain, no captured identity' => [fn () => null, 2, null],
    'variable names a missing tenant' => [fn () => 999999, 1, null],
    'domain disagrees with the captured variable' => [fn () => 2, 1, null],
]);

it('attributes a CDR only from a captured identity', function (Closure $variable, int $tenantCount, ?int $expected) {
    // Create $tenantCount tenants sharing domain "shared.test"; tenant 1 also
    // owns "dedicated.test". Ownership comes only from the channel capture
    // (tenant_id variable or dialplan context); the domain is a conflict check,
    // never an assignment source.
})->with('cdr attribution');

it('attributes an inbound call from its tenant dialplan context', function (): void {
    // Two tenants share every domain, so the domain alone is ambiguous and the
    // call arrives on an IP with the public context.
    twoTenantsSharing('shared.test');

    $row = parseCdr(domain: '10.0.0.9', context: 'tenant_2_public');

    expect($row['tenant_id'])->toBe(2);
});

it('attributes an internal extension call from its tenant context', function (): void {
    twoTenantsSharing('shared.test');

    $row = parseCdr(domain: '10.0.0.9', context: 'tenant_1_internal');

    expect($row['tenant_id'])->toBe(1);
});

it('treats a non-tenant context as absent, never as a conflict', function (): void {
    // The captured fixtures carry context "default"; it must not quarantine a
    // payload whose domain resolves cleanly.
    expect(parseCdr(domain: 'dedicated.test', context: 'default')['tenant_id'])->toBe(1);
});

it('quarantines a payload whose dialplan context and tenant variable disagree', function (): void {
    expect(parseCdr(context: 'tenant_1_public', tenantVariable: 2))->toBeNull();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Services/CdrTenantAttributionTest.php`
Expected: FAIL — the shared-domain case parses and attributes to the first row.

- [ ] **Step 3: Export the tenant identity from every tenant dialplan context**

Directory variables are set only on the authenticating A-leg; a bridged B-leg and a gateway call never receive them. Add the export beside the existing caller-ID export in `DialplanXmlBuilder` (L87 shows the pattern), so every leg carries the identity:

```php
$writer->selfClosingTag('action', [
    'application' => 'export',
    'data' => 'tenant_id='.$tenantId,
], 10);
```

Test it in `DialplanTenantExportTest`: render the dialplan for a tenant and assert `data="tenant_id=<id>"` appears in the context named by `DialplanContext::internal($id)` (`tenant_<id>_internal`) **and** `DialplanContext::public($id)` (`tenant_<id>_public`). Internal contexts are never named `tenant_<id>` — [DialplanContext.php:27](../app/Services/DialplanContext.php#L27) already encodes the real shape. This is the difference between attributing a B-leg and quarantining it.

- [ ] **Step 4: Replace the domain lookup with a capture-only resolution**

Add `private function resolveTenantId(array $variables, array $profile): ?int`:

1. Read `variables.tenant_id`; a numeric value names the **only** possible candidate.
2. Read `variables.context` or `callflow[0].caller_profile.context` and pass it to `app(DialplanContext::class)->parseTenantId($context)`. A returned id is a candidate; `null` means "no candidate" and must never quarantine — the captured fixtures carry `"context": "default"`.
3. When both named a candidate and they disagree → quarantine (`conflicting-identity`).
4. **The domain never assigns ownership.** Resolve it only as a conflict check: when the domain maps to exactly one tenant and that tenant differs from the candidate → quarantine (`conflicting-identity`). A domain shared by several tenants carries no information and is ignored.
5. No candidate at all → quarantine (`missing-identity`). This is deliberate: after Step 3 exports `tenant_id` on every dialplan leg and the directory already exports it for registered A-legs, a payload without one means a broken producer, and guessing from a domain that may have moved is the defect C02 exists to remove.

Keep the lookup uncached (the existing comment explains why). Log `reason` as `conflicting-identity` or `missing-identity` alongside `domain` and `context`. Do not add an `unresolved-context` reason: a non-tenant context is normal, not an error. Keep the existing `null` → quarantine contract in `parse()`.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Services/CdrTenantAttributionTest.php tests/Feature/Services/DialplanTenantExportTest.php tests/Feature/Services/CdrRedisIngestDigestTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Xml/DialplanXmlBuilder.php app/Services/Cdr/CdrPayloadParser.php tests/Feature/Services/CdrTenantAttributionTest.php tests/Feature/Services/DialplanTenantExportTest.php
git commit -m "fix(cdr): export tenant identity to every leg and quarantine ambiguity"
```

### Task 1.4: Give every CDR a tenant-relative direction (C02)

**Files:**
- Modify: `app-modules/call-detail-records/src/Models/Cdr.php`
- Modify: `app-modules/call-detail-records/resources/views/cdr-list.blade.php:40`, `:56`
- Modify: `app-modules/call-detail-records/resources/views/cdr-detail.blade.php:20-21`
- Test: `app-modules/call-detail-records/tests/CdrTenantDirectionTest.php` (new)

**Interfaces:**
- Produces: `Cdr::tenantDirection(): string` — `internal`, `inbound` or `outbound`, relative to `$this->tenant_id`. It reads the peer leg through `withoutGlobalScope('tenant')` but takes **only** the peer's `tenant_id`; no other peer field becomes reachable through this method.

- [ ] **Step 1: Write the failing tests**

```php
it('treats a same-tenant bridge as internal for both legs', function (): void {
    [$a, $b] = bridgedPair(tenant: $this->tenant);

    expect($a->tenantDirection())->toBe('internal')
        ->and($b->tenantDirection())->toBe('internal');
});

it('reports a cross-tenant bridge as outbound for the caller and inbound for the callee', function (): void {
    [$a, $b] = bridgedPair(tenant: $this->tenant, peerTenant: $this->otherTenant);

    expect($a->tenantDirection())->toBe('outbound')
        ->and($b->tenantDirection())->toBe('inbound');
});

it('falls back to the FreeSWITCH direction for a leg with no peer', function (): void {
    // A gateway call has no bridge partner; the stored direction decides.
    expect(record(direction: 'inbound')->tenantDirection())->toBe('inbound')
        ->and(record(direction: 'outbound')->tenantDirection())->toBe('outbound');
});

it('never exposes the peer record across tenants', function (): void {
    [$a] = bridgedPair(tenant: $this->tenant, peerTenant: $this->otherTenant);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    expect(Cdr::query()->whereKey($a->b_leg_uuid)->exists())->toBeFalse()
        ->and($a->tenantDirection())->toBe('outbound');
});
```

- [ ] **Step 2: Run them and confirm failure**

Run: `./vendor/bin/pest app-modules/call-detail-records/tests/CdrTenantDirectionTest.php`
Expected: FAIL — the method does not exist.

- [ ] **Step 3: Derive direction from the peer's tenant, not from the leg label**

```php
public function tenantDirection(): string
{
    return $this->tenantDirection ??= $this->resolveTenantDirection();
}

private function resolveTenantDirection(): string
{
    $peer = $this->b_leg_uuid === null ? null : static::withoutGlobalScope('tenant')
        ->where('call_uuid', $this->b_leg_uuid)
        ->first(['id', 'tenant_id', 'leg']);

    if ($peer !== null && (int) $peer->tenant_id === (int) $this->tenant_id) {
        return 'internal';
    }

    if ($peer !== null) {
        // The A-leg created the bridge, so its tenant placed the call; the
        // B-leg received it. This is what makes an A-to-B call outbound in A
        // and inbound in B, which a per-leg direction column cannot express.
        return $this->leg === 'a' ? 'outbound' : 'inbound';
    }

    // No bridge partner (gateway call, voicemail deposit, failed call).
    return in_array($this->direction, ['inbound', 'outbound'], true)
        ? $this->direction
        : ($this->leg === 'a' ? 'outbound' : 'inbound');
}
```

Add a nullable `private ?string $tenantDirection = null;` memo property. Keep the stored `direction` column untouched: it is FreeSWITCH's channel direction, not the tenant-relative one, and the two differ for cross-tenant legs.

- [ ] **Step 4: Show the tenant-relative direction in the panel**

In `cdr-list.blade.php` replace the `$record->direction` badge with `$record->tenantDirection()`, and in `cdr-detail.blade.php` show it beside the stored channel direction with distinct labels (`admin.cdr_direction` for the tenant view, `admin.cdr_channel_direction` for the raw one). Add the new key to `app-modules/call-detail-records/lang/{en,es,fr}/admin.php` with the module's `cdr_` prefix.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest app-modules/call-detail-records/tests tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app-modules/call-detail-records/src/Models/Cdr.php app-modules/call-detail-records/resources/views app-modules/call-detail-records/lang app-modules/call-detail-records/tests/CdrTenantDirectionTest.php
git commit -m "feat(cdr): derive tenant-relative call direction from the paired leg"
```

---

## Package 2 — Recoverable restore lifecycle

Sequence matters inside this package: the journal precedes the claim logic, the projection follows the import, and the health gate follows the projection.

### Task 2.1: Keep authoritative restore state outside the database (O02)

**Files:**
- Create: `app-modules/backups/src/Services/RestoreJournal.php`
- Modify: `config/backup-storage.php`
- Modify: `app-modules/backups/src/Providers/ModuleServiceProvider.php`
- Modify: `app-modules/backups/src/Jobs/RestoreRunner.php`
- Modify: `scripts/resources/tall.sh`, `scripts/tallpbx-queue.service`, `scripts/resources/tallpbx-restore.service`
- Test: `app-modules/backups/tests/RestoreJournalTest.php` (new)

**Interfaces:**
- Consumes: `config('backup-storage.journal_root')`, `Modules\Backups\Models\RestoreOperation`.
- Produces:
  - `RestoreJournal::__construct(string $root)`
  - `RestoreJournal::write(string $operationId, array $entry): void` — atomic **and durable** (fsync the file and its directory, then `rename`)
  - `RestoreJournal::seed(string $operationId, array $metadata): void` — writes the full initial entry (`archive_path`, `archive_checksum`, `source_backup_id`, `scopes`, `started_at`, `requester` `{id, email, name}`) from the request marker, not from the model
  - `RestoreJournal::read(string $operationId): ?array`
  - `RestoreJournal::record(string $operationId, string $phase, array $attributes = []): void` — merges and stamps `phase_at`
  - `RestoreJournal::terminalStatus(string $operationId): ?string` — `'completed'`, `'failed'`, or `null`
  - `RestoreJournal::all(): array<string, array>` — every readable journal entry keyed by UUID

- [ ] **Step 1: Write the failing tests**

`beforeEach` creates `$this->root` under `sys_get_temp_dir()` and `$this->uuid` from `Str::uuid()`; `afterEach` deletes the directory.

```php
it('writes an entry atomically and reads it back', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->write($this->uuid, ['phase' => 'queued', 'archive_path' => '/x.tar.gz', 'scopes' => ['database']]);

    expect($journal->read($this->uuid))
        ->toMatchArray(['phase' => 'queued', 'archive_path' => '/x.tar.gz'])
        ->and(glob($this->root.'/*.tmp'))->toBe([]);
});

it('merges phases and reports a terminal status only when finished', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->write($this->uuid, ['phase' => 'queued']);
    expect($journal->terminalStatus($this->uuid))->toBeNull();

    $journal->record($this->uuid, 'importing');
    expect($journal->read($this->uuid)['phase'])->toBe('importing');

    $journal->record($this->uuid, 'completed', ['result' => 'ok']);
    expect($journal->terminalStatus($this->uuid))->toBe('completed')
        ->and($journal->read($this->uuid)['result'])->toBe('ok');
});

it('returns null for an unknown operation', function (): void {
    expect((new RestoreJournal($this->root))->read('00000000-0000-4000-8000-000000000000'))->toBeNull();
});

it('seeds the full operation metadata before the database is replaced', function (): void {
    $journal = new RestoreJournal($this->root);
    $journal->seed($this->uuid, [
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64),
        'source_backup_id' => null,
        'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
        'started_at' => now()->toIso8601String(),
    ]);

    expect($journal->read($this->uuid))->toMatchArray([
        'phase' => 'queued',
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64),
        'scopes' => ['database'],
        'requester' => ['id' => 7],
        'source_backup_id' => null,
    ]);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreJournalTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the journal**

Add `'journal_root' => env('TALLPBX_RESTORE_JOURNAL', '/var/lib/tallpbx/restore-journal')` to `config/backup-storage.php`. The journal stores one `<uuid>.json` per operation in a **root-only** directory (`0700 root:root`, files `0600 root:root`). One process writes it: the privileged restore helper. The web and queue processes never do — they hand metadata to the helper through the request marker instead (Step 5).

`write()` must be durable before a destructive phase, not merely atomic: `fopen($tmp, 'wb')`, `fwrite`, `fflush`, `fsync`, `fclose`, `chmod` 0600, `rename()` onto the target, then open the directory handle and `fsync` it so the rename itself survives an abrupt shutdown. `record()` reads, merges, stamps `phase_at` with `now()->toIso8601String()`, and writes. `seed()` takes the marker metadata and writes the initial `queued` entry. `terminalStatus()` returns `$entry['phase']` when it is `completed` or `failed`, otherwise `null`.

- [ ] **Step 4: Install the directory and widen the sandbox**

Next to the existing `install -d -m 0770 -o root -g www-data /var/lib/tallpbx/restore-requests` in `scripts/resources/tall.sh`, add `install -d -m 0700 -o root -g root /var/lib/tallpbx/restore-journal`. Add `/var/lib/tallpbx/restore-journal` to `ReadWritePaths=` in `scripts/resources/tallpbx-restore.service` only — not in `scripts/tallpbx-queue.service`, which must not be able to write journal state.

The installer alone is not enough: an existing appliance never re-runs `tall.sh`. Add the same `install -d` line to the upgrade path that `appliance:sync-host` and `tpbx permissions:repair` already use, so a host that updates rather than reinstalls creates the directory before its first restore. The service accounts must still be able to *read* the status view; that is a separate read-only projection, not write access to the directory.

- [ ] **Step 5: Carry the metadata on the request marker**

`RestoreRunner::handle()` runs as `www-data`, so it must not write the journal. Instead it writes the marker as JSON: `archive_path`, `archive_checksum`, `source_backup_id`, `scopes`, `requester` `{id, email, name}` read from the admin record, and `started_at`. The marker directory is already `0770 root:www-data`, so this preserves the unprivileged handoff while keeping journal state root-only. The helper seeds the journal from that payload in Task 2.2, then deletes the marker once the operation reaches a terminal phase.

- [ ] **Step 6: Bind it in the module provider**

```php
RestoreJournal::class => fn (): RestoreJournal => new RestoreJournal((string) config('backup-storage.journal_root')),
```

- [ ] **Step 7: Run the test**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreJournalTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add config/backup-storage.php app-modules/backups/src/Services/RestoreJournal.php app-modules/backups/src/Jobs/RestoreRunner.php app-modules/backups/src/Providers/ModuleServiceProvider.php scripts/resources/tall.sh scripts/tallpbx-queue.service scripts/resources/tallpbx-restore.service app-modules/backups/tests/RestoreJournalTest.php
git commit -m "feat(backups): record restore lifecycle in an external journal"
```

### Task 2.2: Claim restore requests atomically and acknowledge them (O01)

**Files:**
- Modify: `scripts/resources/tallpbx-restore-dispatch`
- Modify: `app/Console/Commands/Backups/RestoreOperationCommand.php:24-61`
- Test: `app-modules/backups/tests/RestoreDispatchTest.php` (new)

**Interfaces:**
- Consumes: `RestoreJournal::terminalStatus()`, `RestoreJournal::record()`.
- Produces: the dispatch script honours `TALLPBX_RESTORE_REQUEST_DIR` and `TALLPBX_SYSTEMCTL` so it can be tested with a fake service manager.

- [ ] **Step 1: Write the failing tests**

```php
it('starts each queued request once and retires its marker', function (): void {
    // Two UUID markers in a temp dir, a fake systemctl recording "$2" per call
    // and exiting 0. Run the script twice; expect exactly two start lines.
});

it('isolates a failing request so the next one still runs', function (): void {
    // The fake systemctl exits 1 for the first UUID. After one run: the first
    // marker is renamed to <uuid>.failed, the second is retired, and the script
    // exits 0.
});

it('accepts a second restore after a completed one', function (): void {
    // A <uuid>.failed marker from an earlier run plus a fresh marker: only the
    // fresh UUID is started.
});

it('recovers a claim abandoned by a crashed dispatcher', function (): void {
    // A <uuid>.claimed older than the staleness bound with no journal entry and
    // no active unit is moved to <uuid>.failed, never retried silently.
});

it('never starts a second restore while one is running', function (): void {
    // The fake systemctl reports an active tallpbx-restore@<other>.service;
    // the fresh marker stays untouched and the run exits 0.
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreDispatchTest.php`
Expected: FAIL — the first stale marker aborts the loop under `set -e`.

- [ ] **Step 3: Rewrite the dispatcher**

Drop `set -e` (keep `set -u` and `pipefail`) and take an exclusive `flock` on the request directory so two triggers cannot interleave. Then, in order:

1. **Concurrency guard.** `systemctl is-active` does not expand globs, so use `systemctl list-units --state=active --no-legend 'tallpbx-restore@*'` and exit 0 without touching any marker when it prints any unit. Two destructive restores must never overlap.
2. **Stale claims.** Move any `<uuid>.claimed` older than `STALE_CLAIM_MINUTES` (default 30) whose unit is not active and whose journal entry is not terminal to `<uuid>.failed`. A claim is never replayed blindly after a crash: an import whose outcome is unknown must be triaged by an operator.
3. **Claim and start.** For each `*.request`: validate the UUID, `mv` the marker to `<uuid>.claimed` (the rename is the claim), run `"$systemctl_bin" start "tallpbx-restore@<uuid>.service"`, then `rm -f` the claim on success or `mv` it to `<uuid>.failed` on failure.
4. Move non-UUID markers into `rejected/`, and always `exit 0` so systemd's journal carries per-request failures.

- [ ] **Step 4: Make the command idempotent for terminal operations**

In `RestoreOperationCommand::handle()` inject `RestoreJournal $journal` and, **before** the `pending-helper` check:

```php
if ($journal->terminalStatus($operationId) !== null) {
    $this->components->info('The restore operation already reached a terminal state.');

    return self::SUCCESS;
}
```

Keep `FAILURE` for everything else. A retried stale marker now retires itself instead of failing the unit. Delete the `<uuid>.claimed` marker after the terminal check, so a completed operation leaves no claim behind.

- [ ] **Step 5: Seed the journal from the claimed marker**

`tallpbx-restore-dispatch` renames `<uuid>.request` to `<uuid>.claimed`, and `mv` preserves the JSON payload written in Task 2.1. The command reads `$requestDirectory.'/'.$operationId.'.claimed'`, `json_decode`s it, and calls `$journal->seed($operationId, $metadata)` before `$executor->execute($operation)`. A missing or unreadable marker fails the command rather than starting an unjournalled restore. When the operation reaches a terminal phase, delete the `.claimed` marker; a surviving claim whose unit is gone is then the only evidence of a crash and is triaged by the dispatcher's staleness rule.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreDispatchTest.php app-modules/backups/tests`
Expected: PASS. Backup tests live only under `app-modules/backups/tests`; there is no `tests/Feature/Backups` directory.

- [ ] **Step 7: Commit**

```bash
git add scripts/resources/tallpbx-restore-dispatch app/Console/Commands/Backups/RestoreOperationCommand.php app-modules/backups/tests/RestoreDispatchTest.php
git commit -m "fix(backups): claim restore requests atomically and acknowledge terminal ones"
```

### Task 2.3: Reconcile the journal into the restored database (O02)

**Files:**
- Create: `app/Console/Commands/Backups/ReconcileRestoreJournalCommand.php`
- Create: `scripts/resources/tallpbx-restore-status`
- Modify: `scripts/resources/tall.sh` (install the status reader)
- Modify: `app-modules/backups/src/Services/RestoreOperationExecutor.php:25-74`
- Modify: `tests/Feature/Architecture/ModuleArchitectureTest.php:25-57`
- Test: `tests/Feature/Console/ReconcileRestoreJournalCommandTest.php` (new)
- Test: `app-modules/backups/tests/RestoreStatusScriptTest.php` (new)

**Interfaces:**
- Consumes: `RestoreJournal::all()`, `RestoreJournal::record()`, `RestoreJournal::terminalStatus()`.
- Produces: `backups:reconcile-restore-journal {--operation= : Limit to one UUID}` and the bootless `scripts/resources/tallpbx-restore-status`. The status reader touches no framework code, so it works while the database is being replaced.

- [ ] **Step 1: Write the failing test**

```php
it('recreates a restore operation row that the imported database erased', function (): void {
    $journal = new RestoreJournal($dir);
    $journal->write($uuid, [
        'phase' => 'importing', 'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64), 'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
        'started_at' => '2026-11-15T10:00:00+00:00',
    ]);

    $this->artisan('backups:reconcile-restore-journal', ['--operation' => $uuid])->assertSuccessful();

    $this->assertDatabaseHas('restore_operations', [
        'id' => $uuid, 'status' => 'importing', 'requested_by_admin_id' => 7,
    ]);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Console/ReconcileRestoreJournalCommandTest.php`
Expected: FAIL — the command does not exist.

- [ ] **Step 3: Implement the projection command**

For each journal entry, `RestoreOperation::updateOrCreate(['id' => $id], [...])` with `archive_path`, `archive_checksum`, `source_backup_id`, `scopes`, `status` (`$entry['phase']`), `failure_reason`, `pre_restore_snapshot_path`, `helper_log_path`, `started_at`, `completed_at`, `requested_by_admin_id` (`$entry['requester']['id']`). `requested_by_admin_id` is **not** a foreign key in `restore_operations`, so the original numeric ID is kept as an opaque audit snapshot even when the restored `admins` table has reused it. The panel must not join that column to `admins` for display; it renders the requester name from the journal. Note this in the command's docblock so the next reader does not "fix" it into a foreign key. Declare the command with the project's Laravel 13 attribute style (`#[Signature]`, `#[Description]`) as `RestoreOperationCommand` does, not `protected $signature`.

- [ ] **Step 4: Add the root-runnable status reader as a bootless script**

A Laravel command cannot serve this purpose: every Artisan boot constructs `ModuleState`, which runs `Module::query()` ([ModuleState.php:140](../app/Services/ModuleState.php#L140)), so the command fails exactly when the database is being replaced. Write `scripts/resources/tallpbx-restore-status` instead: a `#!/usr/bin/php` script that requires no autoloader, scans `<journal_root>/*.json`, `json_decode`s each file, and prints one row per operation (`id`, `phase`, `phase_at`, `archive_path`, `pre_restore_snapshot_path`, `failure_reason`), exiting non-zero when any entry is not terminal. Install it with `install -m 0750 -o root -g root` into `/usr/local/sbin/` from `scripts/resources/tall.sh`, beside the existing restore helper. Do not use `jq`; it is not installed on the appliance.

- [ ] **Step 5: Allowlist the reconcile command in the architecture test**

`tests/Feature/Architecture/ModuleArchitectureTest.php` forbids any `app/` file from importing `Modules\*` unless it appears in `ALLOWED_SHARED_KERNEL_MODULE_DEPENDENCIES` (L25-57). Add one entry for `ReconcileRestoreJournalCommand` in the existing "Privileged System CLI Helpers" block next to `RestoreOperationCommand`, with a one-line rationale in the same style. The status reader is a script, so it needs no entry. Keeping the command in `app/` follows the precedent of the root-invoked `RestoreOperationCommand`; moving it into `app-modules/backups/src/Console/Commands/` plus `consoleCommands()` is the alternative if you prefer module ownership.

- [ ] **Step 6: Call the reconcile command from the executor after the migration**

Write every phase to the journal as it happens (`extracting`, `snapshotting`, `importing`, `restoring-files`, `migrating`, `reconciling`, `health-check`, `completed` / `failed`). After the existing `migrate --force` step, run `artisan backups:reconcile-restore-journal --operation=<id>` and then `$operation->refresh()` before the terminal `update()`.

The failure path needs the same treatment, or the task does not fix its own finding. If `restoreDatabase()` has replaced the database and a later step throws, the existing `catch` calls `$operation->update()` on a row that no longer exists — silently 0 rows, which is exactly O02. Restructure the `catch` block to:

1. `$journal->record($id, 'failed', ['failure_reason' => $exception->getMessage(), 'maintenance_locked' => true])` **first**, because the journal is the only writer that survives the replaced database.
2. Run `artisan backups:reconcile-restore-journal --operation=<id>` inside its own `try/catch`, so a reconcile failure never masks the original exception.
3. Only then attempt `$operation->update([...])`, for the case where the row did survive.

Add a test that seeds a journal entry, deletes the `restore_operations` row, and asserts the failed phase plus the projected row both exist after the catch path runs.

- [ ] **Step 7: Run the tests and the backups suite**

Run: `./vendor/bin/pest tests/Feature/Console/ReconcileRestoreJournalCommandTest.php tests/Feature/Architecture/ModuleArchitectureTest.php app-modules/backups/tests`
Expected: PASS. `RestoreStatusScriptTest` runs the script through `Symfony\Component\Process\Process` against a temporary journal directory in the same suite.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/Backups/ReconcileRestoreJournalCommand.php scripts/resources/tallpbx-restore-status scripts/resources/tall.sh app-modules/backups/src/Services/RestoreOperationExecutor.php tests/Feature/Architecture/ModuleArchitectureTest.php tests/Feature/Console/ReconcileRestoreJournalCommandTest.php app-modules/backups/tests/RestoreStatusScriptTest.php
git commit -m "fix(backups): project the restore journal back into the restored database"
```

### Task 2.4: Restore top-level application files (O03)

**Files:**
- Modify: `app-modules/backups/src/Services/RestoreOperationExecutor.php:105-203`
- Modify: `app-modules/backups/tests/BackupsRouteIndependenceTest.php:193`
- Test: `app-modules/backups/tests/RestoreFileSymmetryTest.php` (new)

**Interfaces:**
- Produces: `public function restoreFiles(string $archiveRoot, array $scopes): void` (renamed from `restoreSelectedFiles`, made public so restored content is testable directly).

- [ ] **Step 1: Write the failing test**

```php
it('restores a top-level file next to the restored directories', function (): void {
    // Archive root contains app_files/state.json and app_files/nested/inner.txt.
    $executor->restoreFiles($archiveRoot, ['app_files']);

    expect(file_get_contents(storage_path('app/state.json')))->toBe('{"v":1}')
        ->and(file_get_contents(storage_path('app/nested/inner.txt')))->toBe('inner');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreFileSymmetryTest.php`
Expected: FAIL — `state.json` is never restored.

- [ ] **Step 3: Mirror the backup's member list on restore**

After the existing `File::directories()` loop, iterate `scandir($archiveRoot.'/app_files')` and copy every non-directory, non-dot entry to `storage_path('app/'.$entry)`. Use `scandir` for the same reason `archiveCustomModules()` does: `File::files()` skips dot files. Update the existing call site to `restoreFiles()`.

Two companions must change in the same task, or the asymmetry survives the rename:

- **`snapshotSelectedFiles()`** ([RestoreOperationExecutor.php:111](../app-modules/backups/src/Services/RestoreOperationExecutor.php#L105-L111)) copies all of `storage_path('app')` into the rollback snapshot. Apply the backup's own exclusion list — `['recordings', 'voicemail', 'fax', 'backups']` — so a pre-restore snapshot does not duplicate the media and backup trees it is supposed to protect. Without this, every restore doubles the on-disk footprint the preflight in Task 2.7 is trying to reserve.
- **`BackupsRouteIndependenceTest.php:193`** obtains the method with `new ReflectionMethod($executor, 'restoreSelectedFiles')`. Rename that string to `restoreFiles`, or the module suite fails as soon as it runs.

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreFileSymmetryTest.php app-modules/backups/tests/RestoreArchiveTest.php app-modules/backups/tests/BackupsRouteIndependenceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/backups/src/Services/RestoreOperationExecutor.php app-modules/backups/tests/BackupsRouteIndependenceTest.php app-modules/backups/tests/RestoreFileSymmetryTest.php
git commit -m "fix(backups): restore top-level application files captured by the backup"
```

### Task 2.5: Give restore commands an explicit, configurable deadline (O04)

**Files:**
- Create: `app-modules/backups/src/Services/RestoreDeadline.php`
- Modify: `app-modules/backups/src/Services/SymfonyRestoreCommandRunner.php:20-31`
- Modify: `app-modules/backups/src/Services/RestoreCommandRunnerInterface.php`
- Modify: `app-modules/backups/src/Services/RestoreOperationExecutor.php:25-74`, `:261-295`
- Modify: `config/backup-storage.php`
- Modify: `app-modules/backups/src/Providers/ModuleServiceProvider.php`
- Test: `app-modules/backups/tests/RestoreCommandTimeoutTest.php` (new)
- Test: `app-modules/backups/tests/RestoreDeadlineTest.php` (new)

**Interfaces:**
- Produces:
  - `SymfonyRestoreCommandRunner::__construct(?float $timeout = 900.0)` — a per-command ceiling only; no idle timeout.
  - `RestoreCommandRunnerInterface::run(array $command, mixed $input = null, array $environment = []): string` with `@param string|resource|null $input` in the PHPDoc — a stream is forwarded to `Process::setInput()` unchanged, so a dump is never loaded into PHP memory. Do **not** write `string|resource|null` as a native type: `resource` is not a builtin type in PHP 8.5, so it is parsed as a class name and a real stream then fails with a `TypeError` at runtime.
  - `RestoreDeadline::__construct(int $seconds = 3600)`, `::remainingSeconds(): int`, `::assertRemaining(): void` — throws `RuntimeException` when the budget is spent.

- [ ] **Step 1: Write the failing tests**

```php
it('applies the configured per-command ceiling instead of inheriting sixty seconds', function (): void {
    $runner = new SymfonyRestoreCommandRunner(timeout: 0.2);

    expect(fn () => $runner->run(['sleep', '2']))
        ->toThrow(Symfony\Component\Process\ProcessTimedOutException::class);
});

it('reserves a fifteen minute command ceiling and a one hour operation budget', function (): void {
    expect((float) config('backup-storage.restore_command_timeout'))->toBe(900.0)
        ->and((int) config('backup-storage.restore_operation_deadline'))->toBe(3600);
});

it('refuses to start the next phase once the operation budget is spent', function (): void {
    Carbon::setTestNow('2026-11-15 10:00:00');
    $deadline = new RestoreDeadline(seconds: 3600);

    Carbon::setTestNow('2026-11-15 10:59:00');
    expect($deadline->remainingSeconds())->toBe(60);

    Carbon::setTestNow('2026-11-15 11:00:01');
    expect(fn () => $deadline->assertRemaining())->toThrow(RuntimeException::class, 'restore budget exhausted');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreCommandTimeoutTest.php app-modules/backups/tests/RestoreDeadlineTest.php`
Expected: FAIL — the first test times out only after 60 seconds, the deadline class and config keys are missing.

- [ ] **Step 3: Apply a per-command ceiling only**

```php
$process = new Process($command, base_path());
$process->setTimeout($this->timeout ?? config('backup-storage.restore_command_timeout'));
```

Do **not** call `setIdleTimeout()`. Symfony measures idle time since the process last wrote to stdout or stderr ([Process.php:1006-1023](../vendor/symfony/process/Process.php#L1006-L1023)), and the snapshot and import commands write to a file (`--result-file`) or read from one, producing no console output. An idle timer would kill a healthy dump that is making progress.

- [ ] **Step 4: Bound the whole operation, not just each command**

`RestoreDeadline` is created as the first statement of `execute()` and checked with `assertRemaining()` before every phase that follows it (`extracting`, `snapshotting`, `importing`, `restoring-files`, `migrating`, `reconciling`, `health-check`). A spent budget fails the operation **before** the next phase starts, so a restore never begins an import it cannot finish. Record `deadline_at` and `remaining_seconds` in every journal transition, and do not reset the budget between phases or retries. The one-hour figure covers machine work only; time waiting for a human to confirm is outside it.

Add `restore_command_timeout` (900) and `restore_operation_deadline` (3600) to `config/backup-storage.php`, backed by `env('TALLPBX_RESTORE_COMMAND_TIMEOUT')` / `env('TALLPBX_RESTORE_OPERATION_DEADLINE')`, and bind the runner in the module provider from the first. The command ceiling catches a single hung subprocess; the operation budget stops a sequence of individually healthy commands from running for hours.

- [ ] **Step 5: Stream the snapshot and the import**

Two `RestoreOperationExecutor` paths still hold the whole dump in PHP memory: `snapshotDatabase()` does `File::put($snapshotPath, $output)` on the runner's captured stdout, and `restoreDatabase()` does `File::get($dumpPath)` before passing it as stdin. Change both:

- Snapshot: add `--result-file=<snapshotPath>` for MySQL/MariaDB so the client writes the file itself, and keep `File::put()` only for the `sqlite3` branch, exactly as in Task 0.2.
- Import: open `fopen($dumpPath, 'rb')` and pass the handle to `run()`, closing it in a `finally`. Symfony `Process::setInput()` accepts a resource and streams it.

Add to `RestoreCommandTimeoutTest`: a 200 MB synthetic dump imported through a `Cats`-style fake asserts the runner received a resource, not a string.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreCommandTimeoutTest.php app-modules/backups/tests/RestoreDeadlineTest.php app-modules/backups/tests`
Expected: PASS in under 10 seconds.

- [ ] **Step 7: Commit**

```bash
git add app-modules/backups/src/Services/RestoreDeadline.php app-modules/backups/src/Services/SymfonyRestoreCommandRunner.php app-modules/backups/src/Services/RestoreCommandRunnerInterface.php app-modules/backups/src/Services/RestoreOperationExecutor.php config/backup-storage.php app-modules/backups/src/Providers/ModuleServiceProvider.php app-modules/backups/tests/RestoreCommandTimeoutTest.php app-modules/backups/tests/RestoreDeadlineTest.php
git commit -m "fix(backups): bound restore commands, the whole operation, and stream SQL"
```

### Task 2.6: Reconcile runtime services and fail a restore that leaves them down (O06, added)

**Files:**
- Create: `app/Services/Appliance/RuntimeServiceReconciler.php`
- Modify: `app-modules/backups/src/Services/RestoreOperationExecutor.php:327-338`
- Test: `tests/Unit/Services/RuntimeServiceReconcilerTest.php` (new)

**Interfaces:**
- Consumes: `ProcessRunner::run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult`.
- Produces: `RuntimeServiceReconciler::reconcile(): array{restarted: list<string>, skipped: list<string>, failed: list<string>, warnings: list<string>, probes: array{ok: list<string>, failed: list<string>}}` — `failed` holds required units that are not running after the restart; `warnings` holds optional units that were running and did not come back; `probes` holds the functional checks below.

- [ ] **Step 1: Write the failing test**

```php
it('restarts every required unit and reports failures instead of swallowing them', function (): void {
    $runner = new RecordingProcessRunner(failFor: ['tallpbx-cdr-worker']);
    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['restarted'])->toContain('mariadb', 'nginx', 'freeswitch')
        ->and($result['failed'])->toBe(['tallpbx-cdr-worker']);
});

it('never enables an optional unit that was already disabled', function (): void {
    $runner = new RecordingProcessRunner(inactive: ['tallpbx-xml-worker']);
    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['skipped'])->toContain('tallpbx-xml-worker')
        ->and($result['warnings'])->toBe([]);
});

it('warns instead of failing when an enabled optional unit does not return', function (): void {
    $runner = new RecordingProcessRunner(failFor: ['tallpbx-xml-worker'], active: ['tallpbx-xml-worker']);
    $result = (new RuntimeServiceReconciler($runner))->reconcile();

    expect($result['failed'])->toBe([])
        ->and($result['warnings'])->toBe(['tallpbx-xml-worker']);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/Services/RuntimeServiceReconcilerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the shared inventory**

Required units: `mariadb`, `redis-server`, `php<MAJOR>.<MINOR>-fpm` (derived from `PHP_MAJOR_VERSION`/`PHP_MINOR_VERSION`, never hardcoded), `nginx`, `freeswitch`, `freeswitch-listener`, `tallpbx-queue`, `tallpbx-scheduler`, `tallpbx-cdr-worker`, `tallpbx-reverb`. Optional units: `tallpbx-xml-worker`, and any other unit that is deliberately disabled by default.

The exit status of `systemctl restart` is not a health signal. Record each unit's **expected** state first (`systemctl is-enabled --quiet` and `is-active --quiet`), restart the required ones, then confirm `is-active` afterwards. A unit that is enabled but inactive after the restart is `failed`; an optional unit that was inactive before the operation is `skipped` and is never started. Never throw from `reconcile()`.

- [ ] **Step 4: Verify function, not just process state**

`reconcile()` finishes with four bounded probes, each with its own short timeout so a hung dependency cannot consume the restore budget:

| Probe | Check | Failure means |
|---|---|---|
| Database | `DB::select('select 1')` within 5 s | the restored schema is unusable |
| Redis | `Redis::connection('default')->ping()` | cache, queues or the CDR stream are unreachable |
| Web/PHP | `GET http://127.0.0.1/up` returns 200 (`bootstrap/app.php` health route) | the web tier never came back |
| FreeSWITCH | `fs_cli -x status` reports `UP` | telephony is down even though the unit is active |

A running process proves nothing on its own; these four do. Record the result in the journal and fail the operation on any required probe:

```php
$services = $this->services->reconcile();
$journal->record($operation->id, 'health-check', ['services' => $services]);

$failed = [...$services['failed'], ...$services['probes']['failed']];

if ($failed !== []) {
    throw new RuntimeException('Restore health checks failed: '.implode(', ', $failed));
}
```

The throw lands in the existing `catch`, marks the operation and journal `failed`, and leaves the application in maintenance mode. Change `finally` so it no longer calls `artisan up` unconditionally: bring the application up only when the try block completed or `$services['failed'] === []`; when required services did not return, keep maintenance mode active, record `'maintenance_locked' => true` in the journal, and let the operator recovery path in Task 7.2 take over. A half-restored runtime must never take live traffic.

- [ ] **Step 5: Run the test and the backups suite**

Run: `./vendor/bin/pest tests/Unit/Services/RuntimeServiceReconcilerTest.php app-modules/backups/tests`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Appliance/RuntimeServiceReconciler.php app-modules/backups/src/Services/RestoreOperationExecutor.php tests/Unit/Services/RuntimeServiceReconcilerTest.php
git commit -m "fix(backups): fail a restore whose required services did not restart"
```

### Task 2.7: Refuse a restore that cannot fit on disk

**Files:**
- Modify: `app-modules/backups/src/Services/RestoreOperationExecutor.php`
- Test: `app-modules/backups/tests/RestoreSpacePreflightTest.php` (new)

**Interfaces:**
- Produces: `RestoreOperationExecutor::projectedRequirement(int $workingSetBytes): array{workingSet: int, reserve: int, required: int}` (public, pure) and `assertSpaceFor(string $archivePath, array $scopes): void`.
- Constructor compatibility: [RestoreServiceTest L241,248](../app-modules/backups/tests/RestoreServiceTest.php#L241-L248) and `BackupsRouteIndependenceTest` instantiate `new RestoreOperationExecutor($runner)` with one argument. Append the new collaborators as trailing nullable parameters resolved through `app()` — `?RestoreJournal $journal = null, ?RuntimeServiceReconciler $services = null, ?Closure $freeSpace = null` — or update both call sites in this same task. A required parameter breaks two suites before this task's own test can run.

- [ ] **Step 1: Write the failing tests**

```php
it('requires the working set plus the reserve, not the reserve alone', function (): void {
    expect(makeExecutor()->projectedRequirement(workingSetBytes: 32_000_000_000))->toMatchArray([
        'workingSet' => 32_000_000_000,
        'reserve' => 3_200_000_000,
        'required' => 35_200_000_000,
    ]);
});

it('refuses a volume that cannot hold the working set and the reserve', function (): void {
    // 30 GB free against a 35.2 GB requirement: the old reserve-only check
    // (3.2 GB) would have admitted this restore.
    $executor = makeExecutor(freeSpace: ['/var/lib/tallpbx/backups' => 30_000_000_000.0]);

    expect(fn () => $executor->assertSpaceFor('/var/lib/tallpbx/backups/x.tar.gz', ['database']))
        ->toThrow(RuntimeException::class, 'insufficient free space');
});

it('refuses when a second volume is short even though the archive volume fits', function (): void {
    $executor = makeExecutor(freeSpace: [
        '/var/lib/tallpbx/backups' => 100_000_000_000.0,
        storage_path('backups') => 1_000_000.0,
    ]);

    expect(fn () => $executor->assertSpaceFor('/var/lib/tallpbx/backups/x.tar.gz', ['database']))
        ->toThrow(RuntimeException::class, 'insufficient free space');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreSpacePreflightTest.php`
Expected: FAIL — methods do not exist.

- [ ] **Step 3: Implement the preflight**

`projectedRequirement()` takes the **working set measured from the archive**, not a multiplier: `tar -tvf` lists each member's uncompressed size, and their sum is what extraction will write. Add the current database dump size when the `database` scope is selected and the media tree size when `media` is selected. Return `reserve = max(2_000_000_000, ceil(workingSet * 0.10))` and `required = workingSet + reserve`.

A fixed fourfold expansion is not a bound — a highly compressible SQL dump can expand far more, and an already-compressed media archive far less. The listing is the bound.

`assertSpaceFor(string $archivePath, array $scopes)` checks `required` against **every** filesystem the operation writes to: the extraction root (`backup-storage.root`), the snapshot root (`storage_path('backups')`), and the media root (`media-storage.store_root`) when the media scope is selected. Refuse when any one is short, naming the volume in the message.

Call it immediately **after** `assertArchiveIsSafe()`, never before. The archive check is cheap, runs first today, and `RestoreServiceTest` proves the ordering by passing a non-existent `/tmp/restore.tar` and expecting `'unsafe path'`; a `filesize()` on a missing path only emits a warning and returns `0`, so placing the space check first trades a precise error for a noisy one.

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreSpacePreflightTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/backups/src/Services/RestoreOperationExecutor.php app-modules/backups/tests/RestoreSpacePreflightTest.php
git commit -m "feat(backups): refuse a restore without space for its working set"
```

### Task 2.8: Enforce the 2 GB upload ceiling end to end

**Files:**
- Modify: `config/backup-storage.php`
- Modify: `config/livewire.php` (`temporary_file_upload.rules`)
- Modify: `scripts/resources/php.sh`
- Modify: `scripts/resources/nginx.sh`
- Modify: `app-modules/backups/src/Livewire/RestoreArchive.php`
- Modify: `app-modules/backups/lang/{en,es,fr}/admin.php`
- Test: `app-modules/backups/tests/RestoreUploadCeilingTest.php` (new)

**Interfaces:**
- Produces: `config('backup-storage.max_upload_bytes')` = `2_000_000_000` (decimal), used by the Livewire rule and the displayed guidance.

- [ ] **Step 1: Write the failing tests**

```php
it('caps a direct archive upload at two decimal gigabytes', function (): void {
    expect((int) config('backup-storage.max_upload_bytes'))->toBe(2_000_000_000);
});

it('refuses an archive above the ceiling and accepts one below it', function (): void {
    Livewire::test(RestoreArchive::class)
        ->set('archive', UploadedFile::fake()->create('big.tar.gz', 2_100_000_000))
        ->call('upload')
        ->assertHasErrors('archive');

    Livewire::test(RestoreArchive::class)
        ->set('archive', UploadedFile::fake()->create('ok.tar.gz', 1_900_000_000))
        ->call('upload')
        ->assertHasNoErrors('archive');
});

it('ships matching PHP and Nginx transport limits', function (): void {
    expect(file_get_contents(base_path('scripts/resources/php.sh')))->toContain('upload_max_filesize')
        ->and(file_get_contents(base_path('scripts/resources/nginx.sh')))->toContain('client_max_body_size');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreUploadCeilingTest.php`
Expected: FAIL — the config key is missing and neither installer script sets a limit.

- [ ] **Step 3: Set the product ceiling and the Livewire rule**

Add `'max_upload_bytes' => (int) env('TALLPBX_MAX_UPLOAD_BYTES', 2_000_000_000)` to `config/backup-storage.php`. Change Livewire's rule from `max:1048576` (1 GiB in kilobytes) to `max:1953125` — `ceil(2_000_000_000 / 1024)` — and validate the decimal ceiling in `RestoreArchive` so the product limit is stated once and enforced where the operator sees it.

- [ ] **Step 4: Align the transport layers**

Neither `scripts/resources/php.sh` nor `scripts/resources/nginx.sh` sets a limit today, so PHP's 2 MB default and Nginx's 1 MB default reject any real archive before Livewire sees it. Add `upload_max_filesize = 2G`, `post_max_size = 2G`, and `client_max_body_size 2000m;`. Those are binary transport headroom; the decimal 2,000,000,000 product limit is enforced in the application. Re-run the installer or `appliance:sync-host` on an existing host, and restart PHP-FPM and Nginx.

- [ ] **Step 5: State the limit in the interface**

Update the restore dialog copy to name 2 GB decimal and the archive types accepted. Add the key to all three locales with the module's `backup_` prefix.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/backups/tests/RestoreUploadCeilingTest.php tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add config/backup-storage.php config/livewire.php scripts/resources/php.sh scripts/resources/nginx.sh app-modules/backups/src/Livewire/RestoreArchive.php app-modules/backups/lang app-modules/backups/tests/RestoreUploadCeilingTest.php
git commit -m "feat(backups): enforce a 2 GB decimal upload ceiling across the stack"
```

---

## Package 3 — Consistent deployment lifecycle

### Task 3.1: Resolve branch and tag targets explicitly (O05)

**Files:**
- Create: `app/Support/GitTarget.php`
- Modify: `app/Services/GitUpdateService.php:135-170`, `:203-226`, `:430-471`
- Modify: `app-modules/admin/src/Livewire/GitUpdate.php:57`, `:222`
- Modify: `app-modules/admin/resources/views/partials/git-channels.blade.php:130-132`
- Test: `tests/Feature/Services/GitUpdateTargetTest.php` (new)
- Test: `tests/Feature/Livewire/GitUpdateTest.php`, `tests/Feature/Services/GitUpdateServiceTest.php`

**Interfaces:**
- Produces:
  - `final readonly class GitTarget { __construct(public string $name, public string $kind, public string $ref) }` — `kind` is `branch` or `tag`; `ref` is `refs/heads/<name>` or `refs/tags/<name>`.
  - `GitUpdateService::resolveTarget(string $target): ?GitTarget`
  - `GitUpdateService::commitsBehind(string $target): ?int` — the **existing** method, changed in place to return `null` when the target cannot be resolved (never `0`).
  - `GitUpdateService::incomingCommits(string $target, int $limit = 5): array`
  - `GitUpdateService::pull(string $target): bool`

- [ ] **Step 1: Write the failing test against a disposable repository**

```php
it('switches to a tag by commit instead of a nonexistent remote branch', function (): void {
    // Build a temp repo with a bare origin, one branch "5.x" and tag "v9.9.9".
    $service = makeService($worktree);

    $target = $service->resolveTarget('v9.9.9');

    expect($target->kind)->toBe('tag')
        ->and($target->ref)->toBe('refs/tags/v9.9.9')
        ->and($service->pull('v9.9.9'))->toBeTrue();
    expect(trim(shell_exec('git -C '.escapeshellarg($worktree).' rev-parse --abbrev-ref HEAD')))->toBe('HEAD');
});

it('reports an unknown target as unresolvable rather than zero commits', function (): void {
    expect(makeService($worktree)->commitsBehind('does-not-exist'))->toBeNull();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateTargetTest.php`
Expected: FAIL — `resolveTarget()` does not exist and `commitsBehind()` returns `0`.

- [ ] **Step 3: Implement resolution once and use it everywhere**

`resolveTarget()` tries `git -C <repo> rev-parse --verify --quiet refs/remotes/origin/<t>` (branch), then `refs/tags/<t>` (tag). `pull()` uses `checkout -B <name> origin/<name>` for a branch switch, `pull --ff-only origin <name>` when already on that branch, and `checkout --detach refs/tags/<name>` for a tag. Change `commitsBehind()` to `?int` in place and have it return `null` after a failed `rev-parse`, never a fabricated `0`; `incomingCommits()` uses `HEAD..<ref>` and returns `[]` only for a resolved ref with no incoming commits.

The execution path is separate from `pull()` and must change too: `update()` calls `runUpdate()` (L365) → `runPipeline()` ([L430-448](../app/Services/GitUpdateService.php#L430-L448)), which builds its own `checkout -B $target origin/$target` / `pull --ff-only origin $target`. Resolve once at the top of `update()` and pass the `GitTarget` (or its ref) down, so a tag becomes `checkout --detach refs/tags/<name>` there as well. Without this, the preview reports correctly while the actual update still fails — the defect the task exists to fix.

Two existing mocks break when `resolveTarget()` adds a `rev-parse` call: `tests/Feature/Services/GitUpdateServiceTest.php` L116 and L132 use `->once()`. Update both to expect the resolution call before the range query.

- [ ] **Step 4: Update the two consumers of `commitsBehind()`**

`app-modules/admin/src/Livewire/GitUpdate.php` declares `public int $commitsBehind = 0;` and assigns the service result at L222. Change the property to `public ?int $commitsBehind = 0;` and keep the assignment. In `resources/views/partials/git-channels.blade.php` L130, render an explicit unknown state — `@if ($commitsBehind === null)` → a `badge badge-warning` with "Unknown" rather than hiding the badge — so an unresolvable target is visible instead of silently reading as up to date. Update the two existing tests that mock `commitsBehind()` to return `?int`.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateTargetTest.php tests/Feature/Livewire/GitUpdateTest.php tests/Feature/Services/GitUpdateServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/GitTarget.php app/Services/GitUpdateService.php app-modules/admin/src/Livewire/GitUpdate.php app-modules/admin/resources/views/partials/git-channels.blade.php tests/Feature/Services/GitUpdateTargetTest.php tests/Feature/Livewire/GitUpdateTest.php tests/Feature/Services/GitUpdateServiceTest.php
git commit -m "fix(updater): resolve branches and tags to explicit refs before updating"
```

### Task 3.2: Restore the Node dependency graph before rollback builds (O07)

**Files:**
- Modify: `app/Services/GitUpdateService.php:482-513`
- Test: `tests/Feature/Services/GitUpdateRollbackTest.php` (new)

**Interfaces:**
- Produces: rollback runs `npm ci --ignore-scripts` before `npm run build`.

- [ ] **Step 1: Write the failing test**

```php
it('reinstalls Node dependencies from the restored lockfile before rebuilding', function (): void {
    $runner = new RecordingProcessRunner(failAt: 'NPM build');
    // Run the pipeline against a temp repo, then assert the rollback steps.

    expect($runner->commands())->toContain(['npm', 'ci', '--ignore-scripts']);
    expect(array_search(['npm', 'ci', '--ignore-scripts'], $runner->commands()))
        ->toBeLessThan(array_search(['npm', 'run', 'build'], $runner->commands()));
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateRollbackTest.php`
Expected: FAIL — rollback builds against the failed version's `node_modules`.

- [ ] **Step 3: Add the reinstall step**

Insert `'Rollback npm' => ['npm', 'ci', '--ignore-scripts']` between `'Rollback composer'` and `'Rollback build'` in `rollback()`.

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateRollbackTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitUpdateService.php tests/Feature/Services/GitUpdateRollbackTest.php
git commit -m "fix(updater): rebuild rollback assets from the restored Node lockfile"
```

### Task 3.3: Reload resident workers after an update (O06)

**Files:**
- Modify: `app/Services/GitUpdateService.php:34` (constructor), `:430-471`
- Test: `tests/Feature/Services/GitUpdateServiceReconciliationTest.php` (new)

**Interfaces:**
- Consumes: `RuntimeServiceReconciler::reconcile()` from Task 2.6.
- Produces: a `Reconcile services` step in the update result, and a failed update when a required unit fails.

- [ ] **Step 1: Write the failing test**

```php
it('reconciles runtime services after the build and cache clear', function (): void {
    $reconciler = new FakeReconciler(failed: [], warnings: []);
    $result = makeService(new RecordingProcessRunner, $reconciler)->update('5.x');

    expect($result->success)->toBeTrue()
        ->and(collect($result->steps)->firstWhere('label', 'Reconcile services'))->not->toBeNull();
});

it('fails the update when a required service does not come back', function (): void {
    $reconciler = new FakeReconciler(failed: ['tallpbx-cdr-worker'], warnings: []);
    $result = makeService(new RecordingProcessRunner, $reconciler)->update('5.x');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('tallpbx-cdr-worker');
});

it('warns but succeeds when only an optional unit does not come back', function (): void {
    $reconciler = new FakeReconciler(failed: [], warnings: ['tallpbx-xml-worker']);
    $result = makeService(new RecordingProcessRunner, $reconciler)->update('5.x');

    expect($result->success)->toBeTrue()
        ->and(collect($result->steps)->firstWhere('label', 'Reconcile services')['status'])->toBe('warn');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateServiceReconciliationTest.php`
Expected: FAIL — no reconciliation step exists.

- [ ] **Step 3: Inject the reconciler and add the step**

Add `?RuntimeServiceReconciler $services = null` as the trailing constructor parameter, resolved through `app()` when null — `tests/Feature/Services/GitUpdateServiceTest.php` constructs `new GitUpdateService($runner)` at seven sites (L63-156), so a required parameter breaks the suite before this task's tests run. `update(string $target)` keeps its single argument. After the `$sequence` loop succeeds and before `return UpdateResult::success(...)`, call `reconcile()`, append a `Reconcile services` step whose status is `ok` when both lists are empty, `warn` when only `warnings` is non-empty, and `failed` when `failed` is non-empty. `addStep()` appends numerically (`$steps[] = [...]`, L684), so tests must read the step with `collect($result->steps)->firstWhere('label', 'Reconcile services')`, never `toHaveKey`. Return a failed `UpdateResult` only for `failed`. Do **not** roll back the code for a service failure: the new code is already migrated, and rollback without a schema reversal would be worse — report the failure and the operator action instead.

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateServiceReconciliationTest.php tests/Feature/Services`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GitUpdateService.php tests/Feature/Services/GitUpdateServiceReconciliationTest.php
git commit -m "fix(updater): reload resident workers and report failed services"
```

---

## Package 4 — Extension aggregate and resilient ingestion

### Task 4.1: Give the extension save one transaction owner (C04, M01)

**Files:**
- Create: `app-modules/extensions/src/Services/ExtensionSaveService.php`
- Modify: `app-modules/extensions/src/Livewire/ExtensionsEdit.php:209-279`, `:343-442`
- Test: `app-modules/extensions/tests/ExtensionSaveServiceTest.php` (new)

**Interfaces:**
- Consumes: `ExtensionServiceInterface::create/update/syncUsers`, `Modules\SipAccounts\Services\SipAccountServiceInterface::create/update`, `Modules\Voicemails\Services\VoicemailServiceInterface::create/update/findByMailbox`.
- Produces: `ExtensionSaveService::save(array $data, array $userIds, ?string $password, bool $voicemailEnabled, string $voicemailPassword, ?Extension $existing = null): Extension` — one `DB::transaction`, returning `$extension->fresh()`.
- Side effects: `VoicemailService::create()` provisions the mailbox directory, which no transaction can roll back. Accept the orphan directory — it is owner-scoped, idempotent, and reused by the next save — and assert only that no orphan **row** survives a rollback.

- [ ] **Step 1: Write the failing test**

```php
it('rolls the whole save back when the SIP account step fails', function (): void {
    $this->app->instance(SipAccountServiceInterface::class, new ThrowingSipAccountService);

    expect(fn () => app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '204'],
        userIds: [],
        password: 'secret123',
        voicemailEnabled: false,
        voicemailPassword: '',
    ))->toThrow(RuntimeException::class);

    expect(Extension::withoutGlobalScope('tenant')->where('extension_number', '204')->exists())->toBeFalse();
});

it('writes the extension, its users, its SIP account and its voicemail in one save', function (): void {
    $extension = app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '205', 'enabled' => true],
        userIds: [$this->user->id],
        password: 'secret123',
        voicemailEnabled: true,
        voicemailPassword: '1234',
    );

    expect($extension->users)->toHaveCount(1)
        ->and($extension->sipAccount)->not->toBeNull()
        ->and(Voicemail::where('voicemail_id', '205')->exists())->toBeTrue();
});
```

`SipAccount` and `Voicemail` apply `BelongsToTenant`, which injects `whereRaw('1 = 0')` whenever `TenantManager::getTenantId()` is null ([BelongsToTenant.php:32-35](../app/Traits/BelongsToTenant.php#L32-L35)). Without an active context, `$extension->sipAccount` is `null` and `Voicemail::where(...)` is `false` even though the rows exist. Set it in `beforeEach` with `app(TenantManager::class)->setTenantId((string) $this->tenant->id)`, or assert through `withoutGlobalScope('tenant')`.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/extensions/tests/ExtensionSaveServiceTest.php`
Expected: FAIL — class not found; today a SIP failure leaves the extension persisted.

- [ ] **Step 3: Implement the aggregate service**

Move the `syncSipAccount()` / `syncVoicemail()` **logic** into private methods on the new service, but replace their direct Eloquent writes with the module services — this is the change that makes Step 1's test possible:

- SIP: `SipAccountServiceInterface::create($data)` / `::update($account, $data)`, keeping the "no password, no account" guard and the `domain:…` / `global:…` `global_auth_key` construction. Resolve the existing account through the injected service or an explicit `SipAccount::withoutGlobalScope('tenant')` lookup, then hand it to `update()`.
- Voicemail: `VoicemailServiceInterface::findByMailbox($tenantId, $number)` then `::create()` / `::update()`, replacing the raw `updateOrCreate` and the raw "disable" update.

Wrap create-or-update, `syncUsers`, SIP sync and voicemail sync in one `DB::transaction(...)`. If a mailer, event listener or filesystem step must not run on a rolled-back transaction, defer it with `DB::afterCommit()` rather than moving it outside the transaction.

- [ ] **Step 4: Make the component call it**

`ExtensionsEdit::save()` keeps authorization and `validate()`, then calls `$extensionService->save(...)` and redirects. Delete the now-unused private methods and the `TenantDomain`/`SipAccount`/`Voicemail` imports.

- [ ] **Step 5: Add the routing-cache commit hook**

`App\Observers\RoutingCacheObserver` currently hooks `saved`/`deleted`/`restored` without `afterCommit`. Implement `Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit` on the observer so invalidation runs only after the transaction commits; a public `$afterCommit` property is not the model-observer contract. Without this, a concurrent FreeSWITCH XML lookup can repopulate the cache from pre-commit state and the transaction makes the race worse, not better.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/extensions/tests tests/Feature/Architecture`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app-modules/extensions/src/Services/ExtensionSaveService.php app-modules/extensions/src/Livewire/ExtensionsEdit.php app/Observers/RoutingCacheObserver.php app-modules/extensions/tests/ExtensionSaveServiceTest.php
git commit -m "fix(extensions): save the extension aggregate in one transaction"
```

### Task 4.2: Derive the tenant from the authorized context in every edit form (C03)

**Files:**
- Modify: `app/Support/BaseEditComponent.php:94-109`
- Modify: `app-modules/extensions/src/Livewire/ExtensionsEdit.php:285-296`
- Test: `app-modules/extensions/tests/ExtensionTenantUserCreateTest.php` (new)
- Test: `app-modules/call-blocks/tests/TenantUserCreateTest.php` (new, peer-form proof)

**Interfaces:**
- Consumes: `BaseEditComponent::resolveTenantId(): ?int`, `BaseEditComponent::isAdminGuard(): bool`.
- Produces: `BaseEditComponent::hydrate(): void` — re-derives `tenantId` from the authorized context for every non-admin actor on each Livewire request, so a tampered payload cannot redirect a write. Applying it once in the base class covers **36** components that declare a required `tenantId`, not just `ExtensionsEdit`.

- [ ] **Step 1: Write the failing tests**

```php
it('lets a tenant user create an extension without touching tenant state', function (): void {
    // web guard actor with extensions.create; TenantContext switched to $tenant.
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->assertDontSee(__('admin.tenant'))
        ->set('extensionNumber', '206')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('extensions', ['extension_number' => '206', 'tenant_id' => $this->tenant->id]);
});

it('refuses a duplicate number inside the same tenant', function (): void {
    // Existing "206" for $tenant; same call as above; assertHasErrors(['extensionNumber' => 'unique']).
});

it('ignores a forged tenant id from a tenant user', function (): void {
    // set('tenantId', $otherTenant->id) then save; the record lands in $tenant.
});

it('neutralises a tampered tenant id on a Livewire update', function (): void {
    // hydrate() runs before the payload is applied, so the guard that matters
    // here is updatedTenantId(). The value must be replaced, not rejected.
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->set('tenantId', $this->otherTenant->id)
        ->assertSet('tenantId', $this->tenant->id);
});

it('lets a tenant user create a call block without touching tenant state', function (): void {
    // Same journey against a second BaseEditComponent subclass (CallBlocksEdit).
    // This is the regression that proves the fix lives in the base class and not
    // in one form; 36 edit components share the pattern.
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/extensions/tests/ExtensionTenantUserCreateTest.php app-modules/call-blocks/tests/TenantUserCreateTest.php`
Expected: FAIL — `tenantId` is required, never rendered and never derived for tenant users.

- [ ] **Step 3: Fix the root cause in `BaseEditComponent`**

```php
protected function loadTenants(): void
{
    if ($this->isAdminGuard()) {
        // unchanged: load the list and preselect the default tenant
        return;
    }

    $this->tenants = new Collection;
    $this->tenantId = $this->resolveTenantId();
}

/**
 * Re-derive the tenant on every Livewire request. Public properties are
 * client-writable after mount, so a non-admin `tenantId` must never be
 * trusted from the payload.
 */
public function hydrate(): void
{
    if (! $this->isAdminGuard()) {
        $this->tenantId = $this->resolveTenantId();
    }
}

/**
 * Replace a client-supplied tenant after Livewire applies the update.
 *
 * `hydrate()` runs before the payload is applied and `updatingTenantId()` runs
 * before Livewire assigns the value (`HandleComponents::updateProperty()` calls
 * the update hooks and then sets the property), so neither can re-pin it.
 * `updatedTenantId()` runs immediately after the assignment and before the
 * action — the only hook that can make the value stick.
 */
public function updatedTenantId(mixed $value): void
{
    if (! $this->isAdminGuard()) {
        $this->tenantId = $this->resolveTenantId();
    }
}
```

- [ ] **Step 4: Harden `ExtensionsEdit`'s own rules**

```php
$tenantId = $this->resolveTenantId();
$uniqueRule = Rule::unique('extensions', 'extension_number')->where('tenant_id', $tenantId);
// ...
'tenantId' => $this->isAdminGuard() ? ['required', 'exists:tenants,id'] : ['nullable'],
```

Keep the `ignore($this->extensionId)` branch for updates.

- [ ] **Step 5: Fail closed at the write boundary**

At the top of `save()`, `abort(403)` when `$this->resolveTenantId()` is `null`. Persist `'tenant_id' => $this->resolveTenantId()` (already the case) and never read `$this->tenantId` for a non-admin. All three layers stay: `hydrate()` repairs a stale snapshot, `updatedTenantId()` re-pins a crafted payload after Livewire assigns it, and the write boundary ignores the property entirely — any one alone leaves a gap the request lifecycle can reach.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/extensions/tests app-modules/call-blocks/tests tests/Feature/Architecture`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/BaseEditComponent.php app-modules/extensions/src/Livewire/ExtensionsEdit.php app-modules/extensions/tests/ExtensionTenantUserCreateTest.php app-modules/call-blocks/tests/TenantUserCreateTest.php
git commit -m "fix(extensions): derive the tenant from context in every edit form"
```

### Task 4.3: Bound user assignment to the extension's tenant (C05)

**Files:**
- Modify: `app-modules/extensions/src/Services/ExtensionService.php:79-84`
- Modify: `app-modules/extensions/src/Livewire/ExtensionsEdit.php:118-119`, `:136-137`
- Modify: `app-modules/extensions/lang/{en,es,fr}/admin.php`
- Test: `app-modules/extensions/tests/ExtensionUserMembershipTest.php` (new)

**Interfaces:**
- Consumes: `App\Models\Tenant::users(): BelongsToMany`.
- Produces: `syncUsers()` throws `ValidationException` on `selectedUserIds` for users outside `$extension->tenant_id`.

- [ ] **Step 1: Write the failing tests**

```php
it('rejects a user who belongs only to another tenant', function (): void {
    expect(fn () => app(ExtensionServiceInterface::class)->syncUsers($extension, [$foreignUser->id]))
        ->toThrow(ValidationException::class);
});

it('accepts a member of the extension tenant and a user shared with two tenants', function (): void {
    $service->syncUsers($extension, [$member->id, $sharedUser->id]);
    expect($extension->fresh()->users)->toHaveCount(2);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest app-modules/extensions/tests/ExtensionUserMembershipTest.php`
Expected: FAIL — the foreign user is attached.

- [ ] **Step 3: Validate at the service boundary**

```php
$allowed = $extension->tenant->users()->whereKey($userIds)->pluck('users.id')->all();
$foreign = array_values(array_diff($userIds, $allowed));

if ($foreign !== []) {
    throw ValidationException::withMessages([
        'selectedUserIds' => [__('admin.extension_selected_users_not_in_tenant')],
    ]);
}
```

Enforce it in the service, not only in the Livewire rule, so direct callers are bound by it.

- [ ] **Step 4: Add the translation key to all three locales**

`'extension_selected_users_not_in_tenant' => '…'` in `app-modules/extensions/lang/en/admin.php`, `es/admin.php`, and `fr/admin.php`. The module dictionary merges into the global `admin` group, so the **prefix** prevents a collision with root keys and the call site uses `__('admin.extension_…')` — never the `extensions::admin.*` namespace syntax. The module header states both rules.

- [ ] **Step 5: Remove the unused global user state**

Delete `public Collection $users` and the `User::query()->orderBy('name')->get()` call in `mount()`; the Blade template never renders it, so it is dead global state and cross-tenant data in the payload. Keep `$selectedUserIds` and its rules.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest app-modules/extensions/tests tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app-modules/extensions/src/Services/ExtensionService.php app-modules/extensions/src/Livewire/ExtensionsEdit.php app-modules/extensions/lang app-modules/extensions/tests/ExtensionUserMembershipTest.php
git commit -m "fix(extensions): require tenant membership for user assignment"
```

### Task 4.4: Isolate a permanently invalid CDR instead of blocking the batch (O08)

**Files:**
- Modify: `app/Services/Cdr/CdrBatchWriter.php:39-51`
- Modify: `app/Services/Cdr/CdrRedisIngest.php:74-104`
- Modify: `app/Console/Commands/CdrDrainSpoolCommand.php:91-107`
- Test: `tests/Feature/Services/CdrPoisonRecordTest.php` (new)
- Test: `tests/Feature/Console/CdrDrainSpoolCommandTest.php`

**Interfaces:**
- Produces: `CdrBatchWriter::write(array $rows): array` — returns the `id` values that were **not** written.
- Consumes: `CdrPayloadParser::quarantine(string $entryId, string $rawJson): bool`, and the spool command's existing quarantine path (`CdrDrainSpoolCommand` L76-83).

- [ ] **Step 1: Write the failing tests**

```php
it('commits the valid rows and reports the one the database rejects', function (): void {
    $rows = [validRow('u1'), invalidRow('u2'), validRow('u3')];

    $failed = app(CdrBatchWriter::class)->write($rows);

    expect($failed)->toBe([$rows[1]['id']])
        ->and(Cdr::whereIn('call_uuid', ['u1', 'u3'])->count())->toBe(2);
});

it('quarantines and removes a poison entry while later entries keep flowing', function (): void {
    // Subclass CdrBatchWriter to throw a QueryException with SQLSTATE 23000 for
    // one id; seed two stream entries; call digest(); assert one quarantine
    // file, one committed row, and getPendingCount() === 0.
});

it('quarantines a rejected spool file instead of deleting it', function (): void {
    // Two spool files, one poison. Run cdr:drain-spool; assert the valid file
    // is removed, the poison file is moved into the quarantine directory, and
    // neither row is lost.
});

it('keeps the original owner when a record is replayed', function (): void {
    Cdr::create(rowFor('u9', tenant: $this->tenant));

    app(CdrBatchWriter::class)->write([rowFor('u9', tenant: $this->otherTenant)]);

    expect(Cdr::withoutGlobalScope('tenant')->where('call_uuid', 'u9')->value('tenant_id'))
        ->toBe($this->tenant->id);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Services/CdrPoisonRecordTest.php`
Expected: FAIL — `write()` returns `void` and the whole batch rolls back.

- [ ] **Step 3: Distinguish deterministic failures from transient ones**

Keep the batch transaction. Catch `Illuminate\Database\QueryException` and evaluate `(string) $exception->getCode()`: when it is `23000` (integrity constraint) or starts with `22` (data exception), roll back and retry **row by row** in individual transactions, collecting failed `id`s. Re-throw every other `QueryException` unchanged so an outage keeps all entries.

- [ ] **Step 4: Make the owner write-once on replay**

`Cdr::upsert($rows, ['call_uuid','leg'], self::UPDATE_COLUMNS)` lists `tenant_id` among the columns it refreshes, so a delayed or replayed payload with a different resolved tenant would move an existing record into another tenant's CDR — the C02 defect returning through the replay path. Remove `tenant_id` from `UPDATE_COLUMNS`; ownership is assigned once, at first insert. The Step 1 replay test pins it. If a corrected attribution must ever be applied, it goes through an explicit, audited command, never a replay.

- [ ] **Step 5: Quarantine the reported rows in the digest loop**

`digest()` maps the returned ids back to their stream entries and `quarantine()`s each original payload; only entries whose quarantine copy succeeded are added to the `xdel` list. Entries that could not be quarantined stay in the stream for the next cycle. The commit-before-delete guarantee is unchanged.

- [ ] **Step 6: Keep the spool drain lossless**

`CdrDrainSpoolCommand` is the second caller of `write()`: it currently wraps the call in `try/catch` and, on success, `@unlink`s **every** file in `$drainable`. Once `write()` returns ids instead of throwing, a poison file would be deleted without ever reaching MariaDB or the quarantine directory. Change it to:

- Build a `$rowIdByFile` map while parsing, so each spool file is addressable by its row `id`.
- Capture `$failedIds = $writer->write($rows)`.
- `unlink` only files whose id is not in `$failedIds`; move the rest into the same quarantine directory the unparseable path already uses (L76-83) and count them as `$quarantined`.
- Keep `return self::FAILURE` for a thrown `Throwable` (database unavailable), so every file survives an outage.

- [ ] **Step 7: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Services/CdrPoisonRecordTest.php tests/Feature/Services/CdrRedisIngestDigestTest.php tests/Feature/Console/CdrDrainSpoolCommandTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Cdr/CdrBatchWriter.php app/Services/Cdr/CdrRedisIngest.php app/Console/Commands/CdrDrainSpoolCommand.php tests/Feature/Services/CdrPoisonRecordTest.php tests/Feature/Console/CdrDrainSpoolCommandTest.php
git commit -m "fix(cdr): isolate deterministic database rejections without losing spooled records"
```

---

## Package 5 — Verification integrity

### Task 5.1: Include module implementation in coverage scope (T02)

**Files:**
- Modify: `phpunit.xml:21-25`
- Test: `tests/Feature/TestEnvironmentSafetyTest.php`

**Interfaces:**
- Produces: `<source>` includes `app` and `app-modules/*/src`.

- [ ] **Step 1: Add the tripwire assertion**

```php
it('includes built-in module implementation in coverage scope', function (): void {
    $xml = simplexml_load_file(base_path('phpunit.xml'));
    $directories = array_map('strval', $xml->xpath('//source/include/directory') ?: []);

    expect($directories)->toContain('app')
        ->and($directories)->toContain('app-modules/*/src');
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php --filter="coverage scope"`
Expected: FAIL — only `app` is listed.

- [ ] **Step 3: Add the source directory**

```xml
<source>
    <include>
        <directory>app</directory>
        <directory>app-modules/*/src</directory>
    </include>
</source>
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml tests/Feature/TestEnvironmentSafetyTest.php
git commit -m "test(coverage): include built-in module sources in the coverage scope"
```

### Task 5.2: Correct the CI intent and add a release checklist (T03)

**Files:**
- Modify: `.github/workflows/tests.yml:1-26`
- Create: `docs/release-checklist.md`
- Modify: `AGENTS.md` (Mandatory Pre-Commit Verification Gate section)

**Interfaces:**
- Produces: a documented, reproducible release procedure naming the tested SHA.

- [ ] **Step 1: Correct the workflow header**

Replace the "on the main and release branches and on every pull request … automated enforcement" paragraph with an accurate description: the workflow is manual (`workflow_dispatch`) environment-parity verification; local verification on the appliance is the gate. Leave the `on:` block unchanged.

- [ ] **Step 2: Write `docs/release-checklist.md`**

Sections: scope of the release; the exact commit SHA under test; commands run (`tpbx optimize:clear`, `./vendor/bin/pint --dirty --test`, `tpbx test --compact --parallel`, `./vendor/bin/pest tests/Browser` for UI overhauls); environment (PHP version, DB engine); results with pass/fail counts; confirmation that the tag points at the tested SHA; the disposable MariaDB exercise for restore/CDR changes; and the statement that verification is local, not CI-gated.

- [ ] **Step 3: Link it from `AGENTS.md`**

In the "Mandatory Pre-Commit Verification Gate" section, add one line pointing at `docs/release-checklist.md` for releases.

- [ ] **Step 4: Verify the workflow still parses**

Run: `php -r 'echo yaml_parse_file(".github/workflows/tests.yml") === false ? "invalid" : "valid";'`
Expected: `valid` (install `ext-yaml` if the function is unavailable; otherwise inspect with `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/tests.yml'))"`).

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/tests.yml docs/release-checklist.md AGENTS.md
git commit -m "docs(ci): describe manual parity runs and add a release checklist"
```

---

## Package 6 — Documentation corrections

Tasks 6.2 and 6.4 carry small configuration and command corrections, because the documentation was describing behaviour the code does not implement. Run `./vendor/bin/pint --dirty` after those two land. Every task ends with a link check.

### Task 6.1: Reconcile firewall guidance with the safety rules (D01)

**Files:**
- Modify: `docs/load-testing-guide.md:52-58`
- Modify: `AGENTS.md:160`

**Interfaces:** none.

- [ ] **Step 1: Replace the raw mutation recipe**

In both files, replace `sudo nft add element inet tallpbx_filter whitelist_ips { <PBX_IP> }` with the managed path: add the generator address through **Security Center → Firewall → Whitelist** (or `tpbx security:apply` after persisting the entry), then verify with `nft list set inet tallpbx_filter whitelist_ips`. Keep the note that a dedicated generator VM with an open `INPUT ACCEPT` policy needs nothing.

- [ ] **Step 2: Scope the statement per host role**

State explicitly that the *PBX* whitelist is managed by TallPBX and an ad-hoc `nft` element is discarded by the next apply, while a non-TallPBX generator with its own firewall is configured with that host's own tooling — never by copying a command into a TallPBX-managed host.

- [ ] **Step 3: Verify no ordinary recipe calls raw nft**

Run: `grep -rnE "nft (add|insert|delete|flush)" docs AGENTS.md load-testing/README.md`
Expected: only the clearly labelled lockout-recovery commands in `docs/operations.md` and the prohibition text remain.

- [ ] **Step 4: Commit**

```bash
git add docs/load-testing-guide.md AGENTS.md
git commit -m "docs(firewall): route load-generator whitelisting through the managed pipeline"
```

### Task 6.2: Correct the environment reference (D02)

**Files:**
- Modify: `docs/env-reference.md:89-90`, `:129-130`, `:614`
- Modify: `config/freeswitch.php:68` (`'server' =>`)
- Test: `tests/Feature/Config/FreeswitchConfigTest.php` (extend)

**Interfaces:** none.

- [ ] **Step 1: Verify each disputed key against code and installer**

For `FS_SERVER`, `FS_DEFAULT_SIP_REALM`, `FS_XML_HANDLER_ACL_CACHE_*`, and the provisioning authentication row, read `config/freeswitch.php`, `config/provisioning.php`, the provisioning middleware, and the installer templates that write `.env`. Record the actual default, the installer-written value, and the fallback separately.

- [ ] **Step 2: Rewrite the two `FS_SERVER`/realm rows**

Describe `FS_SERVER` as the SIP registrar hostname/IP used by provisioning templates, with the `APP_URL` fallback stated as a fallback (not as recommended phone configuration). State `FS_DEFAULT_SIP_REALM` has **no** config default (`env('FS_DEFAULT_SIP_REALM')`) and is written by the installer.

- [ ] **Step 3: Fix the `APP_URL` fallback in code**

`'server' => env('FS_SERVER', env('APP_URL', 'pbx.example.com'))` hands a full URL (`https://pbx.example.com`) to a consumer that expects a hostname, so a host without `FS_SERVER` produces an unusable registrar. Change it to:

```php
'server' => env('FS_SERVER') ?: (parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'pbx.example.com'),
```

Add the three cases to the existing [tests/Feature/Config/FreeswitchConfigTest.php](../tests/Feature/Config/FreeswitchConfigTest.php), which already boots the container and defines `setFsTestEnv()`: `FS_SERVER` set wins; a URL-shaped `APP_URL` yields a bare host; both absent yields `pbx.example.com`. Do **not** create `tests/Unit/Config/FreeswitchServerFallbackTest.php`: `tests/Pest.php` binds `Tests\TestCase` to `Feature`, `app-modules`, `Unit/Jobs` and three named Unit/Services files only, so a new `Unit/Config` directory gets the bare PHPUnit case and `config()` is unavailable. Refresh the existing fallback assertion in that file in the same step. Documenting the fallback without fixing it would leave the same bad value in every generated phone template.

- [ ] **Step 4: Rename the ACL cache keys**

Use `freeswitch.xml_handler.acl_cache_store` / `acl_cache_ttl` to match the code.

- [ ] **Step 5: Match the provisioning authentication description to the middleware**

Describe only what the inspected middleware implements; do not advertise Digest if only Basic is enforced.

- [ ] **Step 6: Verify the claims mechanically**

Run: `grep -rnE "acl_cache|configuration_cache" docs/env-reference.md config/freeswitch.php`
Expected: both files name `acl_cache_*` only, and the server line no longer passes a scheme through.

- [ ] **Step 7: Commit**

```bash
git add docs/env-reference.md config/freeswitch.php tests/Feature/Config/FreeswitchConfigTest.php
git commit -m "docs(env): align the reference with configuration, installer and middleware"
```

### Task 6.3: Stop promising automatic SIP credentials (D03)

**Files:**
- Modify: `docs/pbx-concepts.md:75`, `:96-120`

**Interfaces:** none.

- [ ] **Step 1: Correct the concept and the walkthrough**

Replace "TallPBX automatically creates and secures these credentials for you" with an explanation that the extension form asks for the SIP password and creates the account only when one is supplied, and that a virtual/routing-only extension deliberately has no registration account. In the Alice walkthrough, add the password step.

- [ ] **Step 2: Verify no doc claims automatic generation**

Run: `grep -rnE "automatic(ally)? (create|generat)" docs README.md`
Expected: no remaining claim about SIP credential generation.

- [ ] **Step 3: Commit**

```bash
git add docs/pbx-concepts.md
git commit -m "docs(concepts): match the extension form's credential behaviour"
```

### Task 6.4: Repair load-testing drift and separate current from historical (D05, D06)

**Files:**
- Modify: `load-testing/procedure.md:63-64`, `:109`
- Modify: `load-testing/configurations.md:3`, `:125-129`
- Modify: `load-testing/README.md:110`
- Modify: `load-testing/status.md`
- Modify: `docs/telephony-capacity-scaling-guide.md`
- Modify: `app/Console/Commands/PbxLoadTestSeedCommand.php:42`, `:61`
- Test: `tests/Feature/Commands/PbxLoadTestSeedDomainTest.php` (new)

**Interfaces:** none.

- [ ] **Step 1: Remove the literal realm default from the seeder**

`PbxLoadTestSeedCommand` declares `{--domain=load.test.local}`, so deleting the flag from the procedure changes nothing. Change the option to `{--domain= : SIP realm/domain for the synthetic tenant (defaults to FS_DEFAULT_SIP_REALM)}` and resolve it as `$this->option('domain') ?: config('freeswitch.default_sip_realm')`, failing with a clear message when both are empty. Add `PbxLoadTestSeedDomainTest` asserting the config value is used and that an empty realm is refused.

- [ ] **Step 2: Pass the real realm in the procedure**

Replace `--domain=load.test.local` with `--domain="$PBX_HOST"` (or omit it entirely now that the seeder falls back to the configured realm) and state why the literal breaks registration.

- [ ] **Step 3: Fix the variant names and the flag**

List the five variants that exist in `load-testing/matrix.php`: `v-stock-dynamic`, `v-stock-static`, `v-fast`, `v-fast-default`, `v-octane`. Replace `--variants a,b` with one `--variant=<name>` per invocation, or the documented filter form the runner actually parses.

- [ ] **Step 4: Separate current status from history, including the scaling guide**

Add a "Current support status" block at the top of `status.md` stating supported serving modes, measured tiers, and open work. Move superseded series below a dated heading. Where the plan table says `9 — CDR transport benchmarks | Not started`, keep it but make clear it is a distinct deliverable from the completed fast-entry work — the audit's D06 example conflated the two.

Then apply the same separation to `docs/telephony-capacity-scaling-guide.md`, which the audit named explicitly: keep the historical Fast Controller `~1.5 CPS` failure tables under a dated heading, and label the `30-60+ CPS` figures as unmeasured projections at the point of use, not only in a preamble.

- [ ] **Step 5: Verify every documented flag parses**

Run: `grep -rnE "^[[:space:]]*--variant" load-testing/procedure.md load-testing/README.md`
Expected: no `--variants`, and every named variant exists in `matrix.php`.

- [ ] **Step 6: Commit**

```bash
git add load-testing/procedure.md load-testing/configurations.md load-testing/README.md load-testing/status.md docs/telephony-capacity-scaling-guide.md app/Console/Commands/PbxLoadTestSeedCommand.php tests/Feature/Commands/PbxLoadTestSeedDomainTest.php
git commit -m "docs(load-testing): align invocations with the matrix and split current status from history"
```

### Task 6.5: Repair moved source links and the module-route convention (D07, D10)

**Files:**
- Modify: `docs/architecture.md:659`, `:676`
- Modify: `.agents/skills/tallpbx-custom/SKILL.md:724`, `:727-731`, `:913`, `:949`
- Modify: `CHANGELOG.md:91`
- Modify: `docs/telephony-capacity-scaling-guide.md` (the `config/freeswitch.php` link)
- Test: `tests/Feature/Commands/MakeModuleScaffoldTest.php` (new)

**Interfaces:** none.

- [ ] **Step 1: Fix every broken local link, not just the two moved services**

Point the two architecture links at `app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php` and `.../Firewall/LockoutGuardService.php`. Then repair the rest of the verified set, resolving each relative to **its own file's directory**:

- `CHANGELOG.md:91` links to `docs/load-testing-refresh-implementation-plan.md`, which was deleted; point it at `load-testing/README.md` or mark it historical.
- `.agents/skills/tallpbx-custom/SKILL.md:913` and `:949` link to `README.md`, `INSTALL.md`, `CHANGELOG.md` and `docs/architecture.md` as if from the repository root; from that directory they need `../../../`.
- `docs/telephony-capacity-scaling-guide.md` links `config/freeswitch.php` without `../`.

Leave archived historical documents alone beyond labelling them.

- [ ] **Step 2: Replace the route auto-registration and translation prose**

State that every built-in module declares an explicit `routes/web.php` (enforced by `ModuleArchitectureTest`), and that auto-registration is retired. Keep the list of conditions under which a module adds *extra* routes. Then fix the adjacent contradiction at `SKILL.md:724`, which still shows `$this->loadTranslationsFrom(__DIR__.'/../../lang', 'email-connector')`; the modern contract is `protected function translationPaths(): array` with prefixed keys read through `__('admin.<prefix>_*')`.

- [ ] **Step 3: Add the scaffold compliance test**

`D10`'s acceptance requires proving the instructions produce a conforming module, but the scaffold it targets is a **custom** module, not a built-in one. [MakeModuleCommand.php](../app/Console/Commands/MakeModuleCommand.php) hardcodes `base_path("custom-modules/{$name}")` (L53), calls `Artisan::call('module:cache')` (L83), creates no `routes/` directory (L107-120), and emits a provider with only `moduleName()`/`moduleNamespace()` (L164-198) and an empty `return [ // ];` language file (L224-238). The `routes/web.php` requirement in `ModuleArchitectureTest` scans `glob(base_path('app-modules/*'))` only.

So scope the test to what the scaffold actually guarantees, and assert the built-in convention separately:

1. **Scaffold (custom module):** rebind the base path to a temporary directory (the technique `BackupsRouteIndependenceTest:145` uses), run `make:module`, and assert `module.json`, `src/Providers/ModuleServiceProvider.php` with `moduleName()`/`moduleNamespace()`, `lang/{en,es,fr}/admin.php`, `database/migrations`, and `config` exist. Delete the directory and clear `bootstrap/cache/modules.php` in `finally`.
2. **Built-in convention:** a second assertion iterates `app-modules/*` and requires `routes/web.php` — the same rule the architecture test already enforces, restated here so the skill's prose is pinned to a test.

Do **not** extend `MakeModuleCommand` in this task. Changing the scaffold to satisfy a test would create a custom-module convention that no architecture test enforces.

- [ ] **Step 4: Verify both classes exist at the linked path**

Run: `ls app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php app-modules/security/src/Services/Firewall/LockoutGuardService.php`
Expected: both listed.

- [ ] **Step 5: Verify the skill and links**

Run: `grep -rnE "no .routes/web.php. file needed|auto-regist|loadTranslationsFrom" .agents/skills/tallpbx-custom/SKILL.md`
Expected: no match.

- [ ] **Step 6: Commit**

```bash
git add docs/architecture.md .agents/skills/tallpbx-custom/SKILL.md CHANGELOG.md docs/telephony-capacity-scaling-guide.md tests/Feature/Commands/MakeModuleScaffoldTest.php
git commit -m "docs(dev): fix every broken local link and the module conventions"
```

---

## Package 7 — Reader journeys

### Task 7.1: Write the desk-phone provisioning recipe (D04)

**Files:**
- Create: `docs/provisioning-guide.md`
- Modify: `docs/pbx-concepts.md:86-92`, `docs/README`-equivalent navigation (`README.md`)

**Interfaces:** none.

- [ ] **Steps 1–4**

Write the recipe: enable provisioning (`PROVISIONING_ENABLED=true`) and restart, confirm the route/access middleware conditions, assign a template and a SIP account to the device, set the provisioning endpoint on the phone, then the expected download and registration result. Add 404/401 troubleshooting and a "manual softphone remains the shortest first-call path" note. Link it from the zero-touch paragraph and from the README navigation.

- [ ] **Step 5: Verify the documented settings exist**

Run: `grep -rnE "PROVISIONING_ENABLED|provisioning" config/provisioning.php docs/provisioning-guide.md | head`
Expected: the guide names only keys present in the config.

- [ ] **Step 6: Commit**

```bash
git add docs/provisioning-guide.md docs/pbx-concepts.md README.md
git commit -m "docs(provisioning): add the supported desk-phone recipe"
```

### Task 7.2: Write the backup and restore operator journey (D08)

**Files:**
- Modify: `docs/operations.md` (new section after "Service Management")
- Create: `docs/backup-restore-guide.md`

**Interfaces:** none.

- [ ] **Steps 1–5**

After Tasks 2.1–2.8 land, document: what each scope contains and excludes; where backups are stored and the encryption/key dependency; how to verify a backup; scope selection; expected downtime; where progress and results appear (panel + `sudo tallpbx-restore-status`); the journal location; the two time bounds (15 minutes per command, 1 hour for the operation); failure recovery including a failed post-import restore; restoration onto a replacement host (`appliance:sync-host`); and, at each stage, whether rollback is automatic, manual, or unavailable.

- [ ] **Step 6: Verify the commands in the guide exist**

Run: `tpbx list backups`
Expected: `backups:restore-operation` and `backups:reconcile-restore-journal` are listed, and `sudo tallpbx-restore-status` runs without a database connection.

- [ ] **Step 7: Commit**

```bash
git add docs/operations.md docs/backup-restore-guide.md
git commit -m "docs(operations): document the complete backup and restore journey"
```

### Task 7.3: Add the audience map and developer entry guide (D09)

**Files:**
- Modify: `README.md`
- Create: `docs/developer-guide.md`
- Modify: `docs/pbx-hello-world.md` (move the protocol diagram below the first practical step)

**Interfaces:** none.

- [ ] **Steps 1–4**

Add a "Start here" table mapping reader task → entry document → authoritative detail, per §7 of the audit. Write the developer guide from `AGENTS.md` plus the architecture guide: reproducible setup, module contracts, tenancy and authorization rules, the verification commands, and where not to look (agent-only skills). Move the SIP pipeline diagram in the first-call guide below the first actionable step.

- [ ] **Step 5: Verify every linked path resolves relative to its own file**

```bash
find README.md docs .agents -name '*.md' -print0 | while IFS= read -r -d '' file; do
  dir=$(dirname "$file")
  grep -oE '\]\([^()]+\)' "$file" | sed 's/^](//;s/)$//' | while read -r target; do
    case "$target" in http*|https*|mailto:*|'#'*|file://*) continue;; esac
    path=${target%%#*}
    [ -e "$dir/$path" ] || echo "MISSING $file -> $target"
  done
done
```

Expected: no `MISSING` lines, and `grep -rn 'file:///' README.md docs .agents` returns nothing. Three details matter here: resolve from `dirname` of each file, never the repository root, or `docs/architecture.md` links of the form `config/freeswitch.php` falsely pass; match **every** relative link, not only `.md`; and strip the `#anchor` before testing the path — excluding `#` from the match skips anchored source links instead of checking them, which is the opposite of what this step is for.

- [ ] **Step 6: Commit**

```bash
git add README.md docs/developer-guide.md docs/pbx-hello-world.md
git commit -m "docs: add an audience map and a developer entry guide"
```

---

## Package 8 — Targeted cleanup

### Task 8.1: Consolidate shared view-name resolution (M02)

**Files:**
- Create: `app/Support/ModuleViewName.php`
- Modify: `app/Support/BaseEditComponent.php:126-156`
- Modify: `app/Support/BaseListComponent.php:78-101`
- Test: `tests/Unit/Support/ModuleViewNameTest.php` (new)

**Interfaces:**
- Produces: `ModuleViewName::for(string $className): string` — identical output to today's duplicated logic.

- [ ] **Step 1: Write the failing test**

```php
it('resolves module, legacy and custom-module view names identically', function (string $class, string $expected): void {
    expect(ModuleViewName::for($class))->toBe($expected);
})->with([
    'app-module' => [Modules\Bridges\Livewire\BridgesEdit::class, 'bridges::bridges-edit'],
    'legacy path' => ['Modules\Bridges\Http\Livewire\BridgesEdit', 'bridges::livewire.bridges-edit'],
]);
```

The legacy case uses a string because no legacy class exists in the tree; `for()` reflects on the file path, so the test writes a temporary stub file when the class is not autoloadable. If that is impractical, make the path-to-view mapping `ModuleViewName::fromPath(string $filePath): string` and test it directly with representative paths.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/Support/ModuleViewNameTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Extract the duplicated resolver**

Move the regex, capture-group handling, `snake('-')` conversion, and legacy prefix into `ModuleViewName`. Both base classes call it. Do not remove the legacy branch in this task — custom modules under `custom-modules/<name>/src/Livewire` rely on the same unanchored match, and the audit says to confirm supported consumers first.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Unit/Support/ModuleViewNameTest.php tests/Feature/Architecture tests/Feature/Modules`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/ModuleViewName.php app/Support/BaseEditComponent.php app/Support/BaseListComponent.php tests/Unit/Support/ModuleViewNameTest.php
git commit -m "refactor(support): resolve module view names in one place"
```

---

## Deferred decisions

These are **not** in this plan. Each needs a decision recorded before the related package can be called closed.

| Item | Why deferred | Decision needed |
|---|---|---|
| Tenant re-homing of an extension (new) | Product decision: an admin can currently change `tenantId` on an existing extension, which rewrites the SIP account, orphans the old voicemail, keeps cross-tenant user links, and leaves CDR history behind. | Is re-homing allowed at all, and if so which dependent rows move or are copied? |
| Archive symlink/hardlink policy | `assertArchiveIsSafe()` inspects member names only and extraction runs as root. Needs a deliberate allow/deny policy for member types. | Reject non-regular members outright, or extract with `--no-overwrite-dir` plus a post-extraction scan? |

## Execution order and verification

1. Read **Corrections applied after review** first. Where it differs from a task's original wording, the correction wins.
2. Run packages in numeric order; each package is independently shippable.
3. Before each commit, run the targeted Pest files named in that task's steps, and `./vendor/bin/pint --dirty --test` when the task touched PHP. After each package, run `tpbx optimize:clear` and add the `## [Unreleased]` changelog entry in the same commit as the package's last code change.
4. Run the full suite (`tpbx test --compact --parallel`) **once**, before the commit that closes packages 1–4 — the only change set that spans several subsystems. Do not run it after a documentation-only package, and never after every package.
5. Run `./vendor/bin/pest tests/Browser` only for a package that changes a rendered create/edit journey. Package 4 (tenant create flow) is the only candidate here; packages 5–7 change no rendered view.
6. Close `docs/gpt-evaluation.md` findings by ID in the changelog entry, and mark the audit report partially superseded once packages 0–4 are merged. Record in that entry which corrections came from each review document, so the provenance of every fix stays traceable.
