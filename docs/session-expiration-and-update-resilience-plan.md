# Livewire Session Expiration & Redis Isolation Implementation Plan

> [!NOTE]
> **Status: Ready for Implementation**
> This document specifies the comprehensive architecture and step-by-step implementation plan for replacing native browser session-expiration popups with a native DaisyUI/Alpine.js modal experience, while isolating Redis session storage to ensure web updates and cache sweeps never evict active administrator logins.

---

## 1. Problem Statement & Motivation

During a web UI Git update (`/panel/git-update`), two distinct issues degrade the administrative user experience:

1. **Infrastructure Issue (Session Eviction during Updates)**:
   - When the update pipeline completes, `GitUpdateService::runPipeline()` executes `php artisan optimize:clear` to ensure compiled views, routes, and configs are refreshed.
   - `optimize:clear` executes `php artisan cache:clear`.
   - In standard production configurations, `SESSION_DRIVER=redis` and `CACHE_STORE=redis`.
   - In `config/session.php`, `'connection' => env('SESSION_CONNECTION', 'cache')`, which maps to the `'cache'` connection in `config/database.php` (Redis database `1`).
   - Because volatile cache and stateful user sessions share the exact same Redis database (`1`), clearing the application cache destroys all active user sessions simultaneously.

2. **Frontend UI Issue (Unstyled Native Browser Popup)**:
   - When the administrator's session is wiped, in-flight background polling from Livewire (`wire:poll.1s="pollProgress"`) submits an AJAX request to `/livewire/update` with an invalidated session/CSRF token.
   - Laravel throws a `TokenMismatchException` and returns an HTTP `419 Page Expired` response.
   - Livewire’s core JavaScript client (`vendor/livewire/livewire/dist/livewire.csp.esm.js`) catches the HTTP 419 response and invokes its unstyled browser fallback:
     ```javascript
     confirm("This page has expired.\nWould you like to refresh the page?") && window.location.reload();
     ```
   - This native browser popup violates the project's UI standards (`AGENTS.md`), does not honor dark/light themes, cannot be localized cleanly, and, upon user confirmation (`window.location.reload()`), unceremoniously dumps the administrator onto `/login`.

---

## 2. Architecture & Design Principles

### 2.1 Frontend Strategy: Livewire Request Hook + DaisyUI Modal
Livewire exposes the `Livewire.hook('request', ...)` lifecycle hook. When a component request fails:
- The hook's `fail` callback provides a `preventDefault()` function.
- Invoking `preventDefault()` suppresses Livewire's built-in `window.confirm(...)` popup entirely.
- The hook dispatches a custom window event (`tallpbx:session-expired`).
- A persistent DaisyUI/Alpine.js modal (`<x-session-expired-modal />`) in `resources/views/layouts/app.blade.php` catches the event, displays a theme-consistent modal with clear administrative copy, and provides a direct, branded button to re-authenticate.

### 2.2 Infrastructure Strategy: Redis Database Multi-Tenancy
Redis natively supports up to 16 numbered databases (0–15). To prevent cache clear operations from evicting sessions:
- **DB 0 (`REDIS_DB`)**: Persistent coordination — Queued background jobs (`queue.connections.redis`), distributed mutex locks (`cache.stores.redis.lock_connection`), intrusion detection sliding windows (`tallpbx:security:attempts:*`), and SIP scanner deduplication keys.
- **DB 1 (`REDIS_CACHE_DB`)**: Volatile, disposable cache — Dynamic FreeSWITCH `mod_xml_curl` dialplan XML, directory XML, ACL fragments, routing invalidation tags, and route rate limiters (`RateLimiter`). Safe to wipe at any time via `FLUSHDB`.
- **DB 2 (`REDIS_SESSION_DB`)**: Stateful HTTP session records (`session.connection = 'session'`). Completely immune to `cache:clear` and `optimize:clear`.
- **DB 13 (`REDIS_SESSION_DB` in testing)**: Isolated test session database in `phpunit.xml`.
- **DB 14 & 15**: Automated test suite cache and coordination databases (`phpunit.xml`).

