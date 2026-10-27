# Livewire Session Expiration & Redis Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> [!NOTE]
> **Status: Ready for Implementation (v3 — Post-Review Revision)**
> Incorporates verified findings from [`docs/session-expiration-and-update-resilience-plan-review.md`](session-expiration-and-update-resilience-plan-review.md) plus test-harness isolation fixes (`SafeTestCommand`, `TestEnvironmentSafetyTest`, and `tests/Feature/` container binding).

**Goal:** Eliminate all native browser `window.confirm()` popups across TallPBX (Livewire HTTP 419 expiry, Livewire empty-200 responses, and the 5 remaining `wire:confirm` directives) while isolating Redis sessions into DB 2 so cache flushes, module lifecycle operations, and web Git updates never evict active logins.

**Architecture:** Dedicated `session` Redis connection (`REDIS_SESSION_DB=2`, test DB `13`) separates stateful user sessions from volatile cache (`DB 1`) and persistent coordination/security state (`DB 0`). On the frontend, a Livewire 4 `Livewire.interceptRequest()` hook in `resources/js/app.js` suppresses both built-in `window.confirm()` fallbacks, cancels doomed post-419 `wire:poll` requests, and opens a peer-aligned Alpine/DaisyUI `<x-session-expired-modal />`, while the 5 remaining `wire:confirm` attributes migrate to `<x-confirmation-modal>`.

**Tech Stack:** PHP 8.5, Laravel 13, Redis (`phpredis`), Livewire 4.4, Alpine.js, Tailwind CSS v4, DaisyUI 5, Pest 4 + Playwright.

**Spec:** [`docs/session-expiration-and-update-resilience-plan-review.md`](session-expiration-and-update-resilience-plan-review.md)

## Global Constraints

- **Active branch:** `4.x` (no backwards-compatibility shims for 3.x/2.x).
- **UI token parity:** Match `resources/views/components/confirmation-modal.blade.php` tokens (`bg-black/50`, `h-12 w-12 text-warning`, `mt-2 text-lg font-semibold`, `mt-3 text-sm text-base-content/70`, unsized modal footer `btn btn-primary`). Use DaisyUI semantic colors only.
- **Translation parity:** 100% parity across `en`, `es`, and `fr`. Module keys in `app-modules/certificates/lang/{en,es,fr}/admin.php` MUST use the flat `certificates_*` prefix with zero root or cross-module collisions.
- **Test container binding:** `tests/Pest.php` binds `Tests\TestCase` only to `Feature`, `Browser`, `app-modules`, `Unit/Jobs`, and `Unit/Services/FreeSwitchServiceTest.php`. All tests calling `config()`, `Cache`, `Redis`, or Artisan MUST live under `tests/Feature/` or `tests/Browser/`, never `tests/Unit/Session/`.
- **Test runner parity:** Every test environment override in `phpunit.xml` MUST be mirrored in `app/Console/Commands/SafeTestCommand.php` so `php artisan test --compact --parallel` isolates ParaTest workers identically.
- **Shell script safety:** Validate every modified shell script with `bash -n`; preserve idempotency in `scripts/resources/tall.sh`.

## Review Focus

- **Transient empty `200 OK` during `/panel/git-update` polling:** Must call `preventDefault()` to suppress Livewire's `"The server returned an empty response"` popup, **without** setting `isSessionExpired = true` or opening the Session Expired modal on a single transient blip while the session in DB 2 is still valid.
- **Post-419 `wire:poll.1s` request storm:** Because `preventDefault()` returns early before Livewire sets its internal `sessionExpired` stop flag, subsequent requests after a `419` must be aborted via `request.cancel()` until `livewire:navigated` or page reload.
- **Stale `.env` on existing deployments (`SESSION_CONNECTION=cache`):** Existing `.env` files override `config/session.php`'s new `'session'` default; `/panel/git-update` must surface a clear warning banner when `session.driver === 'redis'` and `session.connection === 'cache'`.
- **`scripts/run-cache-sweep.sh` DB 0 collateral wipe:** Bare `redis-cli flushdb` flushes DB 0 (security sliding windows, scanner dedup keys, mutex locks) instead of DB 1; must target `${REDIS_CACHE_DB:-1}`.
- **Existing Livewire delete action signatures:** `CertificateManager::deleteCertificate(int $id, CertificateExecutorInterface $executor)` and `deleteDnsCredential(int $id)` must keep their existing signatures (or clean up pending modal state inside them) so existing direct-call tests pass alongside modal-driven flows.

---

## 1. Problem Statement & Motivation

During web UI Git updates (`/panel/git-update`), module lifecycle operations, and destructive panel actions, two categories of defects degrade the administrative experience:

