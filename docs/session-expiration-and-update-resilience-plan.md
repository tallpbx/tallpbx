# Livewire Session Expiration & Redis Isolation Implementation Plan

> [!NOTE]
> **Status: Ready for Implementation**
> This document specifies the comprehensive architecture and step-by-step implementation plan for replacing all native browser `window.confirm()` popups (both Livewire's HTTP 419 fallback and remaining `wire:confirm` directives) with cohesive DaisyUI/Alpine.js modals, while isolating Redis session storage to ensure web updates and cache sweeps never evict active administrator logins.

---

## 1. Problem Statement & Motivation

During a web UI Git update (`/panel/git-update`) and across destructive panel actions, two distinct issues degrade the administrative user experience:

1. **Infrastructure Issue (Session Eviction during Updates)**:
   - When the update pipeline completes, `GitUpdateService::runPipeline()` executes `php artisan optimize:clear` to ensure compiled views, routes, and configs are refreshed.
   - `optimize:clear` executes `php artisan cache:clear`.
   - In standard production configurations (`.env`, `.env.example`, and `scripts/resources/tall.sh`), `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, and `SESSION_CONNECTION=cache`.
   - In `config/session.php`, `'connection' => env('SESSION_CONNECTION', 'cache')`, which maps to the `'cache'` connection in `config/database.php` (Redis database `1`).
   - Because volatile cache and stateful user sessions share the exact same Redis database (`1`), clearing the application cache via `FLUSHDB` destroys all active user sessions simultaneously.

2. **Frontend UI Issue (Unstyled Native Browser Popups)**:
   - **HTTP 419 Session Expiration Popup**: When the administrator's session is wiped, in-flight background polling from Livewire (`wire:poll.1s="pollProgress"`) submits an AJAX request to `/livewire/update` with an invalidated session/CSRF token. Laravel throws a `TokenMismatchException` and returns an HTTP `419 Page Expired` response. Livewire’s core JavaScript client (`vendor/livewire/livewire/dist/livewire.csp.esm.js`) catches the HTTP 419 response and invokes its unstyled browser fallback:
     ```javascript
     confirm("This page has expired.\nWould you like to refresh the page?") && window.location.reload();
     ```
   - **Remaining `wire:confirm` Directives**: Five buttons across the `certificates` and `security` modules still use `wire:confirm="..."`, which calls `window.confirm()` in Livewire's `js/directives/wire-confirm.js` instead of the standardized `<x-confirmation-modal>` component used everywhere else in TallPBX.
   - Native browser popups violate the project's UI standards (`AGENTS.md`), ignore dark/light themes, and break visual consistency across the administrative panel.

---

## 2. Architecture & Design Principles

### 2.1 Frontend Strategy: Livewire 4 `interceptRequest` + DaisyUI Modals
1. **Livewire 4 Canonical Request Interceptor**:
   - Per Livewire 4 documentation and `.agents/skills/livewire-development/SKILL.md`, use `Livewire.interceptRequest(({ request, onError }) => ...)` rather than the legacy v3 `Livewire.hook('request')` compatibility wrapper.
   - In `onError(({ response, preventDefault }) => ...)`, intercept `response.status === 419` and call `preventDefault()` to suppress Livewire's built-in `window.confirm(...)` popup.
   - **Polling Storm Prevention**: Because calling `preventDefault()` bypasses Livewire's internal `sessionExpired` stop flag, pages with active `wire:poll` timers (such as `/panel/git-update` polling every 1s) would otherwise continue firing doomed `419` requests every second while the modal is open. Track an `isSessionExpired` boolean in JS and call `request.cancel()` on any subsequent requests once the modal has been triggered.
2. **Peer-Aligned Session Expiration Modal (`<x-session-expired-modal />`)**:
   - Mount a persistent Alpine.js/DaisyUI modal in `resources/views/layouts/app.blade.php` that listens for `tallpbx:session-expired`.
   - Match the exact visual tokens of the established peer component `resources/views/components/confirmation-modal.blade.php` (`bg-black/50` overlay, static `h-12 w-12 text-warning` icon, `mt-2 text-lg font-semibold` heading, `mt-3 text-sm text-base-content/70` body copy).
   - Use `window.location.reload()` for the primary action button rather than hardcoding `route('panel.login')`. Because `layouts/app.blade.php` is shared by both administrators (`/panel/login`) and tenant users (`/panel/login/tenant`), reloading lets `AuthPanelMiddleware` route unauthenticated sessions cleanly while keeping users whose CSRF token merely rotated in another browser tab on their current page.
3. **Standardizing Remaining `wire:confirm` Actions onto `<x-confirmation-modal>`**:
   - Replace the 5 remaining `wire:confirm` attributes in `app-modules/certificates` and `app-modules/security` with server-state-driven `<x-confirmation-modal>` dialogs, achieving 100% popup-free parity across all modules.

### 2.2 Infrastructure Strategy: Redis Database Multi-Tenancy
Redis natively supports up to 16 numbered databases (0–15). To prevent cache clear operations from evicting sessions:
- **DB 0 (`REDIS_DB`)**: Persistent coordination — Queued background jobs (`queue.connections.redis`), distributed mutex locks (`cache.stores.redis.lock_connection`), intrusion detection sliding windows (`tallpbx:security:attempts:*`), and SIP scanner deduplication keys.
- **DB 1 (`REDIS_CACHE_DB`)**: Volatile, disposable cache — Dynamic FreeSWITCH `mod_xml_curl` dialplan XML, directory XML, ACL fragments, routing invalidation tags, and route rate limiters (`RateLimiter`). Safe to wipe at any time via `FLUSHDB`.
- **DB 2 (`REDIS_SESSION_DB`)**: Stateful HTTP session records (`session.connection = 'session'`). Completely immune to `cache:clear` and `optimize:clear`.
- **DB 13 (`REDIS_SESSION_DB` in testing)**: Isolated test session database in `phpunit.xml`.
- **DB 14 & 15**: Automated test suite cache and coordination databases (`phpunit.xml`).

### 2.3 Upgrade & Cutover Lifecycle
- **Installer, Template & Existing `.env` Synchronization**:
  - Both `.env.example` (line 34) and `scripts/resources/tall.sh` (lines 236–238) currently hardcode `SESSION_CONNECTION=cache`. Both must be updated to `SESSION_CONNECTION=session` so fresh installations and installer re-runs use the isolated session connection.
  - Because `.env` is untracked by Git and existing servers already have `SESSION_CONNECTION=cache` in `.env`, the local `.env` must be updated to `SESSION_CONNECTION=session` (and documented prominently in the `CHANGELOG.md` Upgrade Note).
- **One-Time Re-Authentication on Deployment**: Switching `SESSION_CONNECTION` from `'cache'` (DB 1) to `'session'` (DB 2) creates a fresh session keyspace. Any active sessions currently in DB 1 will not automatically migrate; administrators will re-authenticate once upon deploying this change. Thereafter, their sessions will persist in DB 2 and will never be cleared by subsequent updates or cache flushes.
- **Global Memory & Eviction Coexistence**: Redis applies `maxmemory 256mb` and `maxmemory-policy volatile-lru` across all databases globally. Because session keys consume ~2 KB each (< 100 KB total for an administrative team) and total Redis usage is currently ~1.12 MB, DB 1 and DB 2 coexist with zero memory contention or eviction risk.

---

## 3. Implementation Tasks

### Task 1: Redis Session Connection Isolation, Installer Script, Testing Configuration & Documentation Parity
**Files:**
- Modify: `config/database.php`
- Modify: `config/session.php`
- Modify: `.env.example`
- Modify: `scripts/resources/tall.sh`
- Modify: `phpunit.xml`
- Modify: `AGENTS.md`
- Modify: `README.md`
- Modify: `docs/env-reference.md`
- Modify: `docs/architecture.md`
- Modify: `docs/operations.md`
- Modify: `CHANGELOG.md`

#### Detailed Instructions:
1. In `config/database.php`, add a dedicated `'session'` connection to the `'redis'` configuration array:
   ```php
   'session' => [
       'url' => env('REDIS_URL'),
       'host' => env('REDIS_HOST', '127.0.0.1'),
       'username' => env('REDIS_USERNAME'),
       'password' => env('REDIS_PASSWORD'),
       'port' => env('REDIS_PORT', '6379'),
       'database' => env('REDIS_SESSION_DB', '2'),
       'max_retries' => env('REDIS_MAX_RETRIES', 3),
       'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
       'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
       'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
   ],
   ```
2. In `config/session.php`, update the default connection to point to `'session'`:
   ```php
   'connection' => env('SESSION_CONNECTION', 'session'),
   ```
3. In `.env.example`:
   - Update line 34 from `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`.
   - Under `REDIS_PORT=6379` (~line 69), add `REDIS_DB=0`, `REDIS_CACHE_DB=1`, and `REDIS_SESSION_DB=2`.
4. In `scripts/resources/tall.sh` (lines 236–238), update the installer's `.env` provisioning from `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`:
   ```bash
   grep -q "^SESSION_CONNECTION=" .env 2>/dev/null \
       && sed -i "s/^SESSION_CONNECTION=.*/SESSION_CONNECTION=session/" .env \
       || echo "SESSION_CONNECTION=session" >> .env
   ```
5. In `phpunit.xml`, force both `SESSION_CONNECTION` and the isolated test session database (`13`) so local `.env` values cannot leak into tests and test runners can never touch production DB 2:
   ```xml
   <env name="REDIS_SESSION_DB" value="13" force="true"/>
   <env name="SESSION_CONNECTION" value="session" force="true"/>
   ```
6. Update the local server `.env` (`SESSION_CONNECTION=session` and `REDIS_SESSION_DB=2`).
7. In `AGENTS.md` (Performance & Load Testing section, ~line 621), update the recommended environment string from `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`.
8. In `README.md` (Testing Isolation section, ~line 198), update the documented Redis test database range from `14/15` to `13–15`.
9. In `docs/env-reference.md`:
   - Add `REDIS_SESSION_DB` (integer, default `2`, `database.redis.session.database`) to Table 5.1 ("Redis Cluster, Connection & Resiliency").
   - Update Table 9.1 ("Session & State Management") so `SESSION_CONNECTION` reflects the default `'session'`.
   - Update the production `.env` reference template (~line 656) to include `REDIS_SESSION_DB=2`.
10. In `docs/architecture.md`, document the 3-tier keyspace architecture (DB 0: Coordination/Queues/Locks/Security, DB 1: Volatile Telephony Cache, DB 2: Web Sessions, DB 13–15: Test Suites) in Section 11 ("Real-Time Intrusion Prevention Architecture").
11. In `docs/operations.md`, add guidance in Section 7 ("Redis Memory Limit & Eviction Policy" and "Redis Is Down — Sessions and Cache Not Working") explaining that sessions are isolated in DB 2, ensuring that routine cache clearing (`php artisan optimize:clear` or `cache:clear`) purges DB 1 without evicting active administrator sessions.
12. In `CHANGELOG.md`, document the changes under `## [Unreleased]` along with an explicit **Upgrade Note** instructing administrators on existing installs to update `SESSION_CONNECTION=session` in `.env` and noting the one-time re-authentication when cutting over from DB 1 to DB 2.

---

### Task 2: Livewire 4 `interceptRequest` 419 Handler & Polling Storm Guard
**Files:**
- Modify: `resources/js/app.js`

#### Detailed Instructions:
1. In `resources/js/app.js`, add a `livewire:init` listener using Livewire 4's canonical `Livewire.interceptRequest()` API.
2. Track `isSessionExpired` so that once an HTTP `419` response is intercepted:
   - `preventDefault()` suppresses Livewire's native `window.confirm()` dialog.
   - `tallpbx:session-expired` is dispatched once to open the DaisyUI modal.
   - Any subsequent background `wire:poll` requests are immediately cancelled client-side via `request.cancel()`:
   ```javascript
   let isSessionExpired = false;

   document.addEventListener('livewire:init', () => {
       Livewire.interceptRequest(({ request, onError }) => {
           if (isSessionExpired) {
               request.cancel();
               return;
           }

           onError(({ response, preventDefault }) => {
               if (response.status === 419) {
                   preventDefault();
                   isSessionExpired = true;
                   window.dispatchEvent(new CustomEvent('tallpbx:session-expired'));
               }
           });
       });
   });
   ```
3. Reset `isSessionExpired = false` on `livewire:navigated` in case SPA navigation occurs after session restoration in another tab.

---

### Task 3: Translation Dictionaries & Localization Parity
**Files:**
- Modify: `lang/en/admin.php`
- Modify: `lang/es/admin.php`
- Modify: `lang/fr/admin.php`
- Modify: `app-modules/certificates/lang/en/admin.php` (and `es`, `fr` if present, or root `lang/*/admin.php`)

#### Detailed Instructions:
Add the session expiration and confirmation modal translation keys with 100% parity across English, Spanish, and French:

**English (`lang/en/admin.php`):**
```php
'session_expired_title' => 'Session Expired',
'session_expired_message' => 'Your session has expired. Please refresh the page or sign in again to continue.',
'session_expired_action' => 'Continue',
```

**Spanish (`lang/es/admin.php`):**
```php
'session_expired_title' => 'Sesión expirada',
'session_expired_message' => 'Su sesión ha expirado. Por favor, actualice la página o inicie sesión nuevamente para continuar.',
'session_expired_action' => 'Continuar',
```

**French (`lang/fr/admin.php`):**
```php
'session_expired_title' => 'Session expirée',
'session_expired_message' => 'Votre session a expiré. Veuillez actualiser la page ou vous reconnecter pour continuer.',
'session_expired_action' => 'Continuer',
```

---

### Task 4: In-App DaisyUI / Alpine Session Expiration Modal Component
**Files:**
- Create: `resources/views/components/session-expired-modal.blade.php`
- Modify: `resources/views/layouts/app.blade.php`

#### Detailed Instructions:
1. Create `resources/views/components/session-expired-modal.blade.php` matching the established visual conventions of `resources/views/components/confirmation-modal.blade.php`:
   ```blade
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
                       class="btn btn-primary btn-sm px-6"
                   >
                       {{ __('admin.session_expired_action') }}
                   </button>
               </div>
           </div>
       </div>
   </div>
   ```
2. Mount `<x-session-expired-modal />` at the bottom of `resources/views/layouts/app.blade.php` right before `@livewireScripts`.

---

### Task 5: Replace Remaining `wire:confirm` Browser Popups with `<x-confirmation-modal>`
**Files:**
- Modify: `app-modules/certificates/src/Livewire/CertificateManager.php`
- Modify: `app-modules/certificates/resources/views/certificate-manager.blade.php`
- Modify: `app-modules/security/src/Livewire/SecurityManager.php` (and applicable traits under `app-modules/security/src/Livewire/Concerns/`)
- Modify: `app-modules/security/resources/views/tabs/geo-blocking.blade.php`
- Modify: `app-modules/security/resources/views/tabs/firewall-rules.blade.php`
- Modify: `app-modules/security/resources/views/tabs/external-blocklists.blade.php`
- Modify: `app-modules/security/resources/views/security-manager.blade.php`

#### Detailed Instructions:
Replace all 5 remaining `wire:confirm` occurrences so no Blade template in the repository invokes Livewire's native `window.confirm()`:
1. **Certificates Module (`certificate-manager.blade.php`)**:
   - **Line 265 (`deleteCertificate`)**: Replace `wire:click="deleteCertificate({{ $cert->id }})" wire:confirm="..."` with `wire:click="confirmDeleteCertificate({{ $cert->id }})"`, backed by `$pendingDeleteCertificateId` / `$pendingDeleteCertificateName` and `<x-confirmation-modal>`.
   - **Line 591 (`deleteDnsCredential`)**: Replace `wire:click="deleteDnsCredential({{ $cred->id }})" wire:confirm="..."` with `wire:click="confirmDeleteDnsCredential({{ $cred->id }})"`, backed by `$pendingDeleteCredentialId` / `$pendingDeleteCredentialName` and `<x-confirmation-modal>`.
2. **Security Module (`geo-blocking.blade.php`, `firewall-rules.blade.php`, `external-blocklists.blade.php`)**:
   - **`geo-blocking.blade.php` (Line 69 — `clearGeoRules`)**: Replace `wire:confirm="{{ __('admin.security_geo_clear_confirm') }}"` with `wire:click="confirmClearGeoRules"` opening `<x-confirmation-modal>` (`confirm-action="clearGeoRules"`, `cancel-action="cancelClearGeoRules"`).
   - **`firewall-rules.blade.php` (Line 256 — `resetPreFilterOrder`)**: Replace `wire:confirm="{{ __('admin.security_prefilter_reset_confirm') }}"` with `wire:click.stop="confirmResetPreFilterOrder"` opening `<x-confirmation-modal>` (`confirm-action="resetPreFilterOrder"`, `cancel-action="cancelResetPreFilterOrder"`).
   - **`external-blocklists.blade.php` (Line 110 — `removeAllFeedBlocks`)**: Replace `wire:confirm="{{ __('admin.security_threat_feed_remove_blocks_confirm') }}"` with `wire:click="confirmRemoveAllFeedBlocks"` opening `<x-confirmation-modal>` (`confirm-action="removeAllFeedBlocks"`, `cancel-action="cancelRemoveAllFeedBlocks"`).

---

### Task 6: Automated Testing & Verification
**Files:**
- Create: `tests/Unit/Session/RedisSessionIsolationTest.php`
- Modify: `tests/Feature/InstallerDefaultsTest.php`
- Create: `tests/Feature/Livewire/SessionExpirationModalTest.php`
- Modify: `app-modules/certificates/tests/Feature/CertificateManagerTest.php` (or corresponding module test)
- Modify: `app-modules/security/tests/SecurityManagerLivewireTest.php` (and `SecurityManagerGeoBlockingTest.php`)
- Test: `tests/Feature/Architecture/TranslationParityTest.php`

#### Detailed Instructions:
1. **Redis Isolation Unit Test (`tests/Unit/Session/RedisSessionIsolationTest.php`)**:
   - Assert that `config('session.connection')` is `'session'`.
   - Assert that in the active test environment (`phpunit.xml`), `config('database.redis.session.database')` resolves to `'13'`.
   - Assert that evaluating `config/database.php` with `REDIS_SESSION_DB` unset resolves `redis.session.database` to the production default `'2'`.
   - Assert that `config('database.redis.session.database') !== config('database.redis.cache.database')`.
2. **Installer & Environment Template Test (`tests/Feature/InstallerDefaultsTest.php`)**:
   - Assert that `.env.example` contains `SESSION_CONNECTION=session` and `REDIS_SESSION_DB=2`.
   - Assert that `scripts/resources/tall.sh` sets `SESSION_CONNECTION=session` (and never `SESSION_CONNECTION=cache`).
3. **Session Expiration Modal & Popup-Free Architecture Test (`tests/Feature/Livewire/SessionExpirationModalTest.php`)**:
   - Assert that `<x-session-expired-modal />` markup and translated strings (`admin.session_expired_title`, `admin.session_expired_message`, `admin.session_expired_action`) render on authenticated panel pages.
   - Assert that zero Blade templates across `resources/views/` and `app-modules/` contain `wire:confirm`.
4. **Module Confirmation Modal Tests**:
   - Verify `confirmDeleteCertificate`, `confirmDeleteDnsCredential`, `confirmClearGeoRules`, `confirmResetPreFilterOrder`, and `confirmRemoveAllFeedBlocks` open and close their confirmation state cleanly before executing.
5. **Translation Parity Test**:
   - Run `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php` to verify parity across English, Spanish, and French dictionaries.

---

### Task 7: Pre-Commit Quality & Verification Gate
**Commands to Execute:**
```bash
# 1. Validate shell syntax on modified installer resource script
bash -n scripts/resources/tall.sh

# 2. Clear application caches and verify boot
php artisan optimize:clear

# 3. Rebuild frontend assets with Vite
npm run build

# 4. Code formatting
./vendor/bin/pint --dirty

# 5. Targeted test verification
./vendor/bin/pest tests/Unit/Session/RedisSessionIsolationTest.php
./vendor/bin/pest tests/Feature/InstallerDefaultsTest.php
./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php
./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php

# 6. Live Redis Keyspace Verification
# Verify session stored in DB 2 and cache stored in DB 1:
redis-cli -n 1 keys "*"
redis-cli -n 2 keys "*"
php artisan cache:clear
# Confirm DB 1 is cleared and DB 2 retains its keys
```

---

## 4. Verification & Acceptance Criteria

| Requirement | Acceptance Criteria |
| :--- | :--- |
| **No Native Popups (419)** | Livewire AJAX requests receiving an HTTP 419 use `Livewire.interceptRequest()` to suppress `window.confirm()` and cancel subsequent `wire:poll` requests. |
| **No Native Popups (`wire:confirm`)** | All 5 remaining `wire:confirm` directives in `certificates` and `security` are replaced with `<x-confirmation-modal>`, enforced by an automated test asserting zero `wire:confirm` usages remain. |
| **Cohesive UI & Guard Support** | `<x-session-expired-modal />` matches `<x-confirmation-modal>` styling tokens and triggers `window.location.reload()` so both admin and tenant guards route properly. |
| **Translation Parity** | All title, body, and action copy is translated in 100% parity across `en`, `es`, and `fr`. |
| **Installer & `.env` Parity** | `.env.example`, `scripts/resources/tall.sh`, and `config/session.php` all set `SESSION_CONNECTION=session`. |
| **Cache Clear Isolation** | Running `php artisan optimize:clear` or `php artisan cache:clear` clears Redis DB 1 while leaving user sessions in Redis DB 2 intact. |
| **Test Suite Isolation** | `phpunit.xml` forces `SESSION_CONNECTION=session` and `REDIS_SESSION_DB=13` (`tallpbx-test-`), preventing tests from modifying production DB 2. |
| **Update Resilience** | The web UI Git Updater completes without invalidating the active administrator's session. |