### 2.3 Upgrade & Cutover Lifecycle
- **One-Time Re-Authentication on Deployment**: Switching `SESSION_CONNECTION` from `'cache'` (DB 1) to `'session'` (DB 2) creates a fresh session keyspace. Any active sessions currently in DB 1 will not automatically migrate; administrators will re-authenticate once upon deploying this release. Thereafter, their sessions will persist in DB 2 and will never be cleared by subsequent updates or cache flushes.
- **Global Memory & Eviction Coexistence**: Redis applies `maxmemory 256mb` and `maxmemory-policy volatile-lru` across all databases globally. Because session keys consume ~2 KB each (< 100 KB total for an administrative team) and total Redis usage is currently ~1.12 MB, DB 1 and DB 2 coexist with zero memory contention or eviction risk.

---

## 3. Implementation Tasks

### Task 1: Redis Session Connection Isolation, Testing Configuration & Documentation Parity
**Files:**
- Modify: `config/database.php`
- Modify: `config/session.php`
- Modify: `.env.example`
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
3. In `phpunit.xml`, isolate the test session database to prevent test runners from touching production DB 2:
   ```xml
   <env name="REDIS_SESSION_DB" value="13" force="true"/>
   ```
4. In `.env.example`, document `REDIS_SESSION_DB=2` alongside `REDIS_CACHE_DB=1`.
5. In `AGENTS.md` (Performance & Load Testing section, ~line 621), update the recommended environment string from `SESSION_CONNECTION=cache` to `SESSION_CONNECTION=session`.
6. In `README.md` (Testing Isolation section, ~line 198), update the documented Redis test database range from `14/15` to `13–15`.
7. In `docs/env-reference.md`:
   - Add `REDIS_SESSION_DB` (integer, default `2`, `database.redis.session.database`) to Table 5.1 ("Redis Cluster, Connection & Resiliency").
   - Update Table 9.1 ("Session & State Management") so `SESSION_CONNECTION` reflects the default `'session'`.
   - Update the production `.env` reference template (~line 656) to include `REDIS_SESSION_DB=2`.
8. In `docs/architecture.md`, document the 3-tier keyspace architecture (DB 0: Coordination/Queues/Locks/Security, DB 1: Volatile Telephony Cache, DB 2: Web Sessions, DB 13–15: Test Suites) in Section 11 ("Real-Time Intrusion Prevention Architecture").
9. In `docs/operations.md`, add guidance in Section 7 ("Redis Memory Limit & Eviction Policy" and "Redis Is Down — Sessions and Cache Not Working") explaining that sessions are isolated in DB 2, ensuring that routine cache clearing (`php artisan optimize:clear` or `cache:clear`) purges DB 1 without evicting active administrator sessions.
10. In `CHANGELOG.md`, document the feature under `## [Unreleased]` along with an explicit **Upgrade Note** explaining the one-time re-authentication when cutting over from DB 1 to DB 2.

---

### Task 2: Livewire 419 Request Interception in JavaScript
**Files:**
- Modify: `resources/js/app.js`

#### Detailed Instructions:
1. In `resources/js/app.js`, add a listener for `livewire:init`.
2. Register a `Livewire.hook('request', ...)` listener:
   ```javascript
   document.addEventListener('livewire:init', () => {
       Livewire.hook('request', ({ fail }) => {
           fail(({ status, preventDefault }) => {
               if (status === 419) {
                   preventDefault();
                   window.dispatchEvent(new CustomEvent('tallpbx:session-expired'));
               }
           });
       });
   });
   ```
3. Verify that calling `preventDefault()` completely silences the native browser `confirm()` dialog.

---

### Task 3: Translation Dictionaries & Localization Parity
**Files:**
- Modify: `lang/en/admin.php`
- Modify: `lang/es/admin.php`
- Modify: `lang/fr/admin.php`

#### Detailed Instructions:
Add the session expiration translation keys with 100% parity across English, Spanish, and French:

**English (`lang/en/admin.php`):**
```php
'session_expired_title' => 'Session Expired',
'session_expired_message' => 'Your session has expired or the application was updated. Please sign in again to continue.',
'session_expired_action' => 'Sign In Again',
```

**Spanish (`lang/es/admin.php`):**
```php
'session_expired_title' => 'Sesión expirada',
'session_expired_message' => 'Su sesión ha expirado o la aplicación se ha actualizado. Por favor, inicie sesión nuevamente para continuar.',
'session_expired_action' => 'Iniciar sesión nuevamente',
```

