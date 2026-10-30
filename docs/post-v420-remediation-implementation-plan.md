# Post-v4.2.0 Audit Remediation & Release Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to execute this plan (explicitly selected by user — do NOT use inline `superpowers:executing-plans`). Dispatch a fresh implementer subagent per task followed by a fresh task reviewer (spec compliance + code quality), then run one final whole-branch review at the end. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the remaining 60-second `mod_xml_curl` dialplan cache-invalidation gaps (`Gateway` and custom SIP scanner signatures), align `FS_XML_HANDLER_CACHE_TTL=60` across `.env.example`, the installer, and docs, harden `session:flush` against blind flushes and Redis DB collisions, synchronize `INSTALL.md` and `CHANGELOG.md`, and pass the full `v4.3.0` verification gate on `4.x`.

**Architecture:** Extend TallPBX's cache-only `RoutingCacheVersion` revision stamp in two places: attach `RoutingCacheObserver` to `Modules\Gateways\Models\Gateway` for per-tenant outbound/E911 invalidation, and add a zero-DB-query global counter (`RoutingCacheVersion::getGlobal()` / `bumpGlobal()`) bumped by `SipScannerSignatures::saveCustom()` and embedded in dialplan cache keys. Harden `SessionFlushCommand` by resolving the exact storage target before prompting and refusing unforced `flushdb()` calls when the session Redis `(host, port, database)` tuple collides with `default` (`DB 0`) or `cache` (`DB 1`), then align `.env.example`, `scripts/resources/tall.sh`, `INSTALL.md`, `docs/env-reference.md`, and `CHANGELOG.md`.

**Tech Stack:** PHP `^8.5`, Laravel 13, Pest 4 (parallel unit/feature + Playwright browser suite), Redis (`Redis::connection`), FreeSWITCH 1.11 `mod_xml_curl`, Bash (`scripts/resources/tall.sh`).

**Spec:** Self-contained in this plan — consolidates the validated audit and verification findings for the post-`v4.2.0` (`f455fdab..b7cd1837`) commit series.

**Execution Mode:** Subagent-Driven Development (`superpowers:subagent-driven-development`) — fresh implementer subagent per task + per-task spec/quality reviewer + final whole-branch code review (`b7cd1837..HEAD`).

## Global Constraints

- Active development branch is `4.x` (`git branch --show-current` MUST be `4.x`; there is no `main` or `master` branch).
- **Subagent-Driven Execution & SDD Ledger Hygiene** (`tests/Feature/Architecture/SddLedgerHygieneTest.php`): Execute strictly sequentially via `superpowers:subagent-driven-development` (never dispatch parallel implementers — Tasks 1 and 2 share `tests/Feature/Observers/RoutingCacheObserverTest.php`, and implementer subagents must never spawn nested subagents). Immediately initialize `<workspace>/progress.md` with `# SDD ledger — plan: docs/post-v420-remediation-implementation-plan.md` upon creating the SDD workspace so `.superpowers/sdd/` never contains an empty subdirectory, and delete `<workspace>` (or annotate `> **Archived:**` and index it in `.superpowers/sdd/progress.md`) when retiring this plan file.
- Runtime floor is PHP `^8.5`; every edited PHP file must declare `declare(strict_types=1);` and pass `./vendor/bin/pint --dirty --test`.
- **Zero-DB-query `mod_xml_curl` cache-hit contract** (`app/Support/RoutingCacheVersion.php:17-18`): dialplan and contributor cache-key builders MUST read exclusively from cache (`RoutingCacheVersion`) and MUST NEVER query MariaDB/SQLite (e.g., never call `SecuritySetting::get()` inside a cache-key builder).
- **Installer script safety** (`.agents/skills/tallpbx-custom/SKILL.md`): `scripts/resources/tall.sh` edits must remain idempotent (`grep -q` guard so re-runs never clobber explicit operator overrides in `.env`), include plain-language comments explaining why the setting is written, and pass `bash -n scripts/resources/tall.sh`.
- **Documentation & unit standards**: Use `tpbx <command>` for interactive appliance CLI examples (retaining `php artisan` in non-interactive scripts/systemd units and noting equivalence on first mention in `INSTALL.md`), use decimal storage/memory units (`GB`, `MB`), and obfuscate any public IPs.
- **Cache clearing & test discipline**: Run `tpbx optimize:clear` after code/config/view changes; run targeted Pest files per task; run the full `tpbx test --compact --parallel` suite at most **once** in Task 6. Never mutate live `nftables` state, never create git tags, and never run `git push` without explicit user instruction.

## Review Focus

