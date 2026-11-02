# Octane & RoadRunner Performance Improvement Plan (Lean Telephony XML Worker)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the PHP-FPM process-boot bottleneck for FreeSWITCH dynamic XML by running the XML Handler endpoint under a persistent resident, lean Laravel worker (RoadRunner via Laravel Octane) on `127.0.0.1:8090`, dropping lookup latency from ~80–200 ms to <3 ms and raising single-core throughput from ~20 req/s to 2,500+ req/s while retaining 100% of existing Laravel modular dialplan contributors and tests.

**Architecture:** A persistent, resident PHP engine powered by RoadRunner boots a lean Laravel worker in memory and listens for FreeSWITCH `mod_xml_curl` HTTP requests on loopback port 8090. Unlike full-stack web serving, this worker runs in a strictly scoped, lean telephony context—omitting session, CSRF, Livewire, and Blade overhead. Redis (DB 1) provides sub-millisecond cache hits with atomic lock thundering-herd protection. On cache misses, pre-connected PDO connections query MariaDB in ~2 ms using existing `DirectoryXmlBuilder` and `DialplanXmlBuilder` services. A dedicated post-request listener strictly clears tenant state to guarantee cross-tenant isolation. The fast controller and the `mod_hiredis` + Lua proxy are completely disabled (while preserving their code for rollback safety). Standard administrative web traffic remains on PHP-FPM/Nginx.

**Tech Stack:** Laravel 13, Laravel Octane 2.x, RoadRunner (Go supervisor + standard Debian PHP 8.5 CLI), FreeSWITCH 1.11 (`mod_xml_curl`), Redis 7, MariaDB 11, Pest.

**Spec:** Direct investigation report and architectural spec formulated in conversation `04069f8f-6f41-484a-b8e5-96fbd63d85a9`.

---

## Why We Are Doing This (Context & Problem Statement)

1. **PHP-FPM is a Fundamental Telephony Bottleneck**:
   FreeSWITCH queries dynamic XML on critical telephony events: every SIP registration, authentication challenge, and call leg setup. Under PHP-FPM, every single XML query forces PHP to boot from scratch, load Composer autoloaders, register service providers, and construct the Laravel dependency container. With standard static pools (5–12 workers), a small burst of concurrent SIP registrations or inbound call legs instantly starves the worker pool. Latency spikes from 60 ms to over 500 ms, triggering Sofia SIP transaction timeouts, registration failures, and dropped calls.

2. **The Fast Controller Was Only an Incremental Improvement**:
   To mitigate the boot cost, `XmlHandlerFastBootstrap` was introduced. It trimmed the booted service provider list from ~90 down to ~20. While this yielded an incremental ~40–70% throughput boost, it still suffered from the exact same structural flaw: **it still booted PHP, parsed files, and tore down memory on every single request**. It treated the symptom without removing the process-lifecycle bottleneck.

3. **The Lua Proxy (`mod_hiredis`) Added Too Much Complexity and Fragility**:
   To bypass PHP-FPM on cached lookups, a proxy layer using FreeSWITCH `mod_lua` and `mod_hiredis` was deployed. While it successfully reduced cache-hit latency, it introduced unacceptable architectural fragility:
   - **Dual Caching & State Drift**: Keys had to be mirrored from Laravel's cache into an unprefixed raw Redis DB 0 namespace (`pbx:fsxml:*`).
   - **Complex Cross-Language Invalidation**: Keeping Lua and PHP in sync required introducing monotonic tenant versions, global routing identity epochs (`:e<epoch>`), and fragile Eloquent observers.
   - **Mirroring Overhead**: Mirroring XML payloads on PHP requests imposed an 18% to 31% throughput penalty on PHP-FPM during saturation.
   - **Thundering Herds on Misses**: Any cache miss, cold start, or unmapped context dropped out of Lua and slammed directly into PHP-FPM, which still collapsed under concurrency.

