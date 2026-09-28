# Pest 4 Browser Testing Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the entire browser testing subsystem from Laravel Dusk (ChromeDriver / W3C WebDriver) to Pest 4 Browser Testing (Playwright / AmpHP in-process server), unifying unit, feature, and browser testing under a single SQLite-backed parallel test runner and eliminating the dedicated MariaDB Dusk database and `.env` swapping risks.

**Architecture:** Install `pestphp/pest-plugin-browser` and `@playwright/test` on top of TallPBX's existing Pest 4 framework. Implement an authenticated session bridge (`/_testing/login/{guard}/{id}`) for sub-10ms browser logins, convert the four existing Dusk browser test suites to Pest's fluent `visit()` API with automatic element waiting, update the `php artisan app:test` runner, and remove the legacy Dusk infrastructure (ChromeDriver daemon, `scripts/dusk.sh`, `_dusk` MariaDB accounts, and `DuskDatabaseSafety`).

**Tech Stack:** PHP 8.5, Laravel 13.8, Pest 4.7 (`pestphp/pest-plugin-browser` v4.3.1, `amphp/http-server`), Playwright 1.40+, Node.js 24, SQLite (`:memory:`), Livewire 4, DaisyUI 5, Tailwind CSS v4.

**Spec:** [Laravel 13 Dusk Documentation](https://laravel.com/framework/docs/13.x/dusk) recommendation to use Pest 4 browser testing; TallPBX testing architecture in [`AGENTS.md`](file:///var/www/tallpbx/AGENTS.md).

---

## Progress Summary (updated 2026-09-27)

| Task | Status | Commit |
|---|---|---|
| Task 1: Install Dependencies | ✅ Complete | build: install pest-plugin-browser |
| Task 2: Auth Bridge | ✅ Complete | feat(testing): add fast session auth bridge |
| Task 3: Migrate RiskConfirmationBrowserTest | ✅ Complete | test(browser): migrate RiskConfirmationBrowserTest |
| Task 4: Migrate PanelSmokeTest | ✅ Complete | `ee7c0d0` |
| Task 5: Migrate MediaStorageBrowserTest | ✅ Complete | `fix(testing)` + `test(browser): migrate MediaStorageBrowserTest` |
| Task 6: Migrate DocumentationScreenshotsTest | ✅ Complete | `test(browser): migrate DocumentationScreenshotsTest` |
| Task 7: Runner/phpunit.xml updates | ✅ Complete | `feat(testing)` runner + `scripts/test-browser.sh` |
| Task 8: Remove legacy Dusk infrastructure | ⏳ Not started | — |
| Task 9: CI, AGENTS.md, INSTALL.md, CHANGELOG | ⏳ Not started | — |

---

## Pest 4 Browser API — Critical Findings

These patterns were discovered during Task 4 implementation. **All future test migrations must follow them.**

### 1. `GuessLocator` — Bare Tag Names Are Text Searches

`assertPresent()`, `assertMissing()`, `assertSeeIn()`, and all methods that call `guessLocator()` internally check whether the selector is "explicit" by looking for CSS special characters (`#`, `.`, `[`, `>`, `:`, `*`, etc.). A plain tag name like `'table'`, `'input'`, `'textarea'`, or `'form'` does **not** qualify — it falls through to `page->getByText(selector)` which searches for visible text, not elements.

```php
// ❌ WRONG — searches for text "table" on the page, returns 0 always
$page->assertPresent('table');

// ✅ CORRECT — '>' makes it an explicit CSS selector
$page->assertPresent('table > *');

// ✅ CORRECT — '[' makes it explicit
$page->assertPresent('input[type], input:not([type])');

// ✅ CORRECT — always use assertScript for bare tag presence
$page->assertScript('document.querySelectorAll("textarea").length > 0');
```

### 2. No Auto-Waiting — All Assertions Are Snapshot-Based

Contrary to Dusk's `waitFor`, **none** of the Pest 4 Browser assertion methods auto-wait for elements to appear. They all check the current DOM snapshot:

| Method | Behaviour | Wait? |
|---|---|---|
| `assertSee(text)` | `getByText()->all()->isVisible()` — snapshot | ❌ No |
| `assertPresent(selector)` | `locator->count()` — snapshot | ❌ No |
| `assertSeeAnythingIn(selector)` | `locator->textContent()` — **BLOCKS 30 s** if element missing | ⚠️ Blocks on missing element |
| `waitForText(text)` | Playwright `page.waitForSelector` with text | ✅ Yes |
| `wait(seconds)` | Fixed sleep | ✅ Yes (fixed) |

> **Warning:** `assertSeeAnythingIn('table')` will hang for 30 seconds if `<table>` is not present on the page. Never use it as a fallback for `assertPresent('table')`. Use `assertPresent('table > *')` instead.

### 3. Renamed/Removed Dusk Methods

| Dusk | Pest 4 Browser | Notes |
|---|---|---|
| `assertScript($expr)` → returns void | `assertScript($expr, $expected = true)` | Native assertion |
| `evaluate($expr)` | `$page->evaluate($expr)` (on Page object) | Returns mixed, use for numeric values |
| `assertAbsent($sel)` | `assertMissing($sel)` | |
| `assertInputValue($sel, $val)` | `assertValue($sel, $val)` | |
| `setViewportSize($w, $h)` | `resize($w, $h)` | |
| `screenshot($path)` | `screenshot(filename: $path)` | Named argument required |
| `waitFor($sel, $secs)` | `waitForText($text)` or `wait($secs)` | No selector-based wait |
| `assertPresent('table')` | `assertPresent('table > *')` | See §1 above |
| `assertSeeIn('thead','COLUMN')` | Use title-case — CSS `text-transform` ≠ DOM text | |

### 4. Permission Setup Convention

Browser tests that visit panel pages **must** use the standard `AdminSeeder` convention (not hand-rolled permission lists):

```php
beforeEach(function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($superAdminGroup->id);
});
```

See `AGENTS.md` § "CRITICAL — Permission Setup in Pest Tests" for rationale.

### 5. Shared Session Across Visits — Identity Switching Requires Bridge Cleanup

Every `visit()` creates a fresh browser context, but the in-process test server shares **one session** across all requests (`test()->prepareCookiesForRequest()` + a singleton session store). `loginAs()` persistence across visits depends on this. Consequences for tests that switch identities mid-test:

- The bridge must sign out the **opposite guard** and forget `selected_tenant_id`, otherwise the previous identity (or its tenant selection) leaks into the next identity's requests — an admin stays signed in, or `ScopeTenant` aborts with 403 on a tenant the new user does not belong to. `TestAuthController` now performs this cleanup.
- Multiple users **cannot be active simultaneously** in one test (no independent sessions). Serialize: `loginAs(A)` → assert as A → `loginAs(B)` → assert as B.

### 6. `<option>` Elements Are Not Visible to `assertSee()`

Text inside `<select>`/`<option>` (e.g. a destination offered in a dropdown) fails `assertSee()` because Playwright does not report `<option>` elements as visible. Verify dropdown choices through the DOM instead:

```php
$page->assertScript(<<<'JS'
    (() => {
        const select = document.querySelector('#media-archive-file-store');
        if (!select) return false;
        return [...select.options].some((option) => option.textContent.includes('Local storage - media'));
    })()
    JS);
```

### 7. `script()` Returns the Evaluated Value and Breaks Fluent Chains

`$page->script($js)` returns the evaluated JavaScript value, so a void snippet (an IIFE without `return`) yields `null` and the fluent chain breaks (`Call to a member function wait() on null`). Run void scripts as standalone statements:

```php
$page->script(<<<'JS'
    (() => { localStorage.setItem('theme', 'dark'); })()
    JS);
$page->wait(1.2)->screenshot(filename: 'landing-dark');
```

Also note when capturing files: `screenshot(bool $fullPage = true, ?string $filename = null)` requires the **named** `filename:` argument, and `Screenshot::save()` always writes into `tests/Browser/Screenshots/` (git-ignored) regardless of the name passed. Documentation captures therefore stage there and are copied into `docs/images/` with an md5 freshness check.

### 8. Killed Runs Leak Playwright Servers; Full-Suite Timings on Small Servers

- Browser runs can leave an orphaned `playwright run-server` process behind (~120 MB RSS each; the plugin starts it through an `sh -c` wrapper that survives the plugin's stop call). A stalled `app:test --full` run was traced to 12 accumulated orphans (~1.5 GB on a 4 GB server) — after cleanup the identical run completes in ~10 minutes. Before heavy suites, reclaim memory with `pkill -f "playwright run-server"`.
- Measured on the 4 GB validation server: full feature suite ≈ 3 min (4 parallel workers, 2 400 tests); full browser suite ≈ 6.2 min (sequential, 47 tests + 1 skipped); `php artisan app:test --full` ≈ 10 min total.

---

## Global Constraints

- Preserve 100% of existing test assertions and coverage across all browser test scenarios (login, dashboard, CRUD lists, CRUD edit modals, risk confirmation typed input, SFTP file stores, and documentation screenshot captures).
- Browser tests must execute against in-memory SQLite (`:memory:`) with `RefreshDatabase` and zero `.env` file swapping.
- The web panel must remain safe for simultaneous administrator use during test execution.
- Maintain compatibility with Debian Linux (Node v24.18.0, Chromium) and headless CI runners (`.github/workflows/tests.yml`).
- After any code changes, always run `php artisan optimize:clear`.

## Review Focus

1. **Authentication speed parity:** In Dusk, `loginAs()` sets the session in <10ms via an internal endpoint. If Pest browser tests have to fill the login form for all 20+ smoke tests sequentially, the suite will slow down significantly. The plan must provide an instant test-session bridge.
2. **Livewire 4 DOM morphs:** Livewire dynamic component updates must synchronize properly with Playwright assertions without resorting to arbitrary `pause()` delays.
3. **Database isolation:** Ensure browser-issued HTTP requests and the test process share the exact same SQLite in-memory database connection without schema recreation failures.
4. **Documentation screenshots fidelity:** `DUSK_CAPTURE_DOCS=1` must produce identical 1920x1080 screenshots saved to [`docs/images/`](file:///var/www/tallpbx/docs/images) without altering images when content has not changed.
5. **Multi-guard support:** Both `admin` and `web` (tenant user) guards must authenticate seamlessly via the session bridge.

---

## File Structure

### Created Files
- `tests/Browser/Concerns/InteractsWithAuthentication.php`: Shared trait or helper providing fast session creation (`loginAs()`) for browser tests.
- `scripts/test-browser.sh`: Lightweight runner script replacing `scripts/dusk.sh`.
- `tests/Browser/RiskConfirmationTest.php`: Converted Pest 4 browser test for tenant/user deletion confirmation.
- `tests/Browser/PanelSmokeTest.php`: Converted Pest 4 browser test for unified panel navigation and CRUD.
- `tests/Browser/MediaStorageTest.php`: Converted Pest 4 browser test for file stores.
- `tests/Browser/DocumentationScreenshotsTest.php`: Converted Pest 4 browser test for doc captures.

### Modified Files
- [`composer.json`](file:///var/www/tallpbx/composer.json): Require `pestphp/pest-plugin-browser:^4.3`, remove `laravel/dusk`.
- [`package.json`](file:///var/www/tallpbx/package.json): Require `playwright`.
- [`tests/Pest.php`](file:///var/www/tallpbx/tests/Pest.php): Register browser configuration and helpers.
- [`phpunit.xml`](file:///var/www/tallpbx/phpunit.xml): Add `Browser` test suite.
- [`app/Console/Commands/TestCommand.php`](file:///var/www/tallpbx/app/Console/Commands/TestCommand.php): Update `--full` to use Pest browser runner.
- [`app/Providers/AppServiceProvider.php`](file:///var/www/tallpbx/app/Providers/AppServiceProvider.php): Remove `DuskDatabaseSafety::enforce()`.
- [`scripts/resources/mariadb.sh`](file:///var/www/tallpbx/scripts/resources/mariadb.sh): Remove `_dusk` MariaDB database and user creation.
- [`scripts/resources/tall.sh`](file:///var/www/tallpbx/scripts/resources/tall.sh): Remove `.env.dusk` generation.
- [`scripts/resources/config.sh`](file:///var/www/tallpbx/scripts/resources/config.sh): Remove `chromium-driver` apt package.
- [`.github/workflows/tests.yml`](file:///var/www/tallpbx/.github/workflows/tests.yml): Add Playwright setup steps.
- [`AGENTS.md`](file:///var/www/tallpbx/AGENTS.md): Update pre-claim verification and testing guidelines.
- [`INSTALL.md`](file:///var/www/tallpbx/INSTALL.md): Update browser testing documentation.
- [`CHANGELOG.md`](file:///var/www/tallpbx/CHANGELOG.md): Record changes in `[Unreleased]`.

### Deleted Files
- `tests/DuskTestCase.php`
- `tests/Browser/Pages/Page.php`
- `tests/Browser/Pages/LoginPage.php`
- `tests/Browser/Pages/HomePage.php`
- `scripts/dusk.sh`
- `app/Support/DuskDatabaseSafety.php`
- `tests/Feature/Support/DuskDatabaseSafetyTest.php`
- `.env.dusk.example`

---

## Tasks

### Task 1: Install Dependencies & Setup Playwright

**Files:**
- Modify: [`composer.json`](file:///var/www/tallpbx/composer.json#L321-L334)
- Modify: [`package.json`](file:///var/www/tallpbx/package.json#L10-L20)
- Modify: [`.gitignore`](file:///var/www/tallpbx/.gitignore)

**Interfaces:**
- Consumes: Existing PHP 8.5 & Node v24 environment.
- Produces: `vendor/pestphp/pest-plugin-browser` package and `node_modules/playwright` installed.

- [x] **Step 1: Add `pestphp/pest-plugin-browser` to Composer**

Run:
```bash
COMPOSER_ALLOW_SUPERUSER=1 composer require pestphp/pest-plugin-browser:^4.3 --dev
```
Expected: Installs `pestphp/pest-plugin-browser` (v4.3.1) and AmpHP dependencies cleanly.

- [x] **Step 2: Add `playwright` to npm and install browser binaries**

Run:
```bash
npm install --save-dev playwright
npx playwright install chromium
```
Expected: Playwright installed, Chromium browser binaries downloaded to `~/.cache/ms-playwright`.

- [x] **Step 3: Update `.gitignore`**

Ensure `tests/Browser/screenshots` and `tests/Browser/Screenshots` are ignored in `.gitignore`:
```gitignore
tests/Browser/Screenshots/
tests/Browser/screenshots/
tests/Browser/console/
tests/Browser/source/
```

- [x] **Step 4: Verify Pest recognizes the browser plugin**

Run:
```bash
./vendor/bin/pest --version
```
Expected: Pest 4 prints version with browser plugin active.

- [x] **Step 5: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json .gitignore
git commit -m "build: install pest-plugin-browser and playwright on branch 2.0"
```

---

### Task 2: Fast Session Authentication Bridge

**Files:**
- Create: `app/Http/Controllers/Testing/TestAuthController.php`
- Modify: `routes/web.php`
- Create: `tests/Browser/Concerns/InteractsWithAuthentication.php`
- Modify: [`tests/Pest.php`](file:///var/www/tallpbx/tests/Pest.php)

**Interfaces:**
- Consumes: `App\Models\Admin`, `App\Models\User`, Laravel `auth()` guards.
- Produces: `loginAs(Admin|User $user, string $guard = 'admin')` helper for Pest browser tests that establishes a session in <10ms without submitting the UI login form.

- [x] **Step 1: Write the failing feature test for the test auth bridge** ✅
- [x] **Step 2: Run test to verify it fails** ✅
- [x] **Step 3: Implement `TestAuthController` and route** ✅

Files: `app/Http/Controllers/Testing/TestAuthController.php`, `routes/web.php`

- [x] **Step 4: Register `loginAs` helper in `tests/Pest.php`** ✅

Files: `tests/Browser/Concerns/InteractsWithAuthentication.php`, `tests/Pest.php`

- [x] **Step 5: Run test to verify it passes** ✅
- [x] **Step 6: Commit** ✅

---

### Task 3: Migrate Pilot Suite: `RiskConfirmationBrowserTest`

**Files:**
- Modify: [`tests/Browser/RiskConfirmationBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/RiskConfirmationBrowserTest.php)

**Interfaces:**
- Consumes: `Pest\Browser\Api\AwaitableWebpage`, `loginAs()`, `App\Models\Tenant`, `App\Models\User`.
- Produces: Zero Dusk dependencies in `RiskConfirmationBrowserTest.php`.

- [x] **Step 1: Refactor `RiskConfirmationBrowserTest.php` to Pest 4 Browser syntax** ✅

Updated [`tests/Browser/RiskConfirmationBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/RiskConfirmationBrowserTest.php):
```php
<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    User::where('email', 'doomed@example.com')->delete();
    Tenant::where('name', 'Typed Tenant')->delete();

    $this->admin = Admin::firstOrCreate(
        ['email' => 'admin@risk.test'],
        ['name' => 'Risk Test Admin', 'password' => bcrypt('risk-secret'), 'enabled' => true],
    );

    $group = Group::firstOrCreate(['name' => 'Risk Test Group'], ['system' => true]);

    foreach (['admin.tenants.view', 'admin.tenants.delete', 'admin.users.view', 'admin.users.delete'] as $permissionName) {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['module' => 'admin', 'description' => 'Risk permission for '.$permissionName],
        );
        $group->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $this->admin->groups()->syncWithoutDetaching([$group->id]);
});

afterEach(function () {
    $this->admin->groups()->detach();
    $this->admin->delete();
    Group::where('name', 'Risk Test Group')->delete();
});

it('requires typing the tenant name before deleting a tenant', function () {
    $tenant = Tenant::factory()->create(['name' => 'Typed Tenant']);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/tenants');
    $page->assertSee('Typed Tenant')
        ->click('button[wire\\:click="confirmTenantDeletion('.$tenant->id.')"]')
        ->assertSee('Delete Tenant?')
        ->assertSee('Type the tenant name to confirm')
        ->assertButtonDisabled('button.btn-error')
        ->fill('input[type="text"]', 'Typed Tenant')
        ->click('button.btn-error')
        ->assertSee('Tenant deleted.');

    expect(Tenant::find($tenant->id))->toBeNull();
});

it('deletes a user through the shared confirmation modal', function () {
    $user = User::factory()->create(['email' => 'doomed@example.com']);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/users');
    $page->assertSee('doomed@example.com')
        ->click('button[wire\\:click="confirmUserDeletion('.$user->id.')"]')
        ->assertSee('Delete User?')
        ->assertSee('The user loses panel access')
        ->click('button.btn-error')
        ->assertSee('User deleted.');

    expect(User::find($user->id))->toBeNull();
});
```

- [x] **Step 2: Run the migrated test** ✅ — PASS. Both browser tests pass.
- [x] **Step 3: Commit** ✅

---

### Task 4: Migrate Core Suite: `PanelSmokeTest`

**Files:**
- Modify: [`tests/Browser/PanelSmokeTest.php`](file:///var/www/tallpbx/tests/Browser/PanelSmokeTest.php)

**Interfaces:**
- Consumes: `loginAs()`, `visit()`, Livewire CRUD components.
- Produces: Full browser verification of panel routes and navigation in ~20-30 seconds.

- [x] **Step 1: Convert `PanelSmokeTest.php`** ✅

All 20+ tests converted. Key patterns applied (see **Pest 4 Browser API — Critical Findings** above):
- `loginAs()` via session bridge — no login form interaction
- `assertPresent('table > *')` not `assertPresent('table')` (bare tag = text search)
- `assertScript(expr)` for boolean JS checks (Echo, overflow, footer width)
- `assertValue()` for input value checking (not `assertInputValue()`)
- `resize(w, h)` not `setViewportSize(w, h)`
- `screenshot(filename: $path)` with named argument
- `wait(0.5)->assertScript(...)` for Alpine transition waits
- Permission setup uses `module:sync` + `AdminSeeder` + Super Administrators group

- [x] **Step 2: Run the migrated smoke suite** ✅ — Representative subset (5 key tests) passes in ~28s.
- [x] **Step 3: Commit** ✅ — `7f28427`, then API fix `ee7c0d0`

> **Note on full suite timing:** Running the entire `PanelSmokeTest.php` suite sequentially takes 4–6 minutes on a 4 GB server due to Chromium memory pressure. Run representative subsets with `--filter` during development. The full suite is appropriate for CI only.



---

### Task 5: Migrate File Stores & Media Storage Suite

**Files:**
- Modify: [`tests/Browser/MediaStorageBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/MediaStorageBrowserTest.php)

**Interfaces:**
- Consumes: `FileStore`, `MediaAsset`, SFTP and local storage forms.
- Produces: Validated storage archive selection flows in Playwright.

- [x] **Step 1: Convert `MediaStorageBrowserTest.php`** ✅

Updated [`tests/Browser/MediaStorageBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/MediaStorageBrowserTest.php) to Pest 4 browser syntax:
- `$this->browse()` callbacks replaced with sequential `$this->loginAs()` + `visit()` chains.
- The three-browser identity-switching test was serialized (shared in-process session; see Critical Findings §5).
- `$browser->waitFor(selector)` replaced with retried `assertPresent()` chains; `waitForText()` replaced with retried `assertSee()`.
- The XHR response reader now uses `$page->script()` with an IIFE (Playwright evaluates script content as an expression).
- The reserved-destination assertion uses an `assertScript` DOM check because `<option>` text is not visible to `assertSee()` (§6).
- `beforeEach` creates the reserved "Local storage - media" destination up front — on a freshly refreshed test database the component's option query runs before the destination is firstOrCreate'd, so the selector would otherwise omit it on first load.
- `TestAuthController` (Test 2's bridge) was fixed: switching identities now signs out the opposite guard and clears `selected_tenant_id` (previously leaked through the shared session, causing 403s).

- [x] **Step 2: Run the test** ✅

```bash
./vendor/bin/pest tests/Browser/MediaStorageBrowserTest.php
```

Result: PASS — 5 tests, 26 assertions (~42 s). Regression checks: `TestAuthControllerTest` 4/4, `RiskConfirmationBrowserTest` 2/2, representative `PanelSmokeTest` dashboard test — all green.

- [x] **Step 3: Commit** ✅

```bash
git add tests/Browser/MediaStorageBrowserTest.php app/Http/Controllers/Testing/TestAuthController.php tests/Feature/Testing/TestAuthControllerTest.php
git commit -m "test(browser): migrate MediaStorageBrowserTest to Pest 4 browser"
```

---

### Task 6: Migrate Documentation Screenshots Suite

**Files:**
- Modify: [`tests/Browser/DocumentationScreenshotsTest.php`](file:///var/www/tallpbx/tests/Browser/DocumentationScreenshotsTest.php)

**Interfaces:**
- Consumes: `DUSK_CAPTURE_DOCS=1` environment flag, `docs/images/` directory.
- Produces: High-resolution PNG screenshots with exact viewport sizing (1920x1080) for documentation.

- [x] **Step 1: Convert `DocumentationScreenshotsTest.php` to Pest syntax** ✅

The full 10-screenshot walkthrough was converted (not the abbreviated example below): landing light/dark themes, three dashboard layouts, tenants/devices/extensions, impersonation, and the multi-language switcher.
- Class-based Dusk test → functional Pest test with `beforeEach` seeding + `markTestSkipped` gate on `DUSK_CAPTURE_DOCS=1`.
- `beforeEach` now runs `module:sync --only-local` + `AdminSeeder` (Super Administrators convention) — the Dusk suite relied on a persistent pre-seeded `_dusk` database, which refreshed in-memory SQLite never has.
- `resize(1440, 900)` → per-visit context option `['viewport' => ['width' => 1440, 'height' => 900]]` (each `visit()` is a fresh context).
- `pause(ms)` → `wait(seconds)`; `waitForText(text, secs)` → `waitForText(text)`; `screenshot('name')` → `screenshot(filename: 'name')`; void `script([...])` snippets → standalone IIFE `script()` calls (§7).
- Freshness copy step unchanged in behavior: compares md5 against `docs/images/{name}` and copies only changed captures.

- [x] **Step 2: Run verification (dry-run without flag, then opted-in)** ✅

```bash
./vendor/bin/pest tests/Browser/DocumentationScreenshotsTest.php
# → 1 skipped (DUSK_CAPTURE_DOCS gate)

DUSK_CAPTURE_DOCS=1 ./vendor/bin/pest tests/Browser/DocumentationScreenshotsTest.php
# → PASS — 1 passed (12 assertions), ~70 s, screenshots captured
```

The opted-in run refreshed 8 of 10 panels (landing images were byte-identical — no churn), proving the capture → freshness-copy pipeline end-to-end. The refreshed images were **reverted** (not committed): they are content decisions for the maintainer to review, and the migration itself changes no UI. Re-run the opted-in command any time to regenerate them.

- [x] **Step 3: Commit** ✅

```bash
git add tests/Browser/DocumentationScreenshotsTest.php
git commit -m "test(browser): migrate DocumentationScreenshotsTest to Pest 4 browser"
```

---

### Task 7: Update Test Runner Command & Add Browser Testsuite

**Files:**
- Modify: [`app/Console/Commands/TestCommand.php`](file:///var/www/tallpbx/app/Console/Commands/TestCommand.php)
- Create: `scripts/test-browser.sh`
- [`phpunit.xml`](file:///var/www/tallpbx/phpunit.xml): intentionally unchanged (see ruling in Step 1)

**Interfaces:**
- Consumes: `php artisan app:test --full`, `bash scripts/test-browser.sh`.
- Produces: Integrated test runner that can run unit, feature, and browser tests together or separately.

- [x] **Step 1: Browser testsuite — Ruling: NOT added to phpunit.xml** ✅

Reasoning (recorded as an execution ruling): PHPUnit runs **every** `<testsuite>` in the configuration by default, so adding a `Browser` suite would (a) make `php artisan test`, the default `app:test` mode, and the CI gate execute the ~6-minute browser suite on every invocation, and (b) make `--full` run browser tests **twice** (`runPest` covers all suites, then `runBrowserTests` runs them again). The tiered runner design (fast default, `--full` = feature + browser) and AGENTS.md's separate browser step stay intact, and path-based runs need no testsuite entry: `./vendor/bin/pest tests/Browser` and `bash scripts/test-browser.sh`.
Cost if wrong: bare `--testsuite=Browser` invocations are unavailable; browser tests remain reachable by path.

- [x] **Step 2: Create `scripts/test-browser.sh`** ✅

Thin, commented runner: `exec ./vendor/bin/pest tests/Browser "$@"` — extra arguments pass through (e.g. `--filter=...`).

- [x] **Step 3: Update `TestCommand.php`** ✅

`runDusk()` replaced with `runBrowserTests()`: spawns `vendor/bin/pest tests/Browser --compact` with the shared cleaned test environment (`cleanTestingEnvironment()` keeps `.env` values from leaking into the child) and a **900 s** timeout — the plan's 300 s figure would time out the full panel smoke suite (measured browser suite: 374 s).

- [x] **Step 4: Verify** ✅

```bash
bash scripts/test-browser.sh --filter="renders the sidebar with navigation"
# → 1 passed

php artisan app:test --full
# → Step 1/2 feature suite: 2400 passed (9933 assertions), 4 workers
# → Step 2/2 browser suite: 1 skipped, 47 passed (213 assertions) in 374 s
```

The first `--full` attempt stalled past 28 minutes because 12 orphaned `playwright run-server` processes (~1.5 GB) starved the 4 GB server — after cleanup the identical run completed in ~10 minutes (see Critical Findings §8).

- [x] **Step 5: Commit** ✅

```bash
git add app/Console/Commands/TestCommand.php scripts/test-browser.sh
git commit -m "feat(testing): run browser tests via Pest in app:test and add test-browser script"
```

---

### Task 8: Cleanup Legacy Dusk Infrastructure

**Files:**
- Delete: `tests/DuskTestCase.php`
- Delete: `tests/Browser/Pages/Page.php`
- Delete: `tests/Browser/Pages/LoginPage.php`
- Delete: `tests/Browser/Pages/HomePage.php`
- Delete: `scripts/dusk.sh`
- Delete: `app/Support/DuskDatabaseSafety.php`
- Delete: `tests/Feature/Support/DuskDatabaseSafetyTest.php`
- Delete: `.env.dusk.example`
- Modify: [`app/Providers/AppServiceProvider.php`](file:///var/www/tallpbx/app/Providers/AppServiceProvider.php)
- Modify: [`scripts/resources/mariadb.sh`](file:///var/www/tallpbx/scripts/resources/mariadb.sh)
- Modify: [`scripts/resources/tall.sh`](file:///var/www/tallpbx/scripts/resources/tall.sh)
- Modify: [`scripts/resources/config.sh`](file:///var/www/tallpbx/scripts/resources/config.sh)
- Modify: [`composer.json`](file:///var/www/tallpbx/composer.json)

**Interfaces:**
- Consumes: Cleaned codebase without Dusk dependencies.
- Produces: MariaDB installer without redundant `_dusk` user/database.

- [ ] **Step 1: Remove `DuskDatabaseSafety` check from `AppServiceProvider.php`**

In [`app/Providers/AppServiceProvider.php`](file:///var/www/tallpbx/app/Providers/AppServiceProvider.php#L202-L204):
Remove lines:
```php
        if (config('app.dusk_testing')) {
            DuskDatabaseSafety::enforce();
        }
```
And remove `use App\Support\DuskDatabaseSafety;`.

- [ ] **Step 2: Remove Dusk provisioning from installer scripts**

In [`scripts/resources/mariadb.sh`](file:///var/www/tallpbx/scripts/resources/mariadb.sh#L48-L65):
Remove `CREATE DATABASE IF NOT EXISTS ${database_name}_dusk...` and `CREATE USER IF NOT EXISTS '$dusk_database_username'...`.

In [`scripts/resources/tall.sh`](file:///var/www/tallpbx/scripts/resources/tall.sh):
Remove `.env.dusk` generation block.

In [`scripts/resources/config.sh`](file:///var/www/tallpbx/scripts/resources/config.sh#L38):
Remove `chromium-driver` from apt install list (retain `chromium`).

- [ ] **Step 3: Remove `laravel/dusk` from Composer**

Run:
```bash
COMPOSER_ALLOW_SUPERUSER=1 composer remove laravel/dusk --dev
```
Expected: `laravel/dusk` and `facebook/webdriver` removed from `composer.json` and `composer.lock`.

- [ ] **Step 4: Delete legacy Dusk files**

Run:
```bash
rm -f tests/DuskTestCase.php \
      tests/Browser/Pages/Page.php \
      tests/Browser/Pages/LoginPage.php \
      tests/Browser/Pages/HomePage.php \
      scripts/dusk.sh \
      app/Support/DuskDatabaseSafety.php \
      tests/Feature/Support/DuskDatabaseSafetyTest.php \
      .env.dusk.example \
      .env.dusk
```

- [ ] **Step 5: Run full test suite verification**

Run:
```bash
php artisan optimize:clear
php artisan test --compact --parallel
./vendor/bin/pest tests/Browser
```
Expected: 100% of unit, feature, and browser tests pass.

- [ ] **Step 6: Commit**

```bash
git add -u
git commit -m "refactor(testing): remove legacy laravel/dusk and dedicated dusk database infrastructure"
```

---

### Task 9: Update Documentation, Agent Rules, and CI Workflow

**Files:**
- Modify: [`.github/workflows/tests.yml`](file:///var/www/tallpbx/.github/workflows/tests.yml)
- Modify: [`AGENTS.md`](file:///var/www/tallpbx/AGENTS.md)
- Modify: [`INSTALL.md`](file:///var/www/tallpbx/INSTALL.md)
- Modify: [`CHANGELOG.md`](file:///var/www/tallpbx/CHANGELOG.md)

**Interfaces:**
- Consumes: Finalized Pest 4 browser testing workflow.
- Produces: Updated documentation, CI workflow, and agent instructions.

- [ ] **Step 1: Update GitHub Actions workflow**

In [`.github/workflows/tests.yml`](file:///var/www/tallpbx/.github/workflows/tests.yml):
Add Node.js setup and Playwright installation:
```yaml
      - name: Set up Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '24'

      - name: Install npm dependencies
        run: npm ci

      - name: Install Playwright Browsers
        run: npx playwright install --with-deps chromium
```

- [ ] **Step 2: Update `AGENTS.md`**

Replace:
- `php artisan dusk` in the pre-claim checklist with:
  ```bash
  # 3. Pest browser tests must pass (for UI changes)
  ./vendor/bin/pest tests/Browser
  ```
- Replace the "Dusk Database Isolation" section with "Playwright Browser Testing" documenting the in-memory SQLite shared model and `scripts/test-browser.sh`.

- [ ] **Step 3: Update `INSTALL.md`**

Update section 5 ("Browser Testing") to describe Pest 4 browser testing with Playwright and remove warnings about `.env` swapping.

- [ ] **Step 4: Update `CHANGELOG.md`**

Add under `## [Unreleased]`:
```markdown
### Added
- Pest 4 native browser testing powered by Playwright (`pestphp/pest-plugin-browser`).
- Fast test authentication bridge (`/_testing/login/{guard}/{id}`) for sub-10ms browser test logins.

### Changed
- Converted all browser test suites (`PanelSmokeTest`, `RiskConfirmationBrowserTest`, `MediaStorageBrowserTest`, `DocumentationScreenshotsTest`) to Pest 4 fluent browser syntax.
- Updated `php artisan app:test --full` to execute browser tests directly via Pest.

### Removed
- Removed `laravel/dusk` and `facebook/webdriver` dependencies.
- Deprecated `scripts/dusk.sh`, `App\Support\DuskDatabaseSafety`, and `.env.dusk`.
- Removed dedicated `_dusk` MariaDB database and user creation from installer scripts.
```

- [ ] **Step 5: Run optimization and full suite verification**

Run:
```bash
php artisan optimize:clear
php artisan test --compact --parallel
bash scripts/test-browser.sh
```
Expected: Clean pass with zero errors.

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/tests.yml AGENTS.md INSTALL.md CHANGELOG.md
git commit -m "docs(testing): update documentation and CI for Pest 4 browser testing"
```

---

## Execution Handoff

Plan complete and saved to [`docs/pest-browser-migration-plan.md`](file:///var/www/tallpbx/docs/pest-browser-migration-plan.md). Please review the plan. Which execution approach would you prefer?

- **Subagent-driven** - A fresh subagent implements each task and a fresh reviewer checks it before the next one starts, then a whole-branch review at the end. Most thorough; costs a fresh context per task and per review.
- **Native** - I implement every task myself in this session, the way this harness runs work, then one fresh reviewer on the most capable model checks the whole branch. Cheapest and fastest; no independent review until the end. Runs well with a mid-tier session model, since the plan carries the design.

**For this plan I recommend Native**, because the tasks build directly on each other's test infrastructure, share a single environment with Playwright and Composer, and can be verified step-by-step with immediate feedback in this environment. Does the plan capture what you want, and which approach should we use?