1. **E911 & outbound gateway failover/disable during a 60s dialplan cache window:** Editing an `emergency` `Gateway` host/port or disabling an outbound trunk `Gateway` (`enabled = false`) without touching `Emergency` or `OutboundRoute` must immediately invalidate both cached `DialplanXmlCollector` contributor fragments and `DialplanXmlBuilder` dialplan responses on the very next `mod_xml_curl` lookup *(pinned in Task 1)*.
2. **Hidden SQL queries on cached `mod_xml_curl` hits or cross-tenant stale scanner signatures:** Saving custom SIP scanner signatures via `SipScannerSignatures::saveCustom()` must immediately invalidate cached public dialplans across *all* tenants, while repeated cached `/api/v1/xml-handler` dialplan requests must execute **zero** database queries (`DB::getQueryLog() === []`) *(pinned in Task 2)*.
3. **Installer re-run clobbering intentional operator `.env` overrides:** Fresh installs must receive `FS_XML_HANDLER_CACHE_TTL=60`, while re-running `tall.sh` on a server where an administrator explicitly set a custom `FS_XML_HANDLER_CACHE_TTL` in `.env` must leave that custom value untouched *(pinned in Task 3)*.
4. **`session:flush` wiping application locks (`DB 0`) or telephony caches (`DB 1`) on misconfigured `.env`:** When `database.redis.{session_connection}` resolves to the same `(host, port, database)` as `database.redis.default` or `database.redis.cache`, `session:flush` without `--force` must emit a clear collision error, exit `Command::FAILURE`, and **never** invoke `flushdb()`, while `--force` explicitly overrides *(pinned in Task 4)*.
5. **Post-`v4.2.0` mini-rail `<x-tooltip>` Alpine binding (`64e58a7c`) & decomposed Blade views in a real browser:** `resources/views/layouts/app.blade.php` (`x-bind:data-tip` / `x-bind:aria-label`) and the decomposed Certificate Manager / Security Center views must render cleanly with zero browser console/Alpine syntax errors under Playwright *(pinned in Task 6)*.

---

## File Structure

- `app/Providers/AppServiceProvider.php` — Registers `RoutingCacheObserver` across telephony Eloquent models (`registerRoutingCacheObservers()`). Add `Modules\Gateways\Models\Gateway`.
- `app/Support/RoutingCacheVersion.php` — Cache-only routing revision counter. Add `globalKey()`, `bumpGlobal()`, and `getGlobal()` alongside the per-tenant methods.
- `app/Services/DialplanXmlCollector.php` — Context-wide contributor fragment cache (`contributorCacheKey()`). Embed `RoutingCacheVersion::getGlobal()`.
- `app/Services/Xml/DialplanXmlBuilder.php` — Full dialplan response cache (`dialplanCacheKey()`). Embed `RoutingCacheVersion::getGlobal()`.
- `app-modules/security/src/Support/SipScannerSignatures.php` — Persists custom SIP scanner User-Agent signatures (`saveCustom()`). Bump `RoutingCacheVersion::bumpGlobal()` after persisting.
- `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php` — Public-context SIP scanner dialplan guard. Update class docblock to reflect immediate global revision invalidation.
- `.env.example`, `scripts/resources/tall.sh`, `docs/env-reference.md`, `docs/load-testing-guide.md` — Align `FS_XML_HANDLER_CACHE_TTL` default from `5` to `60` (leave historical empirical benchmark record `docs/load-testing-results.md` untouched).
- `app/Console/Commands/SessionFlushCommand.php` — Driver-aware session invalidation CLI. Add pre-confirmation target descriptor (`describeTarget()`) and Redis DB collision guard (`detectRedisDbCollision()`).
- `INSTALL.md`, `CHANGELOG.md` — Document PHP `8.5` floor, `tpbx` / `tallpbx` CLI wrapper parity, `FS_XML_HANDLER_CACHE_TTL=60` + `.env` upgrade note, `tpbx permissions:sync` in manual upgrades, and `[Unreleased]` entries for `v4.3.0`.
- `tests/Feature/Observers/RoutingCacheObserverTest.php`, `app-modules/security/tests/SipScannerSignaturesTest.php`, `tests/Feature/XmlHandlerCacheConfigTest.php`, `tests/Feature/Console/SessionFlushCommandTest.php` — Targeted Pest regression coverage for Tasks 1–4.

---

### Task 1: Observe `Gateway` in `RoutingCacheObserver` for Outbound & E911 Dialplan Invalidation

**Files:**
- Modify: `app/Providers/AppServiceProvider.php:92-95,388-420`
- Test: `tests/Feature/Observers/RoutingCacheObserverTest.php`