1. **Infrastructure Issue (Session Eviction & Keyspace Cross-Contamination)**:
   - `GitUpdateService::runPipeline()` (line 457), its rollback path (line 495), and `ModuleLifecycleService` (lines 236, 355) execute `php artisan optimize:clear`, which runs `cache:clear` → `RedisStore::flush()` → `FLUSHDB`.
   - In `.env.example`, `scripts/resources/tall.sh` (lines 236–238), and `config/session.php` (line 78), `SESSION_CONNECTION` defaults to `'cache'`, mapping to Redis DB `1` (`config/database.php` line 175). Flushing application cache wipes every active user session in DB `1`.
   - Additionally, `scripts/run-cache-sweep.sh` (line 67) runs a bare `redis-cli flushdb` with no `-n` flag, inadvertently flushing **DB `0`** (persistent coordination, queues, locks, and security intrusion-detection counters) instead of DB `1`.

2. **Frontend UI Issue (Unstyled Native Browser `window.confirm()` Popups)**:
   - **HTTP 419 Session Expiration Popup**: When a session or CSRF token is invalidated, Livewire (`vendor/livewire/livewire/dist/livewire.esm.js` lines 12804–12811) triggers `confirm("This page has expired.\nWould you like to refresh the page?") && window.location.reload()`.
   - **Empty 200 Response Popup**: Immediately below the 419 branch (lines 12813–12817), Livewire triggers `confirm("The server returned an empty response.\nWould you like to refresh the page?") && window.location.reload()` when `response.ok && responseBody.trim() === ""` (such as a transient response blip mid-update).
   - **Remaining `wire:confirm` Directives**: Five buttons across `app-modules/certificates` (lines 265, 591) and `app-modules/security` (`geo-blocking` line 69, `firewall-rules` line 256, `external-blocklists` line 110) still invoke native `window.confirm()` instead of `<x-confirmation-modal>`.

---

## 2. Architecture & Design Principles

### 2.1 Frontend Strategy: Livewire 4 `interceptRequest` + DaisyUI Modals
1. **Canonical Livewire 4 Request Interceptor (`resources/js/app.js`)**:
   - Register `Livewire.interceptRequest(({ request, onError }) => ...)` inside `livewire:init`.
   - **HTTP 419 Handling & Polling Storm Guard**: On `response.status === 419`, call `preventDefault()`, set `isSessionExpired = true`, and dispatch `tallpbx:session-expired` once. Cancel any subsequent requests (`request.cancel()`) while `isSessionExpired` is `true` so `wire:poll.1s="pollProgress"` stops firing doomed requests. Reset `isSessionExpired = false` on `livewire:navigated`.
   - **Empty 200 Response Suppression**: On `response.ok && typeof body === 'string' && body.trim() === ''`, call `preventDefault()` to block the native browser popup without setting `isSessionExpired = true` on a transient blip, allowing active `wire:poll` loops to recover automatically on the next tick.
2. **Peer-Aligned `<x-session-expired-modal />`**:
   - Mounted once in `resources/views/layouts/app.blade.php` before `@livewireScripts`.
   - Uses `window.location.reload()` on its primary button: if the 419 was caused by CSRF rotation or re-authentication in another tab (under either `admin` or `web` tenant guard), reload keeps the user on their current page; if unauthenticated on both guards, `AuthPanelMiddleware` redirects to `panel.login`.
   - Documented inline comments explain the two intentional deviations from `confirmation-modal.blade.php`: client-side Alpine `x-show` (required when Livewire cannot roundtrip) and no Escape/backdrop dismiss (expired sessions cannot continue mutating page state).

### 2.2 Infrastructure Strategy: Standalone Redis Database Isolation
Standalone and Sentinel Redis deployments provide numbered databases `0–15` (note: Redis Cluster supports DB `0` only and would require separate Redis endpoints rather than numbered DB selection):
- **DB 0 (`REDIS_DB`)**: Persistent coordination — Redis queues (`queue.connections.redis`), distributed mutex locks (`cache.stores.redis.lock_connection`), intrusion-detection sliding windows (`tallpbx:security:attempts:*`), and SIP scanner deduplication keys.
- **DB 1 (`REDIS_CACHE_DB`)**: Volatile, disposable cache — FreeSWITCH `mod_xml_curl` XML fragments, ACL cache, routing version tags, and `RateLimiter` counters. Safe to purge via `cache:clear` / `FLUSHDB`.
- **DB 2 (`REDIS_SESSION_DB`)**: Stateful HTTP sessions (`session.connection = 'session'`). Structurally isolated from `cache:clear` and `optimize:clear`.
- **DB 13 (`REDIS_SESSION_DB` in testing)**: Isolated test session database in `phpunit.xml` and `SafeTestCommand`.
- **DB 14 & 15**: Test suite cache (`14`) and coordination/security (`15`) databases under prefix `tallpbx-test-`.