4. **RoadRunner & Octane as a Lean Telephony Worker is the Superior Tradeoff**:
   Running the FreeSWITCH XML Handler under a persistent resident worker engine (RoadRunner via Laravel Octane) represents a far better architectural tradeoff than full-stack web servers or in-process C/Lua modules:
   - **Lean Telephony Scoping (Not Full Stack)**: The worker is configured strictly for stateless telephony XML generation (~65 MB RAM per worker). Web sessions, cookie encryption, CSRF verification, Livewire, and Blade template rendering remain completely isolated to PHP-FPM and Nginx (ports 80/443).
   - **Process Isolation via Binary IPC Pipes**: RoadRunner isolates PHP in child worker processes communicating via binary IPC pipes with a Go process supervisor. If a PHP worker encounters a fatal error or segfaults, only that child worker is recycled by RoadRunner without crashing the supervisor or the host web server (unlike CGO shared-memory crashes).
   - **Standard Debian PHP Toolchain**: RoadRunner executes Debian's standard `/usr/bin/php` (PHP 8.5.12) with standard APT extensions (`php8.5-mysql`, `php8.5-redis`), eliminating third-party bundled CGO PHP binaries and ensuring seamless security updates via `apt upgrade`.
   - **Raw Performance Without Boot Overhead**: The framework, database connection pool, and modular contributors stay resident in memory. Cache hits return in <1 ms; cache misses render in ~2 ms.
   - **No Lua Code to Maintain**: Eliminates the custom Lua proxy script, `mod_hiredis` dependency, and cross-language epoch synchronization.
   - **Protects the Database via Redis**: Standard Redis caching handles high-volume lookups with atomic locking to prevent cache stampedes.
   - **Preserves 100% of Laravel Architecture**: All existing Eloquent models, tenant isolation rules, modular `DialplanXmlContributor` plugins, and Pest test suites continue to function without rewriting business logic into Lua or C.
   - **Net Memory Savings**: Because the persistent worker handles 100% of high-concurrency telephony queries, the main PHP-FPM pool can be reduced from 12 static workers (540 MB) to 3 workers (120 MB). Combined with 2–4 lean RoadRunner workers (130–260 MB), the overall server memory footprint drops by ~200–350 MB RAM while dramatically boosting throughput.

---

## Global Constraints

- **Code Preservation Policy (Zero Premature Deletion)**: Do NOT delete the fast controller (`public/xml-handler-fast.php`, `app/Support/XmlHandlerFastBootstrap.php`) or Lua proxy (`scripts/freeswitch/tallpbx_xml_handler.lua`, `app/Services/Xml/FsXmlMirrorWriter.php`). They must remain in the codebase completely disabled via configuration and bindings so that an immediate, zero-downtime rollback is possible during initial rollout and load testing. Permanent code deletion will be revisited in a future milestone after production verification.
- **Zero regression in generated XML**: The XML returned by the persistent worker must be byte-for-byte identical to the XML generated by the current `XmlHandlerController`.
- **Strict tenant isolation**: In a resident environment where the container is not rebuilt per request, `TenantManager::clear()` must be executed deterministically on every request completion to ensure no tenant identity leaks across call legs.
- **Fail-safe fallback**: If the persistent worker service is stopped or restarting, Nginx or FreeSWITCH must fail gracefully without dropping calls or causing endless timeouts.
- **Lean Telephony Worker vs. Full-Stack Separation**: The persistent Octane daemon is strictly a lean telephony micro-worker, NOT a full-stack web application server. Administrative panel operations (`/panel`), user sessions, Livewire reactivity, and Blade views remain on standard PHP-FPM behind Nginx. The Octane worker serves only the FreeSWITCH loopback endpoints (`/api/v1/xml-handler`), avoiding session serialization, cookie decryption, and frontend view pipeline overhead.
- **Lean Telephony Provider Allowlist**: The lean worker must NOT register the 40+ administrative panel modules, Livewire view renderers, Boost dev tools, or Reverb WebSocket providers. It must strictly load the empirically proven allowlist from `XmlHandlerFastBootstrap::PROVIDERS` (9 core framework providers + 23 telephony module providers) when operating under `TALLPBX_WORKER_ROLE=telephony`.
- **Administrative panel isolation**: The administrative web UI (`/panel`, Livewire, Blade) continues running on standard PHP-FPM and Nginx (ports 80/443). The persistent worker is dedicated exclusively to telephony runtime requests.
- **Verification discipline**: All code changes must pass `./vendor/bin/pint --dirty` and targeted Pest tests before staging.

---

## Review Focus

