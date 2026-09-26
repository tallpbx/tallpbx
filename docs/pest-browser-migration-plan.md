# Pest 4 Browser Testing Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the entire browser testing subsystem from Laravel Dusk (ChromeDriver / W3C WebDriver) to Pest 4 Browser Testing (Playwright / AmpHP in-process server), unifying unit, feature, and browser testing under a single SQLite-backed parallel test runner and eliminating the dedicated MariaDB Dusk database and `.env` swapping risks.

**Architecture:** Install `pestphp/pest-plugin-browser` and `@playwright/test` on top of TallPBX's existing Pest 4 framework. Implement an authenticated session bridge (`/_testing/login/{guard}/{id}`) for sub-10ms browser logins, convert the four existing Dusk browser test suites to Pest's fluent `visit()` API with automatic element waiting, update the `php artisan app:test` runner, and remove the legacy Dusk infrastructure (ChromeDriver daemon, `scripts/dusk.sh`, `_dusk` MariaDB accounts, and `DuskDatabaseSafety`).

**Tech Stack:** PHP 8.5, Laravel 13.8, Pest 4.7 (`pestphp/pest-plugin-browser` v4.3.1, `amphp/http-server`), Playwright 1.40+, Node.js 24, SQLite (`:memory:`), Livewire 4, DaisyUI 5, Tailwind CSS v4.