**Interfaces:**
- Consumes: `Modules\Gateways\Models\Gateway` (`tenant_id`, `name`, `host`, `port`, `enabled`), `App\Observers\RoutingCacheObserver`, `App\Support\RoutingCacheVersion::get(int $tenantId): int`
- Produces: Automatic `RoutingCacheVersion::bump(int $tenantId)` on `Gateway` `saved` and `deleted` events (including cross-tenant `tenant_id` reassignment via `RoutingCacheObserver`)

- [ ] **Step 1: Write the failing `Gateway` observer and E911/outbound cache-invalidation tests in `tests/Feature/Observers/RoutingCacheObserverTest.php`**

Import `Modules\Emergency\Models\Emergency` and `Modules\Gateways\Models\Gateway`, then append two tests to `tests/Feature/Observers/RoutingCacheObserverTest.php`:

```php
it('bumps routing cache version when a gateway is created, updated, disabled, or deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $gateway = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'host' => 'sip1.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $gateway->update(['host' => 'sip2.carrier.test', 'port' => 5080]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $gateway->update(['enabled' => false]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $gateway->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);
});

it('immediately invalidates cached emergency and outbound dialplan XML when a gateway is modified or disabled', function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::flush();

    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '+15551234567',
        'address' => '100 Main St',
    ]);

    $emergencyGw = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'emergency',
        'host' => 'e911-primary.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);

    $trunkGw = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'primary-trunk',
        'host' => 'trunk.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);

    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'dial_pattern' => '^(\d{10})$',
        'gateway_id' => $trunkGw->id,
        'gateway' => null,
        'enabled' => true,
    ]);

    $e911Query = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '911',
    ]);
    $outboundQuery = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '5559876543',
    ]);

    // Warm both 60s caches (full dialplan + context-wide contributor fragments)
    $this->get('/api/v1/xml-handler?'.$e911Query)
        ->assertOk()
        ->assertSee('sofia/external/${destination_number}@e911-primary.carrier.test:5060', false);
    $this->get('/api/v1/xml-handler?'.$outboundQuery)
        ->assertOk()
        ->assertSee("sofia/gateway/{$trunkGw->id}/\$1", false);

    // Update emergency gateway host/port and disable outbound trunk gateway without touching Emergency or OutboundRoute
    $emergencyGw->update(['host' => 'e911-backup.carrier.test', 'port' => 5080]);
    $trunkGw->update(['enabled' => false]);

    // Next mod_xml_curl lookups must immediately reflect both gateway changes
    $this->get('/api/v1/xml-handler?'.$e911Query)
        ->assertOk()
        ->assertSee('sofia/external/${destination_number}@e911-backup.carrier.test:5080', false)
        ->assertDontSee('e911-primary.carrier.test', false);
    $this->get('/api/v1/xml-handler?'.$outboundQuery)
        ->assertOk()
        ->assertDontSee("sofia/gateway/{$trunkGw->id}/\$1", false);
});
```

- [ ] **Step 2: Run the targeted test file to verify the new tests fail**

Run: `./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php`
Expected: FAIL — `RoutingCacheVersion::get((int) $tenant->id)` stays unchanged when `Gateway` is created/updated, and cached `e911-primary.carrier.test` / `sofia/gateway/{$trunkGw->id}` XML remains stale.

- [ ] **Step 3: Register `Gateway::class` in `AppServiceProvider::registerRoutingCacheObservers()`**

In `app/Providers/AppServiceProvider.php`, import `use Modules\Gateways\Models\Gateway;` (alphabetically among `Modules\*` imports) and add `Gateway::class` to `$telephonyModels` inside `registerRoutingCacheObservers()`.

- [ ] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php
```
Expected: Pint passes; all tests in `RoutingCacheObserverTest.php` PASS.

- [ ] **Step 5: Commit Task 1**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Observers/RoutingCacheObserverTest.php
git commit -m "fix(xml): observe Gateway mutations in RoutingCacheObserver for E911 and outbound dialplans"
```

---

### Task 2: Add Global Routing Cache Revision for Custom SIP Scanner Signatures

**Files:**
- Modify: `app/Support/RoutingCacheVersion.php:10-66`
- Modify: `app/Services/DialplanXmlCollector.php:153-168`
- Modify: `app/Services/Xml/DialplanXmlBuilder.php:419-432`
- Modify: `app-modules/security/src/Support/SipScannerSignatures.php:207-220`
- Modify: `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php:27-31`
- Test: `app-modules/security/tests/SipScannerSignaturesTest.php`
- Test: `tests/Feature/Observers/RoutingCacheObserverTest.php`

