# Lua Redis Mirror-Cache Proxy v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status: on hold — do not start at Task 2.** [AO-B7](implementation-plan-review.md) in the review makes a fast front controller for the PHP miss path the prerequisite, and that work is now implemented and measured (Revision 3.5, E37). The Task 1 gate below was computed against the old 55 ms miss path, so it must be re-derived (AO-B8) before this plan resumes.

**Goal:** Add an optional, default-off in-process Lua directory-XML cache in FreeSWITCH that serves Laravel-rendered directory XML from Redis in under 1 ms on cache hits, with automatic version-based invalidation, fail-to-miss fallback to `mod_xml_curl`, and a one-step rollback — and only after a measurement spike proves the economics.

**Architecture:** A strictly additive mirror layer. Laravel keeps its existing versioned cache untouched and additionally writes raw, unprefixed keys (`pbx:fsxml:*`) into Redis DB 0 via dedicated connection `Redis::connection(config('freeswitch.lua_proxy.connection', 'lua_proxy'))`, bypassing the `Cache` facade and overriding Laravel's default database prefix (`tallpbx-database-`) with `'prefix' => ''` so keys match Lua's raw queries byte-for-byte. `RoutingCacheVersion::bump()` mirrors the new revision so the version is embedded in every payload key; a version change therefore makes the next Lua lookup miss and fall through to `mod_xml_curl`, which rebuilds and re-warms the mirror. The Lua script performs at most three `GET`s (tenant map → version → payload), contains no XML logic, and returns `nil` on any doubt. Phase 1 covers directory lookups only (supporting both native FreeSWITCH `domain` lookups and synthetic `user` lookups); dialplan is explicitly out of scope until Phase 1 data justifies it.

**Tech Stack:** FreeSWITCH v1.11 (`mod_lua`, `mod_hiredis`, `mod_xml_curl`), Redis 7 (DB 0 mirror via unprefixed `lua_proxy` connection), Lua 5.x, Laravel 13 / PHP 8.5, Pest.

**Spec:** [`implementation-plan-review.md`](implementation-plan-review.md) (Revision 3.5 — findings B-R1…B-R9, V2-C1…V2-M1, and opinions AO-B1…AO-B9). Supersedes [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md).

## Global Constraints

- **Additive only**: existing PHP cache keys, TTLs, and invalidation semantics are unchanged. `tests/Feature/Http/XmlHandlerControllerTest.php` must pass without modifying any existing test.
- **Raw Redis writes only via unprefixed connection**: mirror writes use `Redis::connection(config('freeswitch.lua_proxy.connection', 'lua_proxy'))` (configured with `'prefix' => ''` and `'database' => env('REDIS_DB', '0')`) with direct `SET`/`GET`/`EXISTS` commands. Never the `Cache` facade and never the default connection with its `tallpbx-database-` prefix — that is what causes key mismatches between PHP and Lua.
- **Namespace invariant**: strictly fixed as `pbx:fsxml` across both PHP (`FsXmlMirrorKeys::NAMESPACE`) and Lua (`local ns = "pbx:fsxml"`). Making the namespace an environment variable introduces silent key-drift risk where PHP and Lua query different keys; treating it as an immutable constant ensures byte-for-byte contract parity.
- **Version-in-key is mandatory**: every payload key embeds the tenant's current `RoutingCacheVersion`. Lua never falls back to a default version — a missing version key is a miss. Version keys are seeded on first warm-up in `DirectoryXmlBuilder` and monotonically advanced across cache flushes in `RoutingCacheVersion::bump()`.
- **Fail-to-miss, never fail-to-stale**: any missing key, Redis error string, invalid charset, or malformed value makes Lua return `nil` so FreeSWITCH uses `mod_xml_curl`.
- **Zero XML business logic in Lua**: the script may only build keys from request headers, read Redis, and validate the reply envelope (`<document`).
- **Feature flag default off**: `FS_LUA_PROXY_ENABLED=false`. Enabling is a deliberate lab/staging step, never part of a routine deploy.
- **Directory-only scope**: `section == "directory"` and either `tag_name == "domain"` (`key_value == domain`) or `tag_name == "user"` (`key_value == user`). Everything else always falls through.
- **Measured before built**: Task 1's numeric gate must pass before Task 2 starts.
- **Verification discipline**: `./vendor/bin/pint --dirty` and targeted Pest only; `tpbx optimize:clear` after any PHP/config change.

## Review Focus

1. **Stale SIP credentials after a panel change** — an extension's password or voicemail PIN is updated; the very next REGISTER/INVITE must be authenticated against the new value. A Lua hit may never survive a version bump.
2. **Panel saves must not fail when Redis is down** — mirror writes are best-effort; a Redis outage may degrade caching but must never break an Eloquent save.
3. **Shared domains with duplicate usernames** — two tenants on one domain own the same username; the mirror key is keyed by the PHP-resolved `(domain, user)` identity and the tenant the resolver actually chose. If no mapping exists, Lua must miss rather than guess.
4. **Truncated or foreign values** — a partially written, empty, or non-XML value under a mirror key must never reach FreeSWITCH's XML parser; the envelope check must reject it.
5. **Lua fault, Redis outage, and rollback** — a Lua runtime error or Redis unavailability must degrade silently to `mod_xml_curl`, and removing the bindings must restore the PHP path with zero data loss.