1. **State Leakage Across Requests**: A tenant ID or authenticated user resolved in request $N$ persists into request $N+1$ on the same worker process.
2. **Database & Redis Connection Drift**: Long-lived worker connections timing out or entering broken transaction states after idle periods.
3. **Thundering Herd During Registrations**: Simultaneous registration requests from hundreds of phones forcing concurrent redundant XML builds instead of waiting on a shared lock.
4. **Service Crash Recovery**: Worker crashing under unexpected fatal errors; RoadRunner automatically recycles the dead PHP worker via binary IPC, and systemd supervises the top-level daemon with auto-restart.
5. **Cache Invalidation Latency**: An extension password or dialplan routing change in the UI must immediately invalidate the persistent worker's Redis cache entry so calls route immediately.

---

### Task 1: Octane & RoadRunner Integration (Lean Worker Setup) & Minimal Proof of Concept

**Files:**
- Modify: `composer.json`
- Create: `app/Support/TelephonyWorkerProviders.php`
- Modify: `app/Providers/ModuleServiceProvider.php`
- Create: `.rr.yaml`
- Modify: `config/octane.php`
- Modify: `config/freeswitch.php`
- Test: `tests/Feature/Http/XmlHandlerOctaneParityTest.php`

**Interfaces:**
- Consumes: `App\Http\Controllers\Api\XmlHandlerController::handle(Request $request): Response`
- Produces: Persistent HTTP listener on `127.0.0.1:8090/api/v1/xml-handler` (lean worker)

- [ ] **Step 1: Write parity test comparing XML output**

Write `tests/Feature/Http/XmlHandlerOctaneParityTest.php` asserting that directory and dialplan requests handled through standard container dispatch produce identical XML strings for a seeded tenant. Mirror the test cases established in `tests/Feature/Http/XmlHandlerFastPathTest.php` (domain directory lookup, not-found user lookup, dialplan with ring group/extensions/bridges) to guarantee byte-for-byte fidelity under the lean provider subset.

- [ ] **Step 2: Require `laravel/octane` and install RoadRunner**

Run: `COMPOSER_ALLOW_SUPERUSER=1 composer require laravel/octane spiral/roadrunner-cli`
Verify package is resolved cleanly for Laravel 13 and PHP 8.5.

- [ ] **Step 3: Define Telephony Provider Allowlist (`app/Support/TelephonyWorkerProviders.php`)**

Extract the empirically tested telephony provider allowlist from `app/Support/XmlHandlerFastBootstrap.php` into a dedicated support class:
```php
namespace App\Support;

final class TelephonyWorkerProviders
{
    /**
     * Module providers required to render FreeSWITCH XML (directory, dialplan, sofia).
     * Excludes all 40+ panel modules, billing, audit logs, and web UI modules.
     */
    public const MODULE_PROVIDERS = [
        \Modules\NumberTranslations\Providers\ModuleServiceProvider::class,
        \Modules\SipProfiles\Providers\ModuleServiceProvider::class,
        \Modules\FileStores\Providers\ModuleServiceProvider::class,
        \Modules\Gateways\Providers\ModuleServiceProvider::class,
        \Modules\Extensions\Providers\ModuleServiceProvider::class,
        \Modules\Bridges\Providers\ModuleServiceProvider::class,
        \Modules\CallBlocks\Providers\ModuleServiceProvider::class,
        \Modules\CallCenters\Providers\ModuleServiceProvider::class,
        \Modules\CallFlows\Providers\ModuleServiceProvider::class,
        \Modules\CallForwards\Providers\ModuleServiceProvider::class,
        \Modules\ConferenceCenters\Providers\ModuleServiceProvider::class,
        \Modules\Conferences\Providers\ModuleServiceProvider::class,
        \Modules\Emergency\Providers\ModuleServiceProvider::class,
        \Modules\FeatureCodes\Providers\ModuleServiceProvider::class,
        \Modules\FollowMe\Providers\ModuleServiceProvider::class,
        \Modules\HotDesking\Providers\ModuleServiceProvider::class,
        \Modules\InboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\IvrMenus\Providers\ModuleServiceProvider::class,
        \Modules\OutboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\PinNumbers\Providers\ModuleServiceProvider::class,
        \Modules\RingGroups\Providers\ModuleServiceProvider::class,
        \Modules\Security\Providers\ModuleServiceProvider::class,
        \Modules\TimeConditions\Providers\ModuleServiceProvider::class,
        \Modules\Voicemails\Providers\ModuleServiceProvider::class,
    ];
}
```

- [ ] **Step 4: Gate Module Provider Registration in `ModuleServiceProvider`**