### 2.3 Upgrade, Eviction & Persistence Lifecycle
- **Existing Install Detection & One-Time Cutover**: Existing servers retain `SESSION_CONNECTION=cache` in `.env` until updated. `/panel/git-update` renders a warning banner whenever `config('session.driver') === 'redis' && config('session.connection') === 'cache'`, guiding operators to set `SESSION_CONNECTION=session` (and `SESSION_ENCRYPT=true` if currently `false`) in `.env` so both changes share a single re-authentication cutover.
- **Memory, Eviction & Restart Behavior**: Isolating sessions in DB 2 guarantees **structural immunity** from `cache:clear` (`FLUSHDB` on DB 1). On installer-provisioned hosts (`scripts/resources/php.sh` `configure_redis()`), Redis enforces `maxmemory 256mb` and `maxmemory-policy volatile-lru` across all DBs globally; because Laravel sessions carry TTLs, eviction safety under `volatile-lru` is **capacity-based** (~1.16 MB used of 256 MB; dev hosts predating `configure_redis()` default to `maxmemory 0` / `noeviction`). Debian's default RDB snapshotting (`save 3600 1 300 100 60 10000`) preserves DB 2 sessions across clean `systemctl restart redis-server` restarts; only ungraceful crashes between RDB checkpoints lose unsaved session writes.

---

## 3. Implementation Tasks

### Task 1: Redis Session DB-2 Isolation, Test Runner Safety & Provisioning Scripts

**Files:**
- Modify: `tests/Feature/TestEnvironmentSafetyTest.php:368-377`
- Create: `tests/Feature/Session/RedisSessionIsolationTest.php`
- Modify: `tests/Feature/InstallerDefaultsTest.php:87-103`
- Modify: `config/database.php:170-185`
- Modify: `config/session.php:78`
- Modify: `phpunit.xml:40-46`
- Modify: `app/Console/Commands/SafeTestCommand.php:75-83`
- Modify: `.env.example:34,69`
- Modify: `scripts/resources/tall.sh:233-239`
- Modify: `scripts/run-cache-sweep.sh:65-68`

**Interfaces:**
- Produces: `config('database.redis.session')` (default DB `'2'`, test DB `'13'`), `config('session.connection') === 'session'`.

- [x] **Step 1: Write failing isolation, behavioral Redis flush, and script tests**

Add `->and((string) config('database.redis.session.database'))->toBe('13')` and `->and((string) config('session.connection'))->toBe('session')` to `it('runs tests against the dedicated Redis test workspaces')` in `tests/Feature/TestEnvironmentSafetyTest.php`.

Create `tests/Feature/Session/RedisSessionIsolationTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

it('defaults redis.session.database to 2 when REDIS_SESSION_DB is unset', function (): void {
    $previous = getenv('REDIS_SESSION_DB');
    $serverPrev = $_SERVER['REDIS_SESSION_DB'] ?? null;
    $envPrev = $_ENV['REDIS_SESSION_DB'] ?? null;

    putenv('REDIS_SESSION_DB');
    unset($_SERVER['REDIS_SESSION_DB'], $_ENV['REDIS_SESSION_DB']);

    try {
        $config = require base_path('config/database.php');
        expect((string) $config['redis']['session']['database'])->toBe('2')
            ->and((string) $config['redis']['session']['database'])
            ->not->toBe((string) $config['redis']['cache']['database']);
    } finally {
        if ($previous !== false) {
            putenv("REDIS_SESSION_DB={$previous}");
        }
        if ($serverPrev !== null) {
            $_SERVER['REDIS_SESSION_DB'] = $serverPrev;
        }
        if ($envPrev !== null) {
            $_ENV['REDIS_SESSION_DB'] = $envPrev;
        }
    }
});

it('preserves Redis session keys in DB 13 when the Redis cache store in DB 14 is flushed', function (): void {
    $sessionStore = new RedisStore(app('redis'), 'session_test_', 'session');
    $handler = new CacheBasedSessionHandler(
        new \Illuminate\Cache\Repository($sessionStore),
        120,
    );

    $sessionId = 'isolation-check-'.bin2hex(random_bytes(6));
    $handler->write($sessionId, 'serialized-admin-session');
    Cache::store('redis')->put('volatile-xml-fragment', '<document/>', 60);

    try {
        expect($handler->read($sessionId))->toBe('serialized-admin-session')
            ->and(Cache::store('redis')->get('volatile-xml-fragment'))->toBe('<document/>');

        Cache::store('redis')->flush();

        expect(Cache::store('redis')->get('volatile-xml-fragment'))->toBeNull()
            ->and($handler->read($sessionId))->toBe('serialized-admin-session');
    } finally {
        $handler->destroy($sessionId);
    }
});
```

In `tests/Feature/InstallerDefaultsTest.php`, add assertions that:
- `.env.example` contains `SESSION_CONNECTION=session` and `REDIS_SESSION_DB=2` (and not `SESSION_CONNECTION=cache`).
- `scripts/resources/tall.sh` contains `SESSION_CONNECTION=session` and does not contain `SESSION_CONNECTION=cache`.
- `scripts/run-cache-sweep.sh` flushes `${REDIS_CACHE_DB:-1}` rather than a bare `redis-cli flushdb` against DB 0.