**Interfaces:**
- Consumes: `App\Support\RoutingCacheVersion::store(): Repository`
- Produces:
  - `App\Support\RoutingCacheVersion::globalKey(): string` (returns `'freeswitch:routing-version:global'`)
  - `App\Support\RoutingCacheVersion::bumpGlobal(): void` (increments `'freeswitch:routing-version:global'` on `self::store()` with the same `is_int($store->increment(...))` fallback used by `bump()`)
  - `App\Support\RoutingCacheVersion::getGlobal(): int` (reads `(int) self::store()->get(self::globalKey(), 0)` — strictly cache-only, never a database query)

- [ ] **Step 1: Write failing tests for global revision bumping, multi-tenant public dialplan invalidation, and zero-DB-query cache hits**

1. In `app-modules/security/tests/SipScannerSignaturesTest.php`, import `App\Support\RoutingCacheVersion` and `Illuminate\Support\Facades\Cache`, then add:

```php
it('bumps the global routing cache version whenever custom scanner signatures are saved', function (): void {
    Cache::flush();
    expect(RoutingCacheVersion::getGlobal())->toBe(0);

    SipScannerSignatures::saveCustom(['BadScanner/1.0']);
    expect(RoutingCacheVersion::getGlobal())->toBe(1);

    SipScannerSignatures::saveCustom([]);
    expect(RoutingCacheVersion::getGlobal())->toBe(2);
});
```

2. In `tests/Feature/Observers/RoutingCacheObserverTest.php`, import `Illuminate\Support\Facades\DB` and `Modules\Security\Support\SipScannerSignatures`, then add:

```php
it('immediately invalidates cached public dialplans across all tenants on custom scanner signature updates while keeping cache hits query-free', function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::flush();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contextA = app(DialplanContext::class)->public((string) $tenantA->id);
    $contextB = app(DialplanContext::class)->public((string) $tenantB->id);

    $queryA = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $contextA,
        'Caller-Destination-Number' => '1000',
    ]);
    $queryB = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $contextB,
        'Caller-Destination-Number' => '1000',
    ]);

    // Warm 60s caches for both tenants prior to adding a custom scanner signature
    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk()->assertDontSee('CustomBot99');
    $this->get('/api/v1/xml-handler?'.$queryB)->assertOk()->assertDontSee('CustomBot99');

    // Verify cached dialplan hit executes ZERO database queries
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk();
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();

    // Save custom global SIP scanner signature — must invalidate both tenants immediately
    SipScannerSignatures::saveCustom(['CustomBot99']);

    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk()->assertSee('CustomBot99');
    $this->get('/api/v1/xml-handler?'.$queryB)->assertOk()->assertSee('CustomBot99');
});
```

- [ ] **Step 2: Run the targeted tests to verify they fail**

Run: `./vendor/bin/pest app-modules/security/tests/SipScannerSignaturesTest.php tests/Feature/Observers/RoutingCacheObserverTest.php`
Expected: FAIL — `Call to undefined method App\Support\RoutingCacheVersion::getGlobal()`.

- [ ] **Step 3: Implement `RoutingCacheVersion::globalKey()`, `bumpGlobal()`, and `getGlobal()`, wire into dialplan cache keys, and call `bumpGlobal()` in `SipScannerSignatures::saveCustom()`**

1. `app/Support/RoutingCacheVersion.php`: Add `public static function globalKey(): string` (`'freeswitch:routing-version:global'`), `public static function bumpGlobal(): void`, and `public static function getGlobal(): int` mirroring `key()`, `bump()`, and `get()` on `self::store()`.
2. `app/Services/DialplanXmlCollector.php`: Append `(string) RoutingCacheVersion::getGlobal()` to the `implode('|', [...])` array in `contributorCacheKey()`.
3. `app/Services/Xml/DialplanXmlBuilder.php`: Append `(string) RoutingCacheVersion::getGlobal()` to the `implode('|', [...])` array in `dialplanCacheKey()`.
4. `app-modules/security/src/Support/SipScannerSignatures.php`: Import `App\Support\RoutingCacheVersion` and call `RoutingCacheVersion::bumpGlobal();` immediately after `SecuritySetting::set(...)` inside `saveCustom()`.
5. `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php`: Update lines 29–30 of the class docblock to state that custom signatures are merged at render time and invalidated immediately via `RoutingCacheVersion::bumpGlobal()`.

- [ ] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest app-modules/security/tests/SipScannerSignaturesTest.php tests/Feature/Observers/RoutingCacheObserverTest.php tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php
```
Expected: Pint passes; all three test files PASS.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/Support/RoutingCacheVersion.php app/Services/DialplanXmlCollector.php app/Services/Xml/DialplanXmlBuilder.php app-modules/security/src/Support/SipScannerSignatures.php app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php app-modules/security/tests/SipScannerSignaturesTest.php tests/Feature/Observers/RoutingCacheObserverTest.php
git commit -m "fix(security): invalidate cached public dialplans immediately on custom scanner signature updates"
```

---