In `app/Providers/ModuleServiceProvider.php`, check the worker role during `register()`. When `env('TALLPBX_WORKER_ROLE') === 'telephony'`, filter discovered module providers against `TelephonyWorkerProviders::MODULE_PROVIDERS`:
```php
$isTelephonyWorker = env('TALLPBX_WORKER_ROLE') === 'telephony';

foreach ($modules as $name => $manifest) {
    if (($manifest['enabled'] ?? true) === false) {
        continue;
    }

    foreach (self::providersFor($manifest) as $providerClass) {
        if ($isTelephonyWorker && ! in_array($providerClass, TelephonyWorkerProviders::MODULE_PROVIDERS, true)) {
            continue;
        }

        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }
}
```
This reduces active providers from ~90 to 24 in the resident worker, preventing the 40+ panel modules from ever entering worker memory.

- [ ] **Step 5: Publish and configure Octane with RoadRunner**

Run: `php artisan octane:install --server=roadrunner`
Configure `.rr.yaml` and `config/octane.php`:
- Bind HTTP listener strictly to loopback: `127.0.0.1:8090`.
- In `.rr.yaml`, inject `env: { TALLPBX_WORKER_ROLE: "telephony" }` so all RoadRunner worker processes boot with the lean provider filter.
- Configure worker command to use standard Debian `/usr/bin/php`.
- Tune worker pool for lean telephony: `num_workers: 2` (or `4` based on CPU cores), `max_jobs: 10000`, `allocate_timeout: 60s`.
- Disable unnecessary listeners (session warming, frontend assets) to maintain a lean worker footprint.
- Register request boundary cleaners.

- [ ] **Step 6: Verify Octane starts in test mode and confirm lean footprint**

Run: `php artisan octane:status` and verify runtime capability.
Verify memory usage: worker RSS should stay ~65 MB (not 180+ MB).

- [ ] **Step 7: Run tests and commit**

```bash
./vendor/bin/pint --dirty
./vendor/bin/pest tests/Feature/Http/XmlHandlerOctaneParityTest.php
git add composer.json composer.lock app/Support/TelephonyWorkerProviders.php app/Providers/ModuleServiceProvider.php config/octane.php .rr.yaml tests/Feature/Http/XmlHandlerOctaneParityTest.php
git commit -m "feat(octane): install roadrunner and enforce lean telephony provider allowlist"
```

---

### Task 2: Strict Tenant State Isolation & Request Sandbox Guards

**Files:**
- Create: `app/Listeners/Octane/ClearTenantContext.php`
- Create: `app/Listeners/Octane/VerifyCleanDatabaseState.php`
- Modify: `config/octane.php`
- Test: `tests/Feature/Octane/TenantStateIsolationTest.php`

**Interfaces:**
- Consumes: `Laravel\Octane\Events\RequestTerminated`, `Laravel\Octane\Events\RequestReceived`
- Produces: Deterministic reset of `TenantManager` and open database transactions

- [ ] **Step 1: Write state leakage test**

Write `tests/Feature/Octane/TenantStateIsolationTest.php` which executes two simulated back-to-back requests on the same container instance (Tenant A then Tenant B) and asserts Tenant A's context never bleeds into Tenant B.

- [ ] **Step 2: Implement `ClearTenantContext` listener**

Create `app/Listeners/Octane/ClearTenantContext.php`:
```php
namespace App\Listeners\Octane;

use App\Services\TenantManager;
use Laravel\Octane\Events\RequestTerminated;

class ClearTenantContext
{
    public function handle(RequestTerminated $event): void
    {
        app(TenantManager::class)->clear();
    }
}
```

- [ ] **Step 3: Register listeners in `config/octane.php`**

Add `ClearTenantContext::class` to `Octane::operationTerminated` and `Octane::requestTerminated` listener arrays.

- [ ] **Step 4: Run isolation tests**

Run: `./vendor/bin/pest tests/Feature/Octane/TenantStateIsolationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Listeners/Octane/ config/octane.php tests/Feature/Octane/TenantStateIsolationTest.php
git commit -m "security(octane): enforce tenant state reset on persistent request termination"
```

---

### Task 3: Redis Cache Streamlining & Thundering-Herd Protection

**Files:**
- Modify: `app/Services/Xml/DirectoryXmlBuilder.php`
- Modify: `app/Services/Xml/DialplanXmlBuilder.php`
- Test: `tests/Unit/Services/XmlBuilderCacheLockTest.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Cache::lock`
- Produces: Stampede-proof cached XML rendering using standard Redis DB 1