---

### Task 1: Measurement Spike & Numeric Gate

**Files:**
- Modify: `.env` (remote test host only — not committed)
- Create: `docs/lua-proxy-measurement-results.md` (standalone committed test report, retained for future reference)

**Interfaces:**
- Consumes: the existing `LogXmlHandlerTiming` middleware (`config('freeswitch.xml_handler.log_timing')`)
- Produces: a Gate Decision (PROCEED / DEFER) recorded in the committed test report

> **Gate outcome (2026-11-01): PROCEED** — all 1 vCPU tier criteria passed; see [lua-proxy-measurement-results.md](../../lua-proxy-measurement-results.md).

- [x] **Step 1: Provision and verify the remote 1 vCPU host**

Provision a datacenter VPS with 1 shared vCPU, 1 GB RAM, 2 GB swap, Debian 13, and a public IPv4 address. Install TallPBX by running the documented one-line installer **interactively** as an end user, following the remote test-server conventions in `docs/load-testing-guide.md#provisioning-a-remote-test-server` — answer the prompts and enter the SignalWire PAT at the prompt, never pre-seeded. Verify: PHP-FPM runs `pm = static` with 5 workers, swap is active, and the full default `nftables` firewall is enabled (benchmark parity with `docs/load-testing-results.md`).

- [x] **Step 2: Capture the clean HTTP baseline with timing off**

Seed the load tenant:

```bash
tpbx pbx:load-test:seed --tenant=load-test-beta --domain=load.test.local --extensions=100 --start=2000 --password='LoadTest1234' --reset
```

Run `tpbx pbx:load-test:dialplan` at `25x1`, `100x5`, and `1000x25` against the host's own public IP, then run:

```bash
PBX_URL="http://<public-ip>/api/v1/xml-handler" TENANT="load-test-beta" TOKEN="$FS_XML_HANDLER_TOKEN" bash scripts/run-cache-sweep.sh
```

That sweep must run on the PBX host because it edits `.env` cache TTLs and flushes Redis DB 1 locally. Count `section=directory` requests versus total xml-handler requests in the nginx access log to obtain the directory share. Keep `FS_XML_HANDLER_LOG_TIMING` off for this pass so the timing listener does not pollute the throughput numbers.

- [x] **Step 3: Capture the app-side pass and record the committed test report** (executed with the `--timing` header harness rather than log parsing)

Set `FS_XML_HANDLER_LOG_TIMING=true` in `.env`, run `tpbx optimize:clear`, repeat `25x1` and `100x5`, then extract the `XML Handler timing.` entries (`elapsed_ms`, `db_query_count`) and build the table below.

Create `docs/lua-proxy-measurement-results.md` as a standalone report for future reference, following the conventions of `docs/load-testing-results.md`:
- **Environment**: provider and region, 1 shared vCPU, 1 GB RAM, 2 GB swap, Debian 13, PHP 8.5 / Laravel 13 / Redis 7 / MariaDB / nginx versions, PHP-FPM `pm = static` worker count, `FS_XML_HANDLER_CACHE_TTL`, and the public IP obfuscated to its last octet.
- **Method**: the exact seed and measurement commands, the timing-off / timing-on split, and a statement that the full default `nftables` firewall was active.
- **Results**: the table below populated from the `XML Handler timing.` log entries, plus the HTTP-level warm-up, burst, and sustained tables and the cache-sweep hit rates.
- **Artifacts**: paths under `storage/app/load-tests/` for every JSON report produced.

| Metric | Hits | Misses |
|:--|:--|:--|
| lookup count | | |
| app-side p50 (ms) | | |
| app-side p95 (ms) | | |
| app-side p99 (ms) | | |
| queries per lookup | | |

- [x] **Step 4: Apply the gate**

Revert `FS_XML_HANDLER_LOG_TIMING=false` and run `tpbx optimize:clear` before recording the decision. The selected measurement target is a remote datacenter host at 1 shared vCPU / 1 GB RAM, so the 4 vCPU throughput ceiling does not apply. Record exactly one decision, citing the measured numbers:
- **PROCEED (1 vCPU tier)** if hit-path app-side p95 ≥ 10 ms **and** HTTP p95 at concurrency 25 ≥ 500 ms **and** directory lookups account for ≥ 1/3 of xml-handler volume.
- **PROCEED (reference tier)** if a 4 vCPU host were measured instead: hit-path app-side p95 ≥ 10 ms **and** sustained XML throughput ≥ 60 req/s.
- **DEFER** if hit-path p95 < 5 ms, or the directory share is below 1/3.

- [x] **Step 5: Stop on DEFER** (not applicable — gate passed)

If the decision is DEFER, close the plan here and record why. Do not start Task 2.

- [x] **Step 6: Commit the test report** (committed as `73345ad`)

```bash
git add docs/lua-proxy-measurement-results.md
git commit -m "docs(load-test): add lua proxy v2 measurement report and gate decision"
```

---

### Task 2: Mirror Key Builder & Writer Service