### Task 3: Align `FS_XML_HANDLER_CACHE_TTL=60` Across `.env.example`, Installer, and Environment Docs

**Files:**
- Modify: `.env.example:118-122`
- Modify: `scripts/resources/tall.sh:245-246`
- Modify: `docs/env-reference.md:119,639`
- Modify: `docs/load-testing-guide.md:281-282`
- Test: `tests/Feature/XmlHandlerCacheConfigTest.php`

**Interfaces:**
- Consumes: `config/freeswitch.php` (`env('FS_XML_HANDLER_CACHE_TTL', 60)`)
- Produces: Synchronized `60`-second default across `.env.example`, `scripts/resources/tall.sh` (idempotent seed for fresh installs), `docs/env-reference.md`, and `docs/load-testing-guide.md`

- [ ] **Step 1: Write the failing `.env.example` and installer parity test in `tests/Feature/XmlHandlerCacheConfigTest.php`**

Append to `tests/Feature/XmlHandlerCacheConfigTest.php`:

```php
it('aligns .env.example and the appliance installer script with the 60-second XML handler cache TTL default', function (): void {
    $envExample = (string) file_get_contents(base_path('.env.example'));
    $tallInstaller = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($envExample)
        ->toContain('FS_XML_HANDLER_CACHE_TTL=60')
        ->not->toContain('FS_XML_HANDLER_CACHE_TTL=5')
        ->and($tallInstaller)
        ->toContain('grep -q "^FS_XML_HANDLER_CACHE_TTL=" .env')
        ->toContain('echo "FS_XML_HANDLER_CACHE_TTL=60" >> .env')
        ->not->toContain('FS_XML_HANDLER_CACHE_TTL=5');
});
```

- [ ] **Step 2: Run the targeted test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/XmlHandlerCacheConfigTest.php`
Expected: FAIL — `.env.example` and `scripts/resources/tall.sh` still contain `FS_XML_HANDLER_CACHE_TTL=5`.

- [ ] **Step 3: Update `.env.example`, `scripts/resources/tall.sh`, `docs/env-reference.md`, and `docs/load-testing-guide.md`**

1. `.env.example:118-122`: Update `FS_XML_HANDLER_CACHE_TTL=60` and the four commented subsystem override examples (`# FS_XML_HANDLER_*_CACHE_TTL=60`).
2. `scripts/resources/tall.sh:245-246`: Add a plain-language comment above the `grep -q "^FS_XML_HANDLER_CACHE_TTL="` check explaining that fresh installs default to 60s (backed by `RoutingCacheVersion` instant invalidation) while existing `.env` values are preserved on re-runs, and change `echo "FS_XML_HANDLER_CACHE_TTL=5" >> .env` to `echo "FS_XML_HANDLER_CACHE_TTL=60" >> .env`.
3. `docs/env-reference.md`: Update the default column on line 119 (`| \`FS_XML_HANDLER_CACHE_TTL\` | \`integer\` | \`60\` | ...`) and the Small/Medium Business `.env` template on line 639 (`FS_XML_HANDLER_CACHE_TTL=60`).
4. `docs/load-testing-guide.md:281-282`: Update `# Production default: 60-second TTL across all telephony caches` and `FS_XML_HANDLER_CACHE_TTL=60`. Do **not** alter `docs/load-testing-results.md` (which records a historical empirical 5s benchmark sweep).

- [ ] **Step 4: Validate shell syntax, format PHP test, and run targeted test**

Run:
```bash
bash -n scripts/resources/tall.sh
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/XmlHandlerCacheConfigTest.php
```
Expected: `bash -n` exits `0` with no output; Pint passes; `XmlHandlerCacheConfigTest.php` PASSES.

- [ ] **Step 5: Commit Task 3**

```bash
git add .env.example scripts/resources/tall.sh docs/env-reference.md docs/load-testing-guide.md tests/Feature/XmlHandlerCacheConfigTest.php
git commit -m "fix(installer): align FS_XML_HANDLER_CACHE_TTL=60 across .env.example, tall.sh, and env docs"
```

---

### Task 4: Harden `SessionFlushCommand` with Target-Aware Confirmation & Redis DB Collision Guard

**Files:**
- Modify: `app/Console/Commands/SessionFlushCommand.php:43-85,156-179`
- Test: `tests/Feature/Console/SessionFlushCommandTest.php`