- [ ] **Step 1: Write unit test for atomic cache locking**

Create `tests/Unit/Services/XmlBuilderCacheLockTest.php` proving that concurrent requests for the same cache key wait on the lock rather than triggering multiple database queries.

- [ ] **Step 2: Refactor `DirectoryXmlBuilder` and `DialplanXmlBuilder` caching**

Add atomic cache locks while keeping existing caching logic:
```php
$cacheKey = $this->cacheKey(...);
$lockKey = "lock:{$cacheKey}";

return Cache::remember($cacheKey, $ttl, function () use ($lockKey, ...) {
    return Cache::lock($lockKey, 5)->block(3, function () use (...) {
        return $this->buildXml(...);
    });
});
```

- [ ] **Step 3: Verify existing XML builder tests pass**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerControllerTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Xml/ tests/Unit/Services/XmlBuilderCacheLockTest.php
git commit -m "perf(xml): add atomic cache locking and streamline redis caching in builders"
```

---

### Task 4: Systemd Service & FreeSWITCH Integration

**Files:**
- Create: `scripts/resources/systemd/tallpbx-xml-worker.service`
- Modify: `scripts/resources/freeswitch.sh`
- Modify: `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`
- Test: `tests/Feature/System/SystemdWorkerConfigTest.php`

**Interfaces:**
- Consumes: Systemd unit `/etc/systemd/system/tallpbx-xml-worker.service`
- Produces: FreeSWITCH routing requests to `http://127.0.0.1:8090/api/v1/xml-handler`

- [ ] **Step 1: Create systemd service unit**

Create `scripts/resources/systemd/tallpbx-xml-worker.service`:
```ini
[Unit]
Description=TallPBX FreeSWITCH XML Persistent Lean Worker (Octane/RoadRunner)
After=network.target redis-server.service mariadb.service
Wants=redis-server.service mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/tallpbx
Environment="TALLPBX_WORKER_ROLE=telephony"
ExecStart=/usr/bin/php /var/www/tallpbx/artisan octane:start --server=roadrunner --host=127.0.0.1 --port=8090 --workers=auto --max-requests=10000
Restart=always
RestartSec=3
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Update `scripts/resources/freeswitch.sh`**

Update the installer/resource script to configure `xml_curl.conf.xml` pointing to `http://127.0.0.1:8090/api/v1/xml-handler` and enable the systemd unit.

- [ ] **Step 3: Commit**

```bash
git add scripts/resources/systemd/tallpbx-xml-worker.service scripts/resources/freeswitch.sh
git commit -m "feat(systemd): add persistent xml worker systemd service and installer integration"
```

---

### Task 5: Disable Lua Proxy & Fast Controller (Retain Code for Reversible Rollback)

> **Important**: In accordance with the rollback-safety policy, **do NOT delete** `public/xml-handler-fast.php`, `app/Support/XmlHandlerFastBootstrap.php`, `scripts/freeswitch/tallpbx_xml_handler.lua`, or `app/Services/Xml/FsXmlMirrorWriter.php`. Retain all code files untouched. Completely disable the features by removing their active runtime bindings and configuration toggles. The decision to permanently purge or archive the code will be revisited in a future release after testing confirms production stability.

**Files:**
- Modify: `/etc/freeswitch/autoload_configs/lua.conf.xml` (remove XML bindings)
- Modify: `config/freeswitch.php` (`lua_proxy.enabled => false`)
- Modify: `scripts/resources/nginx.sh` (repoint `/api/v1/xml-handler` location block away from `xml-handler-fast.php` back to standard `index.php`)
- Modify: `scripts/resources/freeswitch.sh` (ensure `xml-handler-bindings` is omitted from `lua.conf.xml`)

**Interfaces:**
- Consumes: Clean FreeSWITCH config with Lua proxy bindings disabled
- Produces: Runtime configuration where FreeSWITCH exclusively queries `mod_xml_curl` and Nginx routes to `index.php`

- [ ] **Step 1: Remove `xml-handler-bindings` from `lua.conf.xml`**

In `/etc/freeswitch/autoload_configs/lua.conf.xml`, comment out or remove the XML handler script and bindings parameters:
```xml
<!-- xml-handler disabled in favor of persistent Octane worker
<param name="xml-handler-script" value="tallpbx_xml_handler.lua"/>
<param name="xml-handler-bindings" value="directory,dialplan"/>
-->
```