- [x] **Step 2: Run targeted tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php tests/Feature/Session/RedisSessionIsolationTest.php tests/Feature/InstallerDefaultsTest.php`
Expected: FAIL (`database.redis.session.database` is null, `session.connection` is `'cache'`, script assertions fail).

- [x] **Step 3: Implement Redis `session` connection, test isolation, and script updates**

1. `config/database.php`: Add `'session'` under `'redis'` mirroring `'cache'` with `'database' => env('REDIS_SESSION_DB', '2')`.
2. `config/session.php`: Change line 78 to `'connection' => env('SESSION_CONNECTION', 'session')`.
3. `phpunit.xml`: Add `<env name="REDIS_SESSION_DB" value="13" force="true"/>` and `<env name="SESSION_CONNECTION" value="session" force="true"/>`.
4. `app/Console/Commands/SafeTestCommand.php`: Add `'REDIS_SESSION_DB' => '13'` and `'SESSION_CONNECTION' => 'session'` in `testingEnvironmentVariables()`.
5. `.env.example`: Set `SESSION_CONNECTION=session` (line 34) and add `REDIS_DB=0`, `REDIS_CACHE_DB=1`, `REDIS_SESSION_DB=2` under `REDIS_PORT=6379`.
6. `scripts/resources/tall.sh`: Update lines 236–238 from `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`, and ensure `REDIS_SESSION_DB=2` is written idempotently if absent.
7. `scripts/run-cache-sweep.sh`: Replace bare `redis-cli flushdb` on line 67 with `redis-cli -n "${REDIS_CACHE_DB:-1}" flushdb > /dev/null 2>&1` and add a plain-English comment explaining that DB 0 (security/locks) and DB 2 (sessions) must never be flushed during XML cache sweeps.
8. Update local `.env` (`SESSION_CONNECTION=session`, `REDIS_SESSION_DB=2`) so local manual verification reflects production defaults.

- [x] **Step 4: Validate shell syntax and run targeted tests**

Run: `bash -n scripts/resources/tall.sh scripts/run-cache-sweep.sh && php artisan optimize:clear && ./vendor/bin/pest tests/Feature/TestEnvironmentSafetyTest.php tests/Feature/Session/RedisSessionIsolationTest.php tests/Feature/InstallerDefaultsTest.php`
Expected: PASS

---

### Task 2: Root & Certificates Module Translation Dictionaries (`en`, `es`, `fr`)

**Files:**
- Modify: `lang/en/admin.php`
- Modify: `lang/es/admin.php`
- Modify: `lang/fr/admin.php`
- Modify: `app-modules/certificates/lang/en/admin.php`
- Modify: `app-modules/certificates/lang/es/admin.php`
- Modify: `app-modules/certificates/lang/fr/admin.php`
- Test: `tests/Feature/Architecture/TranslationParityTest.php`

**Interfaces:**
- Produces root `admin.*` keys: `session_expired_title`, `session_expired_message`, `session_expired_action`, `git_session_cache_warning`.
- Produces module `admin.certificates_*` keys: `certificates_delete_confirm_title`, `certificates_delete_confirm_message`, `certificates_delete_credential_confirm_title`, `certificates_delete_credential_confirm_message`, `certificates_delete_action`.

- [x] **Step 1: Add root and module translation keys across `en`, `es`, and `fr`**

Root `lang/en/admin.php`:
```php
'session_expired_title' => 'Session Expired',
'session_expired_message' => 'Your session has expired. Please refresh the page or sign in again to continue.',
'session_expired_action' => 'Continue',
'git_session_cache_warning' => 'Session storage currently shares the application cache connection (SESSION_CONNECTION=cache). Set SESSION_CONNECTION=session in .env so updates and cache clears do not sign out active administrators.',
```

Root `lang/es/admin.php`:
```php
'session_expired_title' => 'Sesión expirada',
'session_expired_message' => 'Su sesión ha expirado. Por favor, actualice la página o inicie sesión nuevamente para continuar.',
'session_expired_action' => 'Continuar',
'git_session_cache_warning' => 'El almacenamiento de sesiones comparte actualmente la conexión de caché de la aplicación (SESSION_CONNECTION=cache). Configure SESSION_CONNECTION=session en .env para que las actualizaciones y limpiezas de caché no cierren las sesiones activas de los administradores.',
```

Root `lang/fr/admin.php`:
```php
'session_expired_title' => 'Session expirée',
'session_expired_message' => 'Votre session a expiré. Veuillez actualiser la page ou vous reconnecter pour continuer.',
'session_expired_action' => 'Continuer',
'git_session_cache_warning' => 'Le stockage des sessions partage actuellement la connexion de cache de l\'application (SESSION_CONNECTION=cache). Définissez SESSION_CONNECTION=session dans .env afin que les mises à jour et nettoyages du cache ne déconnectent pas les administrateurs actifs.',
```

Module `app-modules/certificates/lang/en/admin.php` (strictly adhering to the flat `certificates_*` prefix):
```php
'certificates_delete_action' => 'Delete',
'certificates_delete_confirm_title' => 'Delete Certificate?',
'certificates_delete_confirm_message' => 'Are you sure you want to delete certificate ":name"? This action cannot be undone.',
'certificates_delete_credential_confirm_title' => 'Delete DNS Credential?',
'certificates_delete_credential_confirm_message' => 'Are you sure you want to delete DNS credential ":name"?',
```

Module `app-modules/certificates/lang/es/admin.php`:
```php
'certificates_delete_action' => 'Eliminar',
'certificates_delete_confirm_title' => '¿Eliminar certificado?',
'certificates_delete_confirm_message' => '¿Está seguro de que desea eliminar el certificado ":name"? Esta acción no se puede deshacer.',
'certificates_delete_credential_confirm_title' => '¿Eliminar credencial DNS?',
'certificates_delete_credential_confirm_message' => '¿Está seguro de que desea eliminar la credencial DNS ":name"?',
```

Module `app-modules/certificates/lang/fr/admin.php`:
```php
'certificates_delete_action' => 'Supprimer',
'certificates_delete_confirm_title' => 'Supprimer le certificat ?',
'certificates_delete_confirm_message' => 'Êtes-vous sûr de vouloir supprimer le certificat « :name » ? Cette action est irréversible.',
'certificates_delete_credential_confirm_title' => 'Supprimer l\'identifiant DNS ?',
'certificates_delete_credential_confirm_message' => 'Êtes-vous sûr de vouloir supprimer l\'identifiant DNS « :name » ?',
```

- [x] **Step 2: Run translation parity & collision architecture suite**

Run: `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS (100% locale parity, zero root or cross-module key collisions).