**Spec:** [Laravel 13 Dusk Documentation](https://laravel.com/framework/docs/13.x/dusk) recommendation to use Pest 4 browser testing; TallPBX testing architecture in [`AGENTS.md`](file:///var/www/tallpbx/AGENTS.md).

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

- [ ] **Step 1: Write the failing feature test for the test auth bridge**

Create `tests/Feature/Testing/TestAuthControllerTest.php`:
```php
<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;

it('authenticates admin via test bridge when environment is testing', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/admin/{$admin->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($admin, 'admin');
});

it('authenticates tenant user via test bridge when environment is testing', function () {
    $user = User::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/web/{$user->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($user, 'web');
});

it('returns 404 if environment is not testing', function () {
    app()->detectEnvironment(fn () => 'production');

    $response = $this->get("/_testing/login/admin/1");

    $response->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=TestAuthControllerTest
```
Expected: FAIL (route `/_testing/login` not defined).

- [ ] **Step 3: Implement `TestAuthController` and route**

Create `app/Http/Controllers/Testing/TestAuthController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class TestAuthController extends Controller
{
    /**
     * Fast test authentication endpoint for browser and automated testing.
     * Only available when app()->environment('testing').
     */
    public function login(string $guard, string $id): RedirectResponse
    {
        if (! app()->environment('testing')) {
            abort(404);
        }

        $user = match ($guard) {
            'admin' => Admin::query()->findOrFail((int) $id),
            'web' => User::query()->findOrFail((int) $id),
            default => abort(400, 'Invalid guard'),
        };

        Auth::guard($guard)->login($user);
        request()->session()->regenerate();
        request()->session()->save();

        return redirect('/panel');
    }
}
```

Register in `routes/web.php` (conditionally guarded):
```php
if (app()->environment('testing')) {
    Route::get('/_testing/login/{guard}/{id}', [App\Http\Controllers\Testing\TestAuthController::class, 'login'])
        ->middleware(['web']);
}
```

- [ ] **Step 4: Register `loginAs` helper in `tests/Pest.php`**

Create `tests/Browser/Concerns/InteractsWithAuthentication.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use Illuminate\Database\Eloquent\Model;

trait InteractsWithAuthentication
{
    /**
     * Authenticate a user directly into the browser session via the test bridge.
     */
    public function loginAs(Model $user, string $guard = 'admin'): void
    {
        visit("/_testing/login/{$guard}/{$user->getKey()}");
    }
}
```

In [`tests/Pest.php`](file:///var/www/tallpbx/tests/Pest.php), bind trait to `Browser` suite:
```php
pest()->use(Tests\Browser\Concerns\InteractsWithAuthentication::class)
    ->in('Browser');
```

- [ ] **Step 5: Run test to verify it passes**

Run:
```bash
php artisan test --filter=TestAuthControllerTest
```
Expected: PASS with 3 tests passing.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Testing/TestAuthController.php routes/web.php tests/Browser/Concerns/InteractsWithAuthentication.php tests/Feature/Testing/TestAuthControllerTest.php tests/Pest.php
git commit -m "feat(testing): add fast session authentication bridge for browser tests"
```

---

### Task 3: Migrate Pilot Suite: `RiskConfirmationBrowserTest`

**Files:**
- Modify: [`tests/Browser/RiskConfirmationBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/RiskConfirmationBrowserTest.php)

**Interfaces:**
- Consumes: `Pest\Browser\Api\AwaitableWebpage`, `loginAs()`, `App\Models\Tenant`, `App\Models\User`.
- Produces: Zero Dusk dependencies in `RiskConfirmationBrowserTest.php`.

- [ ] **Step 1: Refactor `RiskConfirmationBrowserTest.php` to Pest 4 Browser syntax**

Update [`tests/Browser/RiskConfirmationBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/RiskConfirmationBrowserTest.php):
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

- [ ] **Step 2: Run the migrated test**

Run:
```bash
./vendor/bin/pest tests/Browser/RiskConfirmationBrowserTest.php
```
Expected: PASS. Both browser tests execute and assert against SQLite memory state.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/RiskConfirmationBrowserTest.php
git commit -m "test(browser): migrate RiskConfirmationBrowserTest to Pest 4 browser"
```

---

### Task 4: Migrate Core Suite: `PanelSmokeTest`

**Files:**
- Modify: [`tests/Browser/PanelSmokeTest.php`](file:///var/www/tallpbx/tests/Browser/PanelSmokeTest.php)

**Interfaces:**
- Consumes: `loginAs()`, `visit()`, Livewire CRUD components.
- Produces: Full browser verification of panel routes and navigation in ~20-30 seconds.

- [ ] **Step 1: Convert `PanelSmokeTest.php`**

Replace `$this->browse(function (Browser $browser) { ... })` across all tests:
1. `it('displays the admin login page')`:
   ```php
   it('displays the admin login page', function () {
       $page = visit('/panel/login');
       $page->assertSee('TallPBX')
           ->assertPresent('input[name="email"]')
           ->assertPresent('input[name="password"]')
           ->assertPresent('button[type="submit"]');
   });
   ```
2. `it('rejects invalid credentials with error message')`:
   ```php
   it('rejects invalid credentials with error message', function () {
       $page = visit('/panel/login');
       $page->fill('email', 'wrong@example.com')
           ->fill('password', 'badpassword')
           ->click('button[type="submit"]')
           ->assertPathIs('/panel/login')
           ->assertSee('These credentials do not match our records.');
   });
   ```
3. `it('logs in an admin through the login form')`:
   ```php
   it('logs in an admin through the login form', function () {
       $page = visit('/panel/login');
       $page->fill('email', 'admin@smoke.test')
           ->fill('password', 'smoke-secret')
           ->click('button[type="submit"]')
           ->assertPathIs('/panel')
           ->assertSee('System Status');
   });
   ```
4. Convert remaining 17 tests to use `$this->loginAs($this->admin, 'admin')` followed by `$page = visit('/panel/...')` and element assertions.

- [ ] **Step 2: Run the migrated smoke suite**

Run:
```bash
./vendor/bin/pest tests/Browser/PanelSmokeTest.php
```
Expected: PASS with all tests passing.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/PanelSmokeTest.php
git commit -m "test(browser): migrate PanelSmokeTest to Pest 4 browser"
```

---

### Task 5: Migrate File Stores & Media Storage Suite

**Files:**
- Modify: [`tests/Browser/MediaStorageBrowserTest.php`](file:///var/www/tallpbx/tests/Browser/MediaStorageBrowserTest.php)

**Interfaces:**
- Consumes: `FileStore`, `MediaAsset`, SFTP and local storage forms.
- Produces: Validated storage archive selection flows in Playwright.

- [ ] **Step 1: Convert `MediaStorageBrowserTest.php`**

Update `$this->browse()` callbacks to Pest 4 `visit()` chains:
- Replace `$browser->loginAs($this->admin, 'admin')` with `$this->loginAs($this->admin, 'admin')`.
- Replace `$browser->visit(...)` with `visit(...)`.
- Replace `$browser->select(...)` with `$page->select(...)`.
- Replace `$browser->waitForText(...)` with direct `$page->assertSee(...)`.

- [ ] **Step 2: Run the test**

Run:
```bash
./vendor/bin/pest tests/Browser/MediaStorageBrowserTest.php
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/MediaStorageBrowserTest.php
git commit -m "test(browser): migrate MediaStorageBrowserTest to Pest 4 browser"
```

---

### Task 6: Migrate Documentation Screenshots Suite

**Files:**
- Modify: [`tests/Browser/DocumentationScreenshotsTest.php`](file:///var/www/tallpbx/tests/Browser/DocumentationScreenshotsTest.php)

**Interfaces:**
- Consumes: `DUSK_CAPTURE_DOCS=1` environment flag, `docs/images/` directory.
- Produces: High-resolution PNG screenshots with exact viewport sizing (1920x1080) for documentation.

- [ ] **Step 1: Convert `DocumentationScreenshotsTest.php` from class to Pest syntax**

Convert `DocumentationScreenshotsTest.php` to functional Pest syntax:
```php
<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    if (getenv('DUSK_CAPTURE_DOCS') !== '1') {
        $this->markTestSkipped('Set DUSK_CAPTURE_DOCS=1 to refresh documentation screenshots.');
    }

    $this->admin = Admin::firstOrCreate(
        ['email' => 'admin@tallpbx.org'],
        [
            'name' => 'System Administrator',
            'password' => bcrypt('tallpbx-secret'),
            'enabled' => true,
            'theme' => 'light',
            'layout_mode' => 'sidebar',
            'sidebar_collapsed' => false,
        ],
    );

    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    if ($superAdminGroup) {
        $this->admin->groups()->syncWithoutDetaching([$superAdminGroup->id]);
    }
});

it('captures documentation screenshots', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel')->resize(1920, 1080);
    $page->assertSee('System Status');

    $outputPath = base_path('docs/images/dashboard.png');
    $page->screenshot($outputPath);

    expect(file_exists($outputPath))->toBeTrue();
});
```

- [ ] **Step 2: Run verification (dry-run without flag, then opted-in)**

Run:
```bash
./vendor/bin/pest tests/Browser/DocumentationScreenshotsTest.php
```
Expected: Skipped (due to missing `DUSK_CAPTURE_DOCS=1`).

Run:
```bash
DUSK_CAPTURE_DOCS=1 ./vendor/bin/pest tests/Browser/DocumentationScreenshotsTest.php
```
Expected: PASS with screenshot generated.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/DocumentationScreenshotsTest.php
git commit -m "test(browser): migrate DocumentationScreenshotsTest to Pest 4 browser"
```