**Files:**
- Modify: `config/database.php` (add dedicated `'lua_proxy'` connection under `'redis'` with `'database' => env('REDIS_DB', '0')` and `'prefix' => ''`)
- Modify: `config/freeswitch.php` (add `lua_proxy` block beside `xml_handler` with `'connection' => env('FS_LUA_PROXY_CONNECTION', 'lua_proxy')`)
- Modify: `.env.example` (add `FS_LUA_PROXY_ENABLED=false` and `FS_LUA_PROXY_CONNECTION=lua_proxy`)
- Create: `app/Services/Xml/FsXmlMirrorKeys.php`
- Create: `app/Services/Xml/FsXmlMirrorWriter.php`
- Test: `tests/Unit/Services/FsXmlMirrorKeysTest.php`
- Test: `tests/Feature/Services/FsXmlMirrorWriterTest.php`

**Interfaces:**
- Produces:
  - `FsXmlMirrorKeys::version(int $tenantId): string` → `pbx:fsxml:ver:{tenantId}`
  - `FsXmlMirrorKeys::globalVersion(): string` → `pbx:fsxml:ver:global`
  - `FsXmlMirrorKeys::tenantLookup(string $domain, string $user): string` → `pbx:fsxml:tenant:user:{domain}:{user}`
  - `FsXmlMirrorKeys::directory(int $tenantId, string $tagName, string $domain, string $user, int $version): string` → `pbx:fsxml:dir:{tenantId}:{tagName}:{domain}:{user}:v{version}`
  - `FsXmlMirrorWriter::enabled(): bool`
  - `FsXmlMirrorWriter::writeTenantLookup(string $domain, string $user, int $tenantId): void`
  - `FsXmlMirrorWriter::writeDirectory(int $tenantId, string $tagName, string $domain, string $user, int $version, string $xml): void`
  - `FsXmlMirrorWriter::writeVersion(int $tenantId, int $version): void`
  - `FsXmlMirrorWriter::writeGlobalVersion(int $version): void`

- [ ] **Step 1: Add the configuration blocks**

In `config/database.php`, add under `'redis'` connections (ensuring an explicit empty `'prefix'` so phpredis does not prepend `tallpbx-database-` to raw mirror keys):

```php
'lua_proxy' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_DB', '0'),
    'prefix' => '',
    'max_retries' => env('REDIS_MAX_RETRIES', 3),
    'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
    'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
],
```

In `config/freeswitch.php`, add beside `xml_handler`:

```php
'lua_proxy' => [
    'enabled' => filter_var(env('FS_LUA_PROXY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'connection' => env('FS_LUA_PROXY_CONNECTION', 'lua_proxy'),
],
```

In `.env.example`, add `FS_LUA_PROXY_ENABLED=false` and `FS_LUA_PROXY_CONNECTION=lua_proxy`. (Note: `pbx:fsxml` namespace is defined as a fixed constant in `FsXmlMirrorKeys::NAMESPACE` to prevent key drift).

- [ ] **Step 2: Write the failing key-builder test**

`tests/Unit/Services/FsXmlMirrorKeysTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Xml\FsXmlMirrorKeys;

test('defines fixed pbx:fsxml namespace constant', function () {
    expect(FsXmlMirrorKeys::NAMESPACE)->toBe('pbx:fsxml');
});

test('builds namespaced version keys for tenant and global revisions', function () {
    expect(FsXmlMirrorKeys::version(7))->toBe('pbx:fsxml:ver:7')
        ->and(FsXmlMirrorKeys::globalVersion())->toBe('pbx:fsxml:ver:global');
});

test('builds tenant lookup key from domain and user', function () {
    expect(FsXmlMirrorKeys::tenantLookup('a.com', '1001'))
        ->toBe('pbx:fsxml:tenant:user:a.com:1001');
});

test('builds directory payload key embedding tenant, tag name, domain, user, and routing version', function () {
    expect(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3))
        ->toBe('pbx:fsxml:dir:7:domain:a.com:1001:v3')
        ->and(FsXmlMirrorKeys::directory(7, 'user', 'a.com', '1001', 3))
        ->toBe('pbx:fsxml:dir:7:user:a.com:1001:v3');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/FsXmlMirrorKeysTest.php`
Expected: FAIL (class not found).

- [ ] **Step 4: Implement `FsXmlMirrorKeys`**

Declare `public const NAMESPACE = 'pbx:fsxml';`. All methods are `public static`; each prefixes `self::NAMESPACE` to the formats above. Add a class-level PHPDoc explaining that these key strings are an immutable contract shared byte-for-byte with `scripts/freeswitch/tallpbx_xml_handler.lua`.

- [ ] **Step 5: Write the failing writer test**

`tests/Feature/Services/FsXmlMirrorWriterTest.php` — set `config(['freeswitch.lua_proxy.enabled' => true, 'freeswitch.lua_proxy.connection' => 'lua_proxy', 'freeswitch.xml_handler.directory_cache_ttl' => 60])` in `beforeEach`, and delete the exact keys written in `afterEach` (never `FLUSHDB`):