**French (`lang/fr/admin.php`):**
```php
'session_expired_title' => 'Session expirée',
'session_expired_message' => 'Votre session a expiré ou l\'application a été mise à jour. Veuillez vous reconnecter pour continuer.',
'session_expired_action' => 'Se reconnecter',
```

---

### Task 4: In-App DaisyUI / Alpine Session Expiration Modal Component
**Files:**
- Create: `resources/views/components/session-expired-modal.blade.php`
- Modify: `resources/views/layouts/app.blade.php`

#### Detailed Instructions:
1. Create `resources/views/components/session-expired-modal.blade.php` using DaisyUI dialog styling matching `confirmation-modal.blade.php`:
   ```blade
   <div
       x-data="{ open: false }"
       @tallpbx:session-expired.window="open = true"
       x-cloak
   >
       <div
           x-show="open"
           class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
           role="dialog"
           aria-modal="true"
           aria-labelledby="session-expired-title"
       >
           <div class="relative w-full max-w-md rounded-box bg-base-100 p-6 text-center shadow-2xl border border-base-300">
               <x-heroicon-o-clock class="mx-auto h-12 w-12 text-warning animate-pulse" />
               <h2 id="session-expired-title" class="mt-3 text-lg font-semibold tracking-tight">
                   {{ __('admin.session_expired_title') }}
               </h2>
               <p class="mt-2 text-sm text-base-content/70">
                   {{ __('admin.session_expired_message') }}
               </p>
               <div class="mt-6 flex justify-center">
                   <a
                       href="{{ route('panel.login') }}"
                       class="btn btn-primary btn-sm px-6"
                   >
                       {{ __('admin.session_expired_action') }}
                   </a>
               </div>
           </div>
       </div>
   </div>
   ```
2. Mount `<x-session-expired-modal />` at the bottom of `resources/views/layouts/app.blade.php` right before `@livewireScripts`.

---

### Task 5: Automated Testing & Verification
**Files:**
- Create: `tests/Unit/Session/RedisSessionIsolationTest.php`
- Create: `tests/Feature/Livewire/SessionExpirationModalTest.php`
- Test: `tests/Feature/Architecture/TranslationParityTest.php`

#### Detailed Instructions:
1. **Redis Isolation Unit Test (`tests/Unit/Session/RedisSessionIsolationTest.php`)**:
   - Assert that `config('database.redis.session.database')` resolves to `2` (or the configured `REDIS_SESSION_DB`).
   - Assert that `config('session.connection')` is `'session'`.
   - Assert that `config('database.redis.session.database') !== config('database.redis.cache.database')`.
   - Assert that in the testing environment, `config('database.redis.session.database')` resolves to `13`.
2. **Session Expiration Modal Feature Test (`tests/Feature/Livewire/SessionExpirationModalTest.php`)**:
   - Assert that the modal markup is rendered on authenticated panel views.
   - Assert that the modal component contains the translated strings for `admin.session_expired_title` and `admin.session_expired_action`.
3. **Translation Parity Test**:
   - Run `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php` to verify parity across English, Spanish, and French dictionaries.

---

### Task 6: Pre-Commit Quality & Verification Gate
**Commands to Execute:**
```bash
# 1. Clear application caches and verify boot
php artisan optimize:clear

# 2. Rebuild frontend assets with Vite
npm run build

# 3. Code formatting
./vendor/bin/pint --dirty

# 4. Targeted test verification
./vendor/bin/pest tests/Unit/Session/RedisSessionIsolationTest.php
./vendor/bin/pest tests/Feature/Livewire/SessionExpirationModalTest.php
./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php

# 5. Live Redis Keyspace Verification
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
| **No Native Popups** | Livewire AJAX requests receiving an HTTP 419 never trigger `window.confirm()`. |
| **Cohesive UI** | The session expiration modal renders using DaisyUI tokens, displays an animated clock icon, matches dark/light themes, and includes a direct link to sign in. |
| **Translation Parity** | All title, body, and action copy is translated in 100% parity across `en`, `es`, and `fr`. |
| **Cache Clear Isolation** | Running `php artisan optimize:clear` or `php artisan cache:clear` clears Redis DB 1 while leaving user sessions in Redis DB 2 intact. |
| **Test Suite Isolation** | Test suites in `phpunit.xml` isolate test sessions to DB 13 (`tallpbx-test-`), preventing tests from modifying production DB 2. |
| **Update Resilience** | The web UI Git Updater completes without invalidating the active administrator's session. |