---

### Task 3: Git Updater Legacy `SESSION_CONNECTION=cache` Preflight Warning

**Files:**
- Modify: `tests/Feature/Livewire/GitUpdateTest.php`
- Modify: `app-modules/admin/src/Livewire/GitUpdate.php`
- Modify: `app-modules/admin/resources/views/git-update.blade.php:15-22`

**Interfaces:**
- Consumes: `admin.git_session_cache_warning` from Task 2.
- Produces: `#[Computed] public function usesSharedSessionCache(): bool` on `Modules\Admin\Livewire\GitUpdate`.

- [x] **Step 1: Write failing Livewire tests in `tests/Feature/Livewire/GitUpdateTest.php`**

Add two test cases:
1. When `config(['session.driver' => 'redis', 'session.connection' => 'cache'])`, mounting `GitUpdate` renders `__('admin.git_session_cache_warning')`.
2. When `config(['session.driver' => 'redis', 'session.connection' => 'session'])`, mounting `GitUpdate` `->assertDontSee(__('admin.git_session_cache_warning'))`.

- [x] **Step 2: Run targeted test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Livewire/GitUpdateTest.php --filter="session"`
Expected: FAIL (warning copy not rendered).

- [x] **Step 3: Implement `usesSharedSessionCache(): bool` in `GitUpdate.php` and `<x-inline-alert>` in `git-update.blade.php`**

Add `#[Computed] public function usesSharedSessionCache(): bool` returning `config('session.driver') === 'redis' && config('session.connection') === 'cache'`. When `$this->usesSharedSessionCache` is `true`, render `<x-inline-alert type="warning">{{ __('admin.git_session_cache_warning') }}</x-inline-alert>` at the top of `app-modules/admin/resources/views/git-update.blade.php`.

- [x] **Step 4: Run targeted test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Livewire/GitUpdateTest.php`
Expected: PASS

---

### Task 4: `<x-session-expired-modal />`, Livewire 4 `interceptRequest` Hook & Browser Test

**Files:**
- Create: `tests/Feature/Livewire/SessionExpirationModalTest.php`
- Create: `tests/Browser/SessionExpirationBrowserTest.php`
- Create: `resources/views/components/session-expired-modal.blade.php`
- Modify: `resources/views/layouts/app.blade.php:233-235`
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `admin.session_expired_title`, `admin.session_expired_message`, `admin.session_expired_action` from Task 2.
- Produces: `<x-session-expired-modal />` listening for window event `tallpbx:session-expired`.

- [x] **Step 1: Write failing feature and Playwright browser tests**

Create `tests/Feature/Livewire/SessionExpirationModalTest.php` asserting that authenticated panel pages (admin and tenant guards) render `role="dialog"` with `id="session-expired-modal-title"` and translated `admin.session_expired_*` strings.

Create `tests/Browser/SessionExpirationBrowserTest.php` (using `setUpSmokeAdmin()` + `$this->loginAs($this->admin, 'admin')`):
1. **HTTP 419 interception & modal open**: Visit `/panel/git-update`, install a `window.confirm` spy (`window.__confirmCalled = false`), invalidate the client CSRF token in-place via `$page->script(...)`, click the Fetch button (`button[wire\:click="fetch"]`), and assert `#session-expired-modal-title` (`'Session Expired'`) is visible and `window.__confirmCalled === false`.
2. **Transient empty `200 OK` suppression**: Visit `/panel/git-update`, mock `window.fetch` for one `/livewire/update` request to return `new Response('', { status: 200 })`, click Fetch, and assert `window.__confirmCalled === false` AND `#session-expired-modal-title` stays hidden so subsequent Livewire requests continue working.