```php
test('uses dedicated lua_proxy connection with empty redis prefix', function () {
    $conn = Redis::connection(config('freeswitch.lua_proxy.connection'));
    expect($conn->client()->getOption(\Redis::OPT_PREFIX))->toBeNull();
});

test('writes directory payload with ttl and tenant lookup via raw redis without prefix', function () {
    app(FsXmlMirrorWriter::class)->writeTenantLookup('a.com', '1001', 7);
    app(FsXmlMirrorWriter::class)->writeDirectory(7, 'domain', 'a.com', '1001', 3, '<document type="freeswitch/xml"/>');

    $conn = Redis::connection('lua_proxy');
    expect($conn->get(FsXmlMirrorKeys::tenantLookup('a.com', '1001')))->toBe('7')
        ->and($conn->ttl(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3)))->toBeGreaterThan(0);
});

test('writes nothing when the feature flag is disabled', function () {
    config(['freeswitch.lua_proxy.enabled' => false]);
    app(FsXmlMirrorWriter::class)->writeTenantLookup('b.com', '2001', 9);
    expect(Redis::connection('lua_proxy')->exists(FsXmlMirrorKeys::tenantLookup('b.com', '2001')))->toBe(0);
});

test('does not throw when the redis connection fails', function () {
    Redis::shouldReceive('connection')->andThrow(new \RedisException('down'));
    app(FsXmlMirrorWriter::class)->writeVersion(7, 4);
})->throwsNoExceptions();
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Services/FsXmlMirrorWriterTest.php`
Expected: FAIL (class not found).

- [ ] **Step 7: Implement `FsXmlMirrorWriter`**

Constructor reads the enabled flag and connection name from config (defaulting to `'lua_proxy'`). Every public write method returns early when disabled, wraps its Redis call in `try { … } catch (\Throwable $e) { Log::debug('Lua proxy mirror write failed', ['error' => $e->getMessage()]); }`, and never rethrows. Payload and tenant-lookup writes use `SET … EX (int) config('freeswitch.xml_handler.directory_cache_ttl')`; version writes use `SET` without expiry.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/FsXmlMirrorKeysTest.php tests/Feature/Services/FsXmlMirrorWriterTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add config/database.php config/freeswitch.php .env.example app/Services/Xml/FsXmlMirrorKeys.php app/Services/Xml/FsXmlMirrorWriter.php tests/Unit/Services/FsXmlMirrorKeysTest.php tests/Feature/Services/FsXmlMirrorWriterTest.php
git commit -m "feat(xml): add lua proxy mirror key builder and fail-soft writer with unprefixed connection"
```

---

### Task 3: Mirror Routing Versions on Every Bump

**Files:**
- Modify: `app/Support/RoutingCacheVersion.php`
- Test: `tests/Feature/Support/RoutingCacheVersionMirrorTest.php`

**Interfaces:**
- Consumes: `FsXmlMirrorWriter::writeVersion(int $tenantId, int $version)`, `FsXmlMirrorWriter::writeGlobalVersion(int $version)`
- Produces: version keys that Lua reads; no signature changes to `bump()` / `bumpGlobal()`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Support/RoutingCacheVersionMirrorTest.php`:

```php
use App\Services\Xml\FsXmlMirrorKeys;
use App\Support\RoutingCacheVersion;

beforeEach(function () {
    config(['freeswitch.lua_proxy.enabled' => true, 'freeswitch.lua_proxy.connection' => 'lua_proxy']);
    Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version(42), FsXmlMirrorKeys::globalVersion());
});

test('mirrors the tenant revision into the lua namespace on bump', function () {
    RoutingCacheVersion::bump(42);
    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version(42)))
        ->toBe((string) RoutingCacheVersion::get(42));
});

test('mirrors the global revision on global bump', function () {
    RoutingCacheVersion::bumpGlobal();
    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::globalVersion()))
        ->toBe((string) RoutingCacheVersion::getGlobal());
});

test('advances monotonically when cache store was cleared but mirror key exists', function () {
    // Simulate pre-existing mirror key at version 5 in DB 0
    Redis::connection('lua_proxy')->set(FsXmlMirrorKeys::version(42), '5');
    // Simulate DB 1 cache clear
    Cache::forget(RoutingCacheVersion::key(42));

    RoutingCacheVersion::bump(42);

    // Version must advance to at least 6, not reset to 1
    expect((int) RoutingCacheVersion::get(42))->toBeGreaterThanOrEqual(6)
        ->and((int) Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version(42)))->toBeGreaterThanOrEqual(6);
});

test('a bump still succeeds when the mirror writer throws', function () {
    Redis::shouldReceive('connection')->andThrow(new \RedisException('down'));
    RoutingCacheVersion::bump(42);
})->throwsNoExceptions();
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Support/RoutingCacheVersionMirrorTest.php`
Expected: FAIL (mirror key never written).

- [ ] **Step 3: Implement the mirror call and cache-clear monotonicity guard**