- [ ] **Step 2: Set `FS_LUA_PROXY_ENABLED=false`**

In `config/freeswitch.php`, ensure `lua_proxy.enabled` defaults to `false`. Mirror writing in `FsXmlMirrorWriter` will remain completely dormant.

- [ ] **Step 3: Repoint Nginx away from `xml-handler-fast.php`**

In `scripts/resources/nginx.sh`, remove the dedicated `location = /api/v1/xml-handler` pointing to `public/xml-handler-fast.php`. Nginx will forward XML requests to standard `index.php` (for fallback) or directly proxy to the Octane port `127.0.0.1:8090`.

- [ ] **Step 4: Verify existing tests pass with features disabled**

Run: `tpbx optimize:clear && ./vendor/bin/pest tests/Feature/Http/XmlHandlerControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit configuration changes**

```bash
git add config/freeswitch.php scripts/resources/nginx.sh scripts/resources/freeswitch.sh
git commit -m "config(xml): disable lua proxy bindings and repoint nginx away from fast controller"
```

---

### Task 6: Load Verification & Final Verification Gate

**Files:**
- Create: `docs/superpowers/plans/octane-measurement-results.md`

**Interfaces:**
- Consumes: `scripts/pbx-sipp-validate.sh`, `tpbx pbx:load-test:dialplan`
- Produces: Verification report confirming sub-3ms lookup times under concurrency

- [ ] **Step 1: Verify application boot & Pint code style**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
```

- [ ] **Step 2: Run targeted and HTTP tests**

Run:
```bash
./vendor/bin/pest tests/Feature/Http/XmlHandlerControllerTest.php
./vendor/bin/pest tests/Feature/Octane/
```

- [ ] **Step 3: Execute SIP load benchmark**

Run: `tpbx pbx:load-test:dialplan --timing` at `100x5` and `500x25` concurrency against `http://127.0.0.1:8090/api/v1/xml-handler`.
Confirm:
- Median response time is < 3 ms.
- Zero worker starvation / timeout errors.
- Concurrency ceiling is 2,500+ req/s.

- [ ] **Step 4: Record benchmark results and update CHANGELOG.md**

Update `CHANGELOG.md` under `[Unreleased]`:
- `### Added`: Persistent RoadRunner Octane lean XML worker service on port 8090.
- `### Changed`: FreeSWITCH `mod_xml_curl` gateway target repointed to persistent worker.
- `### Changed`: Disabled Lua proxy bindings and fast controller routing (code retained for rollback safety).
```

---

### Follow-on Extension (Optional — Ask First): CDR Ingestion Endpoint

> **Optional and undecided.** This is a candidate extension, not committed work. Do not implement it, schedule it, or plan around it without explicit approval from the operator. The decision belongs to the user once the worker is proven; treat silence as "do not build".

The CDR ingestion endpoint (`POST /api/v1/cdr`, consumed by FreeSWITCH's `mod_json_cdr`) still runs on PHP-FPM when this plan completes. Moving it onto the same persistent worker after Task 4 may be a worthwhile next step **if the operator asks for it** — it is deliberately **not** part of this plan and not assumed.

- **Why it might be worth doing**: ingestion currently pays the same ~0.4 s full framework boot per call leg. A resident worker would likely bring it to single-digit milliseconds, which would also satisfy the ingestion latency goal left open in Task 7 of [`docs/superpowers/plans/2026-10-31-cdr-modernization-pipeline.md`](superpowers/plans/2026-10-31-cdr-modernization-pipeline.md).
- **Why it is low risk if attempted**: the endpoint is already worker-safe — it is stateless (constant-time token check plus one Redis `XADD` on database 3), keeps no request state, and its `cdr-ingestion` throttle keys off the shared cache store. It inherits Task 2's post-request tenant sandbox automatically because it resolves no tenant itself.
- **What it would need**: a `/api/v1/cdr` route on the worker listener, and a decision on the FreeSWITCH-facing hop — point the module's `url` in `json_cdr.conf.xml` at the worker directly, or keep the nginx path and proxy it to port 8090.
- **Why it is not scheduled**: the POC's gate is byte-identical XML under load; folding an ingestion migration into it would blur that comparison. If, and only if, the operator approves it after Tasks 1–4 prove the worker stable, it becomes its own task.