- [x] **Step 2: Run feature test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php`
Expected: FAIL (`session-expired-modal-title` missing from layout).

- [x] **Step 3: Implement `<x-session-expired-modal />`, mount in `layouts/app.blade.php`, and wire `interceptRequest` in `resources/js/app.js`**

Create `resources/views/components/session-expired-modal.blade.php`:
```blade
{{--
    Intentional deviations from <x-confirmation-modal>:
    1. Driven client-side via Alpine `x-show` rather than server `@if ($open)` because
       this modal opens specifically when Livewire requests can no longer roundtrip (HTTP 419).
    2. Omits Escape-key and backdrop dismiss because an expired session requires a page
       reload / re-authentication before any panel action can succeed.
--}}
<div
    x-data="{ open: false }"
    x-on:tallpbx:session-expired.window="open = true"
    x-cloak
>
    <div
        x-show="open"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="session-expired-modal-title"
    >
        <div class="relative w-full max-w-md rounded-box bg-base-100 p-6 text-center shadow-2xl">
            <x-heroicon-o-clock class="mx-auto h-12 w-12 text-warning" />
            <h2 id="session-expired-modal-title" class="mt-2 text-lg font-semibold">
                {{ __('admin.session_expired_title') }}
            </h2>
            <p class="mt-3 text-sm text-base-content/70">
                {{ __('admin.session_expired_message') }}
            </p>
            <div class="mt-6 flex justify-center">
                <button
                    type="button"
                    @click="window.location.reload()"
                    class="btn btn-primary"
                >
                    {{ __('admin.session_expired_action') }}
                </button>
            </div>
        </div>
    </div>
</div>
```

Mount `<x-session-expired-modal />` in `resources/views/layouts/app.blade.php` immediately above `@livewireScripts`.

In `resources/js/app.js`, add:
```javascript
let isSessionExpired = false;

document.addEventListener('livewire:init', () => {
    Livewire.interceptRequest(({ request, onError }) => {
        if (isSessionExpired) {
            request.cancel();
            return;
        }

        onError(({ response, body, preventDefault }) => {
            if (response?.status === 419) {
                preventDefault();
                isSessionExpired = true;
                window.dispatchEvent(new CustomEvent('tallpbx:session-expired'));
                return;
            }

            // Suppress Livewire's second native confirm() on transient empty 200 responses
            // (e.g. during asset/cache swaps) without falsely marking the session expired.
            if (response?.ok && typeof body === 'string' && body.trim() === '') {
                preventDefault();
            }
        });
    });
});