In `bump(int $tenantId)`:
1. Check if the store has `self::key($tenantId)`. If missing (e.g. after `tpbx optimize:clear` or `cache:clear`), check if the mirror writer is enabled and read the existing version from DB 0 (`GET pbx:fsxml:ver:{tenantId}`). Seed the store with `max(static::get($tenantId), (int)$mirrorVer) + 1` to ensure monotonic progression across cache flushes.
2. Otherwise, use `increment()` (with the existing fallback to `put()`).
3. Call `app(FsXmlMirrorWriter::class)->writeVersion($tenantId, (int) static::get($tenantId));` inside a `try/catch (\Throwable)` that logs at debug level.
4. Apply the same pattern to `bumpGlobal()` with `writeGlobalVersion((int) static::getGlobal())` after the store update.
5. Update the class PHPDoc to state that the mirror write is best-effort and must never break a caller.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Support/RoutingCacheVersionMirrorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/RoutingCacheVersion.php tests/Feature/Support/RoutingCacheVersionMirrorTest.php
git commit -m "feat(xml): mirror routing revisions into lua proxy namespace on bump"
```

---

### Task 4: Mirror Directory XML When Laravel Renders It

**Files:**
- Modify: `app/Services/Xml/DirectoryXmlBuilder.php`
- Test: `tests/Feature/Services/DirectoryXmlMirrorTest.php`

**Interfaces:**
- Consumes: `FsXmlMirrorWriter` methods from Task 2, `FsXmlMirrorKeys` for assertions
- Produces: populated `pbx:fsxml:tenant:user:*`, `pbx:fsxml:ver:*`, and `pbx:fsxml:dir:*` keys for every eligible directory lookup

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/DirectoryXmlMirrorTest.php` — reuse the tenant/SIP-account fixture setup from `tests/Feature/Http/XmlHandlerControllerTest.php`. Enable the flag, set the tenant via `TenantManager`, and clear exact mirror keys in `afterEach`:

```php
test('mirrors directory xml, tenant lookup, and seeds version for real FreeSWITCH domain tag lookups', function () {
    [$tenantId, $domain, $user] = $this->makeDirectoryFixture(); // helper: tenant + sip account, tenant set on TenantManager
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $version = (int) \App\Support\RoutingCacheVersion::get($tenantId);
    $conn = Redis::connection('lua_proxy');
    expect($conn->get(FsXmlMirrorKeys::tenantLookup($domain, $user)))->toBe((string) $tenantId)
        ->and($conn->get(FsXmlMirrorKeys::version($tenantId)))->toBe((string) $version)
        ->and($conn->get(FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, $version)))->toContain('<document');
});

test('mirrors directory xml and tenant lookup for synthetic user tag lookups', function () {
    [$tenantId, $domain, $user] = $this->makeDirectoryFixture();
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('user', $domain, $user, $user);

    $version = (int) \App\Support\RoutingCacheVersion::get($tenantId);
    $conn = Redis::connection('lua_proxy');
    expect($conn->get(FsXmlMirrorKeys::tenantLookup($domain, $user)))->toBe((string) $tenantId)
        ->and($conn->get(FsXmlMirrorKeys::directory($tenantId, 'user', $domain, $user, $version)))->toContain('<document');
});

test('does not mirror when key value differs from expected tag scope', function () {
    [$tenantId, $domain, $user] = $this->makeDirectoryFixture();
    // For domain tag, keyValue must match domain; mismatch should not mirror
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, 'other.com');
    expect(Redis::connection('lua_proxy')->exists(FsXmlMirrorKeys::tenantLookup($domain, $user)))->toBe(0);

    // For user tag, keyValue must match username; mismatch should not mirror
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('user', $domain, $user, '9999');
    expect(Redis::connection('lua_proxy')->exists(FsXmlMirrorKeys::tenantLookup($domain, $user)))->toBe(0);
});

test('does not mirror when directory caching is disabled', function () {
    config(['freeswitch.xml_handler.directory_cache_ttl' => 0]);
    [$tenantId, $domain, $user] = $this->makeDirectoryFixture();
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);
    expect(Redis::connection('lua_proxy')->exists(FsXmlMirrorKeys::tenantLookup($domain, $user)))->toBe(0);
});

test('seeds version key on cold start without waiting for administrative bump', function () {
    [$tenantId, $domain, $user] = $this->makeDirectoryFixture();
    Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version($tenantId));

    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version($tenantId)))
        ->toBe((string) \App\Support\RoutingCacheVersion::get($tenantId));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Services/DirectoryXmlMirrorTest.php`
Expected: FAIL (no mirror keys written).

- [ ] **Step 3: Implement the mirror write in `buildCachedDirectoryXml()`**