**Interfaces:**
- Consumes: `config('session.driver')`, `config('session.connection')`, `config('session.files')`, `config('session.table')`, `config('database.redis.*')`
- Produces:
  - `private function describeTarget(string $driver): string` — resolves human-readable target metadata *before* prompting:
    - `'redis'` -> `"driver: redis, connection: [{$connectionName}], endpoint: [{$host}:{$port}], database: [{$dbIndex}]"`
    - `'database'` -> `"driver: database, connection: [{$connectionLabel}], table: [{$table}]"` (where `$connectionLabel` falls back to `(string) config('database.default')` when `session.connection` is `null`)
    - `'file'` -> `"driver: file, path: [{$path}]"`
    - `'cookie'` -> `"driver: cookie (client-side only; no server store will be modified)"`
    - `'array'` -> `"driver: array (in-memory)"`
    - `default` -> if `app('session')->driver()->getHandler() instanceof CacheBasedSessionHandler`, `"driver: {$driver} (WARNING: cache-backed handler flushes the entire backing cache store)"`; otherwise `"driver: {$driver}"`
  - `private function detectRedisDbCollision(string $connectionName): ?string` — compares normalized `(host, port, database)` (or `url` + `database` when `url` is set) of `database.redis.{$connectionName}` against `database.redis.default` and `database.redis.cache` (skipping self-comparison if `$connectionName` is literally `'default'` or `'cache'`, which is itself a collision with that role: if `$connectionName === 'default'` return `'default'`, if `$connectionName === 'cache'` return `'cache'`, and otherwise if another connection `'default'` or `'cache'` shares the exact same endpoint + DB index, return that colliding connection name; otherwise `null`).
  - Confirmation prompt format: `"This will invalidate all active HTTP sessions across all tenants and administrators ({$target}). Do you wish to continue?"`
  - Collision guard behavior on `'redis'`: If `$collidingConnection = $this->detectRedisDbCollision($connectionName)` is non-null and `! $this->option('force')`, emit `$this->components->error("Refusing to run FLUSHDB: Redis session connection [{$connectionName}] shares database [{$dbIndex}] on [{$host}:{$port}] with Redis [{$collidingConnection}] connection. Isolate REDIS_SESSION_DB (e.g. DB 2) or pass --force to override.")` and return `self::FAILURE` **before** prompting or calling `flushdb()`. When `--force` is passed on a colliding DB, emit `$this->components->warn(...)` and proceed.

- [ ] **Step 1: Update existing confirmation tests and add Redis target disclosure + DB collision guard tests in `tests/Feature/Console/SessionFlushCommandTest.php`**

In `tests/Feature/Console/SessionFlushCommandTest.php`:
1. Preserve/restore `database.redis.session`, `database.redis.default`, and `database.redis.cache` in `beforeEach` / `afterEach`.
2. Update the two existing confirmation tests (`:25-39`) to set a deterministic driver (`array`) and expect the target-aware confirmation prompt:
   `'This will invalidate all active HTTP sessions across all tenants and administrators (driver: array (in-memory)). Do you wish to continue?'`
3. Add three new tests:

```php
it('discloses the exact Redis connection, host, port, and database index in the confirmation prompt before flushing', function (): void {
    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '13'],
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: redis, connection: [session], endpoint: [127.0.0.1:6379], database: [13]). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('refuses to run FLUSHDB without --force when the session Redis database collides with default or cache', function (string $collidingDb, string $expectedRole): void {
    Redis::shouldReceive('connection')->never();

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => $collidingDb],
    ]);

    $this->artisan('session:flush')
        ->expectsOutputToContain("shares database [{$collidingDb}]")
        ->expectsOutputToContain("with Redis [{$expectedRole}] connection")
        ->assertFailed();
})->with([
    'collides with default DB 0' => ['0', 'default'],
    'collides with cache DB 1' => ['1', 'cache'],
]);

it('allows overriding the Redis database collision guard when --force is explicitly passed', function (): void {
    $redisConn = \Mockery::mock();
    $redisConn->shouldReceive('flushdb')->once()->andReturnTrue();
    Redis::shouldReceive('connection')->once()->with('session')->andReturn($redisConn);

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
    ]);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('shares database [1]')
        ->expectsOutputToContain('Flushed Redis session store')
        ->assertSuccessful();
});
```
*(Remember `use Illuminate\Support\Facades\Redis;` at the top of `SessionFlushCommandTest.php`.)*

- [ ] **Step 2: Run `SessionFlushCommandTest.php` to verify the tests fail**

Run: `./vendor/bin/pest tests/Feature/Console/SessionFlushCommandTest.php`
Expected: FAIL — prompt text does not include target descriptor and Redis DB collision does not abort before `flushdb()`.

- [ ] **Step 3: Implement `describeTarget()` and `detectRedisDbCollision()` in `app/Console/Commands/SessionFlushCommand.php`**