document.addEventListener('livewire:navigated', () => {
    isSessionExpired = false;
});
```

- [x] **Step 4: Build Vite bundle and run feature + browser tests**

Run: `npm run build && php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php && bash scripts/test-browser.sh tests/Browser/SessionExpirationBrowserTest.php`
Expected: PASS

---

### Task 5: Replace All 5 Remaining `wire:confirm` Directives with `<x-confirmation-modal>`

**Files:**
- Modify: `app-modules/certificates/tests/Feature/CertificateManagerLivewireTest.php`
- Modify: `app-modules/security/tests/SecurityManagerGeoBlockingTest.php`
- Modify: `app-modules/security/tests/SecurityManagerFirewallRulesTest.php`
- Modify: `app-modules/security/tests/SecurityManagerThreatFeedTest.php`
- Modify: `tests/Feature/Livewire/SessionExpirationModalTest.php`
- Modify: `app-modules/certificates/src/Livewire/CertificateManager.php:487-515,746-763`
- Modify: `app-modules/certificates/resources/views/certificate-manager.blade.php:263-271,589-595`
- Modify: `app-modules/security/src/Livewire/SecurityManager.php` (and applicable traits under `app-modules/security/src/Livewire/Concerns/`)
- Modify: `app-modules/security/resources/views/security-manager.blade.php`
- Modify: `app-modules/security/resources/views/tabs/geo-blocking.blade.php:69`
- Modify: `app-modules/security/resources/views/tabs/firewall-rules.blade.php:256`
- Modify: `app-modules/security/resources/views/tabs/external-blocklists.blade.php:110`

**Interfaces:**
- Consumes: `admin.certificates_delete_*` keys from Task 2; existing `admin.security_geo_clear_*`, `admin.security_prefilter_reset_*`, `admin.security_threat_feed_remove_blocks_*` keys in `app-modules/security/lang/*/admin.php`.

- [x] **Step 1: Write failing confirmation-modal & zero-`wire:confirm` architecture tests**

1. In `tests/Feature/Livewire/SessionExpirationModalTest.php`, add an architecture test scanning every `*.blade.php` file in `resources/views/` and `app-modules/` and asserting zero occurrences of `wire:confirm`.
2. In `CertificateManagerLivewireTest.php`, add tests verifying `confirmDeleteCertificate(int $id)` / `cancelDeleteCertificate()` and `confirmDeleteDnsCredential(int $id)` / `cancelDeleteDnsCredential()` open/close `<x-confirmation-modal>` and execute deletion on confirm.
3. In `SecurityManagerGeoBlockingTest.php`, `SecurityManagerFirewallRulesTest.php`, and `SecurityManagerThreatFeedTest.php`, add tests verifying `confirmClearGeoRules()` / `cancelClearGeoRules()`, `confirmResetPreFilterOrder()` / `cancelResetPreFilterOrder()`, and `confirmRemoveAllFeedBlocks()` / `cancelRemoveAllFeedBlocks()`.

- [x] **Step 2: Run targeted tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php app-modules/certificates/tests/Feature/CertificateManagerLivewireTest.php app-modules/security/tests/SecurityManagerGeoBlockingTest.php app-modules/security/tests/SecurityManagerFirewallRulesTest.php app-modules/security/tests/SecurityManagerThreatFeedTest.php`
Expected: FAIL (5 `wire:confirm` occurrences detected; `confirm*` methods undefined).

- [x] **Step 3: Implement modal confirmation flows in `CertificateManager` and `SecurityManager`**

1. **`CertificateManager`**:
   - Add `$pendingDeleteCertificateId` / `$pendingDeleteCertificateName`, `confirmDeleteCertificate(int $id): void`, `cancelDeleteCertificate(): void`, and reset pending state inside `deleteCertificate(int $id, CertificateExecutorInterface $executor): void`.
   - Add `$pendingDeleteCredentialId` / `$pendingDeleteCredentialName`, `confirmDeleteDnsCredential(int $id): void`, `cancelDeleteDnsCredential(): void`, and reset pending state inside `deleteDnsCredential(int $id): void`.
   - Replace `wire:confirm` on lines 265 and 591 of `certificate-manager.blade.php` with `wire:click="confirmDeleteCertificate({{ $cert->id }})"` and `wire:click="confirmDeleteDnsCredential({{ $cred->id }})"`, rendering `<x-confirmation-modal>` for each.
2. **`SecurityManager`**:
   - Add `$confirmingClearGeoRules` (`confirmClearGeoRules()` / `cancelClearGeoRules()`, reset in `clearGeoRules()`).
   - Add `$confirmingResetPreFilterOrder` (`confirmResetPreFilterOrder()` / `cancelResetPreFilterOrder()`, reset in `resetPreFilterOrder()`).
   - Add `$confirmingRemoveAllFeedBlocks` (`confirmRemoveAllFeedBlocks()` / `cancelRemoveAllFeedBlocks()`, reset in `removeAllFeedBlocks()`).
   - Replace `wire:confirm` in `geo-blocking.blade.php` (line 69), `firewall-rules.blade.php` (line 256, keeping `.stop` modifier: `wire:click.stop="confirmResetPreFilterOrder"`), and `external-blocklists.blade.php` (line 110), rendering `<x-confirmation-modal>` in `security-manager.blade.php` using existing `admin.security_*` title/confirm keys.

- [x] **Step 4: Run targeted tests to verify they pass**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php app-modules/certificates/tests/Feature/CertificateManagerLivewireTest.php app-modules/security/tests/SecurityManagerGeoBlockingTest.php app-modules/security/tests/SecurityManagerFirewallRulesTest.php app-modules/security/tests/SecurityManagerThreatFeedTest.php`
Expected: PASS

---

### Task 6: Documentation Parity & `CHANGELOG.md` Upgrade Note

**Files:**
- Modify: `AGENTS.md:621`
- Modify: `README.md:198`
- Modify: `docs/env-reference.md:292-295,474-476,648-657`
- Modify: `docs/architecture.md:422-430`
- Modify: `docs/operations.md:291-314`
- Modify: `CHANGELOG.md`

- [x] **Step 1: Update project documentation and `CHANGELOG.md`**

1. `AGENTS.md` (line 621): Update `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`.
2. `README.md` (line 198): Update test Redis database range from `14/15` to `13–15`.
3. `docs/env-reference.md`:
   - Under `### 5.1 Redis Cluster, Connection & Resiliency` (~line 292), add `REDIS_SESSION_DB` (`integer`, default `2`, `database.redis.session.database`).
   - Under `### 9.1 Session Storage & Lifetime` (line 475), update `SESSION_CONNECTION` default from `cache` to `session`.
   - Under `### High-Throughput PBX (100–1,000+ Extensions, Call Centers)` (~line 648 / 656), include `SESSION_CONNECTION=session` and `REDIS_SESSION_DB=2`.
4. `docs/architecture.md`: Under `## 11. Real-Time Attack Neutralization & WebSocket Alert Flow` (`### 11.1 Redis Decoupling & Graceful Degradation`), document the standalone Redis 3-tier keyspace layout (`DB 0` persistent coordination/queues/locks/security, `DB 1` disposable telephony/app cache, `DB 2` HTTP sessions, `DB 13–15` isolated test workspaces).
5. `docs/operations.md`: Under `### Redis Is Down — Sessions and Cache Not Working` (line 291) and `### Redis Memory Limit & Eviction Policy` (line 308), document that sessions live in DB 2 so `optimize:clear` / `cache:clear` flushes DB 1 without signing administrators out, clarify capacity-based `volatile-lru` behavior, and note RDB snapshot persistence across graceful Redis restarts.
6. `CHANGELOG.md`: Under `## [Unreleased]`, add `### Changed` and `### Fixed` entries plus an **Upgrade Note** instructing operators on existing deployments to set `SESSION_CONNECTION=session` (and verify `SESSION_ENCRYPT=true`) in `.env` followed by `php artisan optimize:clear`, noting the one-time re-authentication upon cutover.

- [x] **Step 2: Verify no stale `SESSION_CONNECTION=cache` recommendations remain in active docs**

Run: `grep -rn "SESSION_CONNECTION=cache" AGENTS.md README.md .env.example scripts/`
Expected: Zero matches.

---

### Task 7: Mandatory Pre-Commit Verification Gate

- [x] **Step 1: Run formatting, build, syntax, and targeted test suite checks**

```bash
# 1. Validate shell syntax on modified scripts
bash -n scripts/resources/tall.sh scripts/run-cache-sweep.sh

# 2. Clear application caches and rebuild frontend assets
php artisan optimize:clear
npm run build

# 3. Verify PHP code style on modified files
./vendor/bin/pint --dirty --test

# 4. Run targeted feature and architecture tests
./vendor/bin/pest \
    tests/Feature/TestEnvironmentSafetyTest.php \
    tests/Feature/Session/RedisSessionIsolationTest.php \
    tests/Feature/InstallerDefaultsTest.php \
    tests/Feature/Livewire/GitUpdateTest.php \
    tests/Feature/Livewire/SessionExpirationModalTest.php \
    tests/Feature/Architecture/TranslationParityTest.php \
    app-modules/certificates/tests/Feature/CertificateManagerLivewireTest.php \
    app-modules/security/tests/SecurityManagerGeoBlockingTest.php \
    app-modules/security/tests/SecurityManagerFirewallRulesTest.php \
    app-modules/security/tests/SecurityManagerThreatFeedTest.php

# 5. Run targeted Playwright browser verification
bash scripts/test-browser.sh tests/Browser/SessionExpirationBrowserTest.php
```

---

## 4. Verification & Acceptance Criteria

| Requirement | Acceptance Criteria |
| :--- | :--- |
| **No Native Popups (419 & Empty 200)** | `Livewire.interceptRequest()` suppresses `window.confirm()` on both HTTP `419` and empty `200` responses; `419` opens `<x-session-expired-modal />` and cancels subsequent `wire:poll` requests, while transient empty `200` responses do not falsely expire active update polls. |
| **No Native Popups (`wire:confirm`)** | All 5 remaining `wire:confirm` directives in `certificates` and `security` use `<x-confirmation-modal>`, guarded by an automated test scanning all Blade views. |
| **Cohesive UI & Reload Recovery** | `<x-session-expired-modal />` matches `<x-confirmation-modal>` visual tokens and reloads cleanly for both CSRF-rotated active sessions (`admin`/`web` guards) and fully expired sessions. |
| **Translation Parity** | All root (`admin.*`) and module (`admin.certificates_*`) keys pass `TranslationParityTest` across `en`, `es`, and `fr` with zero collisions. |
| **Installer, Sweep & Upgrade Visibility** | `.env.example` and `scripts/resources/tall.sh` set `SESSION_CONNECTION=session`; `scripts/run-cache-sweep.sh` flushes `${REDIS_CACHE_DB:-1}` (protecting DB 0 and DB 2); `/panel/git-update` warns when legacy `.env` still uses `SESSION_CONNECTION=cache`. |
| **Cache Clear & Test Isolation** | `Cache::store('redis')->flush()` / `optimize:clear` purges DB 1 while preserving sessions in DB 2; both `phpunit.xml` and `SafeTestCommand` isolate test sessions to DB 13 (`tallpbx-test-`). |