After the existing `$xml = $cache->remember(...)` result (or after the uncached build when TTL is active — mirror only when `$ttl > 0`), add an eligibility guard: feature enabled, `$domain !== ''`, `$username !== ''`, and matching tag scope:
- `$tagName === 'domain' && $keyValue === $domain` (FreeSWITCH's native `switch_xml_locate_user()` shape)
- `$tagName === 'user' && $keyValue === $username` (synthetic benchmark shape)

When eligible:
1. Fetch `$tenantIdInt = (int) $tenantId;` and `$version = (int) RoutingCacheVersion::get($tenantIdInt);`
2. Obtain `$writer = app(FsXmlMirrorWriter::class);`
3. Call `$writer->writeVersion($tenantIdInt, $version);` (seeds `pbx:fsxml:ver:{tenantId}` on cold start so Lua never misses due to an unseeded version key)
4. Call `$writer->writeTenantLookup($domain, $username, $tenantIdInt);`
5. Call `$writer->writeDirectory($tenantIdInt, $tagName, $domain, $username, $version, $xml);`

Extract the guard into a private `shouldMirror(string $tagName, string $domain, string $username, string $keyValue, int $ttl): bool` with a PHPDoc documenting both lookup modes.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Services/DirectoryXmlMirrorTest.php tests/Feature/Http/XmlHandlerControllerTest.php`
Expected: PASS, including the unchanged 68-test controller suite.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Xml/DirectoryXmlBuilder.php tests/Feature/Services/DirectoryXmlMirrorTest.php
git commit -m "feat(xml): mirror rendered directory xml into the lua cache namespace"
```

---

### Task 5: Version-Aware Lua Script & Key-Parity Harness

**Files:**
- Create: `scripts/freeswitch/tallpbx_xml_handler.lua`
- Create: `tests/fixtures/freeswitch/lua_key_fixtures.json`
- Create: `tests/Unit/Scripts/test_tallpbx_xml_handler.sh`
- Create: `tests/Unit/Services/FsXmlMirrorKeysFixtureTest.php`
- Test: the shell harness and the PHP fixture test above

**Interfaces:**
- Consumes: key formats from Task 2 (byte-for-byte)
- Produces: `XML_STRING` set on hit, unset on any miss; `TALLPBX_DEBUG_KEYS` (tenant/version/payload key strings) exposed for the harness only

- [ ] **Step 1: Write the Lua script**

`scripts/freeswitch/tallpbx_xml_handler.lua`:

```lua
-- TallPBX version-aware directory XML cache proxy (phase 1: directory domain and user lookups).
-- Contract: key strings must match App\Services\Xml\FsXmlMirrorKeys byte-for-byte.
-- Any doubt returns nil so FreeSWITCH falls through to mod_xml_curl.

local function proxy() -- returns xml string or nil
    if not XML_REQUEST or XML_REQUEST["section"] ~= "directory" then return nil end
    local tag_name = XML_REQUEST["tag_name"] or ""
    if tag_name ~= "domain" and tag_name ~= "user" then return nil end

    local key_value = XML_REQUEST["key_value"] or ""
    local user = (params and (params:getHeader("sip_auth_username") or params:getHeader("user"))) or ""
    local domain = (params and (params:getHeader("domain") or params:getHeader("domain_name")
        or params:getHeader("variable_domain_name"))) or ""

    if user == "" or domain == "" then return nil end
    if tag_name == "domain" then
        if key_value ~= domain then return nil end
    elseif tag_name == "user" then
        if key_value ~= user then return nil end
    end

    if not domain:match("^[%w%.%-_]+$") or not user:match("^[%w%.%-_]+$") then return nil end

    local api = freeswitch.API()
    local ns = "pbx:fsxml"

    local tenant = api:execute("hiredis_raw", "default GET " .. ns .. ":tenant:user:" .. domain .. ":" .. user)
    if not tenant or tenant == "" or tenant == "(nil)" or tenant:sub(1, 1) == "-" then return nil end

    local version = api:execute("hiredis_raw", "default GET " .. ns .. ":ver:" .. tenant)
    if not version or version == "" or version == "(nil)" or version:sub(1, 1) == "-" then return nil end

    local payload_key = ns .. ":dir:" .. tenant .. ":" .. tag_name .. ":" .. domain .. ":" .. user .. ":v" .. version
    TALLPBX_DEBUG_KEYS = { tenant = ns .. ":tenant:user:" .. domain .. ":" .. user,
                           version = ns .. ":ver:" .. tenant, payload = payload_key }

    local reply = api:execute("hiredis_raw", "default GET " .. payload_key)
    if not reply or reply == "" or reply == "(nil)" or reply:sub(1, 1) == "-" then return nil end
    if #reply < 20 or not reply:find("<document", 1, true) then return nil end
    return reply
end

local ok, result = pcall(proxy)
if ok and result then XML_STRING = result else XML_STRING = nil end
```

- [ ] **Step 2: Write the shared fixtures**

`tests/fixtures/freeswitch/lua_key_fixtures.json` — cases covering: standard domain tag hit (`tag_name=domain`, `key_value=domain`), synthetic user tag hit (`tag_name=user`, `key_value=user`), missing tenant map, missing version, `-NOAUTH` error reply, truncated payload, foreign non-XML value, `key_value` mismatch in both tag modes, invalid charset. Each case carries `tag_name`, `domain`, `user`, `key_value`, `tenant_id`, `version`, Redis replies, and expected `XML_STRING` (string or null) plus expected `TALLPBX_DEBUG_KEYS`.

- [ ] **Step 3: Write the parity harness and the PHP fixture test**

`tests/Unit/Scripts/test_tallpbx_xml_handler.sh` — for each fixture case: build a Lua stub defining `freeswitch.API()` that parses the requested key out of the `hiredis_raw` command and returns the case's canned reply, stub `params.getHeader`, set `XML_REQUEST`, `dofile` the script, then assert `XML_STRING` and `TALLPBX_DEBUG_KEYS` against the fixture. Interpreter detection order: `lua`, `lua5.4`, `lua5.1`, `luajit`; if none exist, print `SKIP: no lua interpreter` and exit 2.

`tests/Unit/Services/FsXmlMirrorKeysFixtureTest.php` — iterate the same JSON and assert `FsXmlMirrorKeys::tenantLookup()`, `::version()`, and `::directory()` reproduce each case's expected key strings. The JSON file is the single source of truth: if the PHP key format or the Lua key format drifts, one of the two tests fails.

- [ ] **Step 4: Run the harnesses**

Run: `bash tests/Unit/Scripts/test_tallpbx_xml_handler.sh`
Expected: `OK <n> cases` and exit 0 (or exit 2 SKIP when no interpreter is installed; install `lua5.4` on the lab host).

Run: `./vendor/bin/pest tests/Unit/Services/FsXmlMirrorKeysFixtureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/freeswitch/tallpbx_xml_handler.lua tests/fixtures/freeswitch/lua_key_fixtures.json tests/Unit/Scripts/test_tallpbx_xml_handler.sh tests/Unit/Services/FsXmlMirrorKeysFixtureTest.php
git commit -m "feat(freeswitch): add version-aware directory cache lua proxy with key parity harness"
```

---

### Task 6: Bind the Lua Handler, Hiredis Connection Pool Tuning, Load Order, and Rollback Runbook

**Files:**
- Create: `scripts/freeswitch/lua.conf.xml` (source of truth)
- Create: `scripts/freeswitch/hiredis.conf.xml` (tuned profile with 16 connections & auth support)
- Deploy: `/etc/freeswitch/autoload_configs/lua.conf.xml`
- Deploy: `/etc/freeswitch/autoload_configs/hiredis.conf.xml`
- Modify: `/etc/freeswitch/autoload_configs/modules.conf.xml` (load order only)
- Modify: `docs/operations.md` (rollback procedure)

**Interfaces:**
- FreeSWITCH configuration: `<param name="xml-handler-script" value="tallpbx_xml_handler.lua"/>`, `<param name="xml-handler-bindings" value="directory"/>`
- FreeSWITCH hiredis profile `default`: `max-connections="16"`, `timeout_ms="500"`, optional `password` parameter
- Load order: `<load module="mod_lua"/>` before `<load module="mod_xml_curl"/>`

- [ ] **Step 1: Add the hiredis configuration template with tuned connection pool**

Create `scripts/freeswitch/hiredis.conf.xml` to tune the default profile for high concurrency (bumping from 3 to 16 connections so 3 sequential queries per lookup under concurrency 25+ do not exhaust the pool):

```xml
<configuration name="hiredis.conf" description="mod_hiredis">
  <profiles>
    <profile name="default">
      <connections>
        <connection name="primary">
          <param name="hostname" value="127.0.0.1"/>
          <param name="port" value="6379"/>
          <param name="timeout_ms" value="500"/>
          <param name="max-connections" value="16"/>
          <!-- If REDIS_PASSWORD is configured in .env, uncomment and supply: -->
          <!-- <param name="password" value="{REDIS_PASSWORD}"/> -->
        </connection>
      </connections>
      <params>
        <param name="ignore-connect-fail" value="true"/>
        <param name="ignore-error" value="true"/>
        <param name="delete-when-zero" value="true"/>
      </params>
    </profile>
  </profiles>
</configuration>
```

Deploy to `/etc/freeswitch/autoload_configs/hiredis.conf.xml` (`chown freeswitch:tallpbx-media`, `chmod 644`). If `.env` defines a non-empty `REDIS_PASSWORD`, ensure the password param is uncommented and populated so `mod_hiredis` does not receive `-NOAUTH`.

- [ ] **Step 2: Add the Lua bindings template**

Create `scripts/freeswitch/lua.conf.xml` containing the two handler parameters inside `<settings>`, preserving the existing comments. Deploy to `/etc/freeswitch/autoload_configs/lua.conf.xml` (copy on lab; installer wiring is a separate task) and copy the script to `/etc/freeswitch/scripts/`, `chown freeswitch:tallpbx-media`, `chmod 644`.

- [ ] **Step 3: Verify module order**

In `/etc/freeswitch/autoload_configs/modules.conf.xml`, confirm `mod_lua` loads before `mod_xml_curl`. Do not reorder other modules.

- [ ] **Step 4: Restart FreeSWITCH during a maintenance window**

Run `sudo systemctl restart freeswitch` and confirm clean boot with no Lua-parse errors in `/var/log/freeswitch/freeswitch.log`. Verify `hiredis` connection: `fs_cli -x "hiredis_raw default PING"` returns `+PONG`.

- [ ] **Step 5: Document the rollback runbook**

Add a "Rollback: Lua XML Cache Proxy" subsection to `docs/operations.md`: comment out the two `xml-handler-*` parameters in `lua.conf.xml`, `systemctl restart freeswitch`, verify `fs_cli -x "find_user_xml id <ext> <domain>"` returns the user, and confirm `storage/logs/laravel.log` shows new xml-handler requests. Note that `FS_LUA_PROXY_ENABLED=false` stops mirror writes but is not the kill-switch — removing the bindings is.

- [ ] **Step 6: Commit**

```bash
git add scripts/freeswitch/lua.conf.xml scripts/freeswitch/hiredis.conf.xml docs/operations.md
git commit -m "chore(freeswitch): bind lua directory cache handler with 16 hiredis connections and document rollback"
```

---

### Task 7: End-to-End Gate on the Lab Host

**Files:**
- Modify: `docs/lua-proxy-measurement-results.md` (append end-to-end gate results)
- Modify: `CHANGELOG.md` (Unreleased → Added)

**Interfaces:**
- Consumes: everything from Tasks 2–6
- Produces: a PROCEED/KEEP decision for enabling the proxy beyond the lab

- [ ] **Step 1: Enable and warm**

Set `FS_LUA_PROXY_ENABLED=true`, run `tpbx optimize:clear`, trigger one `fs_cli -x "find_user_xml id <ext> <domain>"`, then confirm in DB 0: `redis-cli -n 0 GET pbx:fsxml:tenant:user:<domain>:<ext>`, `GET pbx:fsxml:ver:<tenantId>`, and `GET pbx:fsxml:dir:<tenantId>:domain:<domain>:<ext>:v<version>` all exist.

- [ ] **Step 2: Prove Lua served the lookup**

Note the `/api/v1/xml-handler` request count in the nginx access log, run ten `find_user_xml` lookups, and confirm the count is unchanged.

- [ ] **Step 3: Prove immediate invalidation (Review Focus 1)**

Update an extension in the panel; confirm `pbx:fsxml:ver:<tenantId>` increments; run one lookup and confirm exactly one new `/api/v1/xml-handler` request (miss → rebuild), then two more lookups with no new requests (hit).

- [ ] **Step 4: Prove credential freshness (Review Focus 1)**

Change a SIP password in the panel, then re-register the lab SIPp endpoint with the new password (succeeds) and with the old password (fails). Record both outcomes.

- [ ] **Step 5: Prove multi-tenant isolation (Review Focus 3)**

Create a second tenant on a different domain with the same extension number; warm both; confirm each `find_user_xml` returns that tenant's own XML and that the two payload keys differ.

- [ ] **Step 6: Prove the failure paths (Review Focuses 4 and 5)**

Overwrite a payload key with a truncated string and confirm the lookup still succeeds via `mod_xml_curl`; stop Redis, confirm lookups still succeed via `mod_xml_curl` and the Laravel log shows only debug-level mirror failures; restart Redis.

- [ ] **Step 7: Rollback drill (Review Focus 5)**

Remove the bindings, restart FreeSWITCH, confirm lookups work through PHP, then re-apply the bindings and restart.

- [ ] **Step 8: Measure the after-state**

Repeat the Task 1 load run and append hits/misses latency to the measurement document. Compare against the Task 1 baseline and record one decision: KEEP (enable on reference deployments) or DISABLE (leave the code default-off and close the experiment).

- [ ] **Step 9: Update the changelog**

Add an `### Added` entry under `[Unreleased]`: optional default-off Lua directory cache proxy, with the note that enabling requires the `lua.conf.xml` bindings, tuned `hiredis.conf.xml`, and a FreeSWITCH restart, and that rollback is binding removal.

- [ ] **Step 10: Final verification and commit**

Run `tpbx optimize:clear`, `./vendor/bin/pint --dirty`, `./vendor/bin/pest tests/Unit/Services/FsXmlMirrorKeysTest.php tests/Feature/Services/FsXmlMirrorWriterTest.php tests/Feature/Support/RoutingCacheVersionMirrorTest.php tests/Feature/Services/DirectoryXmlMirrorTest.php tests/Feature/Http/XmlHandlerControllerTest.php`, and the shell harness. Then:

```bash
git add CHANGELOG.md docs/lua-proxy-measurement-results.md
git commit -m "docs(changelog): record lua directory cache proxy lab results"
```

---

## Out of Scope / Deferred

- **Dialplan proxying (phase 2).** Revisit only if Phase 1 sustains ≥ 90 % hit ratio and the deployment target still exceeds 60 req/s. Phase 2 requires: tenant derivation from `tenant_{id}_internal|public`, destination and global version in the key, and every dialplan input (gateways, time conditions, ring groups, IVRs, scanner signatures, limiters, markers) represented in the version or the key.
- **Configuration / `sofia.conf` proxying** — low lookup volume, no measured benefit.
- **`mod_hiredis` database selection** — not supported by the installed module; the mirror lives in DB 0 by design.
- **Installer wiring** for `lua.conf.xml` and `hiredis.conf.xml` — deferred until the feature is KEEP-enabled on reference deployments.

## Risks and Guards

| Risk | Guard |
|:--|:--|
| Stale credentials served after a panel change | Version-in-key plus Task 7 Step 3/4 proofs; no default version in Lua |
| Panel save breaks during a Redis outage | Best-effort writer with catch-and-log; Task 2 test |
| Cross-tenant key collision on shared domains | Tenant from PHP's resolved identity map; no domain-only fallback; Task 7 Step 5 |
| Malformed cached value reaches the XML parser | `<document` envelope check; Task 5 fixtures |
| Lua fault breaks registration | Whole body in `pcall`; automatic `nil`; binding rollback runbook |
| mod_hiredis connection starvation under burst load | `hiredis.conf.xml` tuned to 16 connections in Task 6 Step 1 |
| Unauthenticated Redis with REDIS_PASSWORD | Password parameter documented in `hiredis.conf.xml`; `-NOAUTH` fails soft to `mod_xml_curl` |
| Namespace drift between PHP and Lua | `pbx:fsxml` fixed as immutable constant `FsXmlMirrorKeys::NAMESPACE` |
| Unbounded experiment | Feature flag default off; Task 1 gate; Task 7 keep/disable decision |