---

### Task 7: Update Test Runner Command & Add Browser Testsuite

**Files:**
- Modify: [`phpunit.xml`](file:///var/www/tallpbx/phpunit.xml)
- Modify: [`app/Console/Commands/TestCommand.php`](file:///var/www/tallpbx/app/Console/Commands/TestCommand.php)
- Create: `scripts/test-browser.sh`

**Interfaces:**
- Consumes: `php artisan app:test --full`, `bash scripts/test-browser.sh`.
- Produces: Integrated test runner that can run unit, feature, and browser tests together or separately.

- [ ] **Step 1: Add Browser testsuite to `phpunit.xml`**

In [`phpunit.xml`](file:///var/www/tallpbx/phpunit.xml#L11-L18):
```xml
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    </testsuites>
```

- [ ] **Step 2: Create lightweight `scripts/test-browser.sh`**

Create `scripts/test-browser.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

./vendor/bin/pest tests/Browser "$@"
```
Make executable: `chmod +x scripts/test-browser.sh`.

- [ ] **Step 3: Update `TestCommand.php` to use the Pest runner**

In [`app/Console/Commands/TestCommand.php`](file:///var/www/tallpbx/app/Console/Commands/TestCommand.php#L243-L255):
```php
    /**
     * Run browser tests via Pest 4 and Playwright.
     */
    private function runDusk(): int
    {
        $this->line('<comment>$ ./vendor/bin/pest tests/Browser</comment>');

        $process = new Process(['./vendor/bin/pest', 'tests/Browser', '--compact'], base_path());
        $process->setTimeout(300);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
```

- [ ] **Step 4: Verify `php artisan app:test --full`**

Run:
```bash
php artisan app:test --full
```
Expected: PASS across feature tests and all browser tests.

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml app/Console/Commands/TestCommand.php scripts/test-browser.sh
git commit -m "feat(testing): integrate browser testsuite into phpunit.xml and app:test"
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