In `SessionFlushCommand::handle(Filesystem $filesystem): int`:
1. Resolve `$driver = (string) config('session.driver', 'redis');` first.
2. If `$driver === 'redis'`, check `$this->detectRedisDbCollision($connectionName)`:
   - When non-null and `! $this->option('force')`, log the error via `$this->components->error(...)` and return `self::FAILURE` immediately (before prompting).
   - When non-null and `$this->option('force')`, emit `$this->components->warn(...)` noting `--force` override.
3. Build `$target = $this->describeTarget($driver);` and pass `"This will invalidate all active HTTP sessions across all tenants and administrators ({$target}). Do you wish to continue?"` to `$this->confirm(...)` when `! $this->option('force')`.

- [ ] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Console/SessionFlushCommandTest.php
```
Expected: Pint passes; all tests in `SessionFlushCommandTest.php` PASS.

- [ ] **Step 5: Commit Task 4**

```bash
git add app/Console/Commands/SessionFlushCommand.php tests/Feature/Console/SessionFlushCommandTest.php
git commit -m "fix(console): disclose session:flush target before confirmation and guard Redis DB collisions"
```

---

### Task 5: Synchronize `INSTALL.md` & `CHANGELOG.md` for `v4.3.0` Readiness

**Files:**
- Modify: `INSTALL.md:9-23,166-183,206,230,285-287,314-316,403-411,472`
- Modify: `CHANGELOG.md:8-22`

**Interfaces:**
- Consumes: CLI wrapper conventions from `docs/operations.md:23-25,246-248` (`tpbx`, `tallpbx`, `artisan`, `sudo tpbx permissions:repair --scope=full`), upgrade sequence from `CHANGELOG.md:42` (`permissions:sync`)
- Produces: Synchronized installation/upgrade documentation and complete `[Unreleased]` changelog notes ready for `v4.3.0`

- [ ] **Step 1: Update `INSTALL.md` for PHP 8.5, `tpbx` CLI parity, `FS_XML_HANDLER_CACHE_TTL=60`, and `permissions:sync`**

Apply these targeted updates in `INSTALL.md`:
1. **§1 (`:11-23`)**: Add `- **PHP Runtime**: PHP 8.5 (installed and configured automatically from the Sury repository on Debian 13; required for manual or development setups)`.
2. **Changing or Resetting the Administrator Password (`:166-183`)**: Introduce the global appliance CLI (`tpbx`, with `tallpbx` and `artisan` aliases) alongside `php artisan`, noting commands run from any directory without `cd /var/www/tallpbx`, and update the interactive examples to `tpbx admin:password` and `tpbx admin:password admin@example.com --password="YourNewPassword"`.
3. **Sound Prompt Languages (`:206`)**: Update `php artisan pbx:sounds:default <language>` -> `tpbx pbx:sounds:default <language>`.
4. **Redis Cache & Session Storage (`:230`)**: Update `FS_XML_HANDLER_CACHE_TTL=5` -> `FS_XML_HANDLER_CACHE_TTL=60` and `php artisan optimize:clear && php artisan optimize` -> `tpbx optimize:clear && tpbx optimize`.
5. **File Permissions (`:285-287`)**: Replace `cd /var/www/tallpbx` + `sudo php artisan permissions:repair --scope=full` with `sudo tpbx permissions:repair --scope=full` (matching `docs/operations.md:247`).
6. **Firewall CLI examples (`:314-315`)**: Update `php artisan security:status` / `php artisan security:unban <IP_ADDRESS>` -> `tpbx security:status` / `tpbx security:unban <IP_ADDRESS>`.
7. **Method 2 — Manual CLI Upgrade (`:403-410`)**: Update Step 3 and Step 4 to use `tpbx` and include `tpbx permissions:sync`:
   ```bash
   # 3. Apply database, module, and permission updates.
   tpbx migrate --force
   tpbx module:sync --only-local
   tpbx permissions:sync

   # 4. Refresh caches, permissions, and FreeSWITCH configuration.
   tpbx optimize:clear
   sudo tpbx permissions:repair --scope=full
   fs_cli -x "reloadxml"
   ```
8. **Firewall lockout troubleshooting (`:472`)**: Update `` `php artisan` commands `` -> `` `tpbx` (`php artisan`) commands ``.

- [ ] **Step 2: Update `[Unreleased]` in `CHANGELOG.md`**

Under `## [Unreleased]` in `CHANGELOG.md`:
- Update the `### Added` -> **Session Flush Console Command** bullet (`:12`) to note pre-confirmation target disclosure (connection, endpoint, DB index / path / table) and the Redis DB collision safety guard preventing accidental `FLUSHDB` when `REDIS_SESSION_DB` collides with `REDIS_DB` (`DB 0`) or `REDIS_CACHE_DB` (`DB 1`).
- Add a `### Changed` subsection documenting:
  - **XML Handler Cache TTL Installer & Environment Alignment (`FS_XML_HANDLER_CACHE_TTL=60`)**: Aligned `.env.example`, fresh installations in `scripts/resources/tall.sh`, `INSTALL.md`, and `docs/env-reference.md` with the 60-second default introduced in `4.2.0`. Include an explicit **Upgrade Note**: existing servers whose `.env` still specifies `FS_XML_HANDLER_CACHE_TTL=5` from an earlier install preserve their explicit setting on installer re-runs; update `FS_XML_HANDLER_CACHE_TTL=60` in `.env` and run `tpbx optimize:clear` to adopt the 60-second window.
  - **Installation & Upgrade Guide Parity**: Documented the PHP `8.5` runtime requirement, `tpbx` / `tallpbx` global CLI commands, and `tpbx permissions:sync` during manual CLI upgrades in `INSTALL.md`.
- Add under `### Fixed`:
  - **Gateway Dialplan Cache Invalidation**: Registered `Gateway` on `RoutingCacheObserver` so creating, editing, disabling, or deleting SIP gateways immediately invalidates cached outbound and emergency (E911) `mod_xml_curl` dialplan responses instead of waiting up to 60 seconds.
  - **Custom SIP Scanner Signature Dialplan Invalidation**: Added a cache-backed global routing revision (`RoutingCacheVersion::bumpGlobal()`) triggered by `SipScannerSignatures::saveCustom()` so custom SIP scanner User-Agent changes take effect immediately across all tenant public dialplans while keeping cached `mod_xml_curl` lookups database-query-free.

- [ ] **Step 3: Verify documentation consistency and markdown links**

Run:
```bash
grep -n "FS_XML_HANDLER_CACHE_TTL=5" .env.example scripts/resources/tall.sh INSTALL.md docs/env-reference.md docs/load-testing-guide.md || true
grep -n "permissions:sync" INSTALL.md
```
Expected: First `grep` produces empty output (zero stale `=5` references across those five files); second `grep` shows `tpbx permissions:sync` in `INSTALL.md`.

- [ ] **Step 4: Commit Task 5**

```bash
git add INSTALL.md CHANGELOG.md
git commit -m "docs: synchronize INSTALL.md and CHANGELOG.md for post-v4.2.0 remediations"
```

---

### Task 6: Final Pre-Release Verification Gate (`v4.3.0` Readiness)

**Files:**
- Test: `tests/Browser/CertificateManagerBrowserTest.php`
- Test: `tests/Browser/PanelOperationsSmokeTest.php`
- Test: `tests/Browser/SecurityCenterBrowserTest.php`
- Test: Full parallel application suite (`tpbx test --compact --parallel`)

**Interfaces:**
- Consumes: Completed Tasks 1–5 on branch `4.x`
- Produces: Verified, release-ready `4.x` branch (do **not** tag `v4.3.0`, retire this plan file, or push to `origin` until the user explicitly requests cutting/pushing the release)

- [ ] **Step 1: Clear compiled caches and verify clean code style**

Run:
```bash
git branch --show-current
tpbx optimize:clear
./vendor/bin/pint --dirty --test
```
Expected: Branch is `4.x`; caches clear cleanly; Pint reports zero style issues.

- [ ] **Step 2: Run targeted Playwright browser smoke tests for `layouts/app.blade.php` (`64e58a7c`) and decomposed Blade views**

Run:
```bash
./vendor/bin/pest tests/Browser/PanelOperationsSmokeTest.php tests/Browser/CertificateManagerBrowserTest.php tests/Browser/SecurityCenterBrowserTest.php
```
Expected: PASS with zero browser console/Alpine expression errors on `<x-tooltip>` in the mini navigation rail or decomposed Security Center / Certificate Manager tabs.

- [ ] **Step 3: Run the single pre-release parallel Pest test gate**

Run:
```bash
tpbx test --compact --parallel
```
Expected: Full suite PASSES with 0 failures (including `SddLedgerHygieneTest` and `UiArchitectureTest`).

- [ ] **Step 4: Run final whole-branch SDD review, report verification evidence, and await release-cut / push confirmation**

Dispatch the final whole-branch code reviewer (`superpowers:requesting-code-review`) over `b7cd1837..HEAD` per `superpowers:subagent-driven-development`, clean up this plan's `.superpowers/sdd/<workspace>` directory once clean, and report exact pass counts, `git log --oneline v4.2.0..HEAD`, and any recorded `Ruling:` entries to the user. Once the user confirms cutting `v4.3.0`, retire `docs/post-v420-remediation-implementation-plan.md`, move `[Unreleased]` in `CHANGELOG.md` to `## [4.3.0] - YYYY-MM-DD`, commit `chore(release): v4.3.0`, and tag/push only as instructed.
