# Agent Instructions

## Agent Documentation Source of Truth

- Keep durable project guidance in this `AGENTS.md` file or in the generic `.agents/skills/` directory.
- Do not store project instructions in tool-specific memory locations such as `.opencode/memory/`; those files are local to one agent runtime and are not portable across humans or other AI agents.

## Project Skills

- Follow the project skills under `.agents/skills/` whenever a task matches their scope, and check them at the start of applicable work before writing code. Available skills: `laravel-best-practices` (all Laravel PHP code), `freeswitch-development` (FreeSWITCH/PBX behavior), `livewire-development` (Livewire components and reactivity), `tailwindcss-development` (Tailwind/UI classes), `tallpbx-custom` (TallPBX-specific patterns such as authentication guards, tenant isolation, group permissions, and dual-event binding), `testing-best-practices` (Laravel test design, coverage, and review), and `echo-development` (Laravel Echo real-time broadcasting and WebSockets). Applicable skills take precedence over generic habits for matching work.

## CRITICAL — Cache Clearing After Code Changes

After ANY code changes (Blade views, config, routes, events, Livewire components, compiled classes), ALWAYS run:
```
php artisan optimize:clear
```
Do not skip this step. The application caches compiled views, config, routes, and events — failing to clear will result in stale output. This applies even to "cosmetic" changes like replacing raw SVGs with heroicons. The application listens for Artisan command completion and runs `App\Support\GeneratedFilePermissions::repair()` so generated cache/build files stay readable by `www-data`.

## CRITICAL — Mandatory Pre-Claim Verification

You MUST NOT claim a feature is "fixed" or "done" until ALL of the following pass.
Run them in this exact order after every change, before every commit:

```bash
# 1. Application must boot without errors
php artisan optimize:clear

# 2. All feature/unit tests must pass
php artisan test --compact

# 3. Dusk browser tests must pass (for UI changes)
php artisan dusk

# 4. Routes must exist (especially for new features)
php artisan route:list --name=<feature-name>
```

**CI enforcement**: `.github/workflows/tests.yml` runs the full Pest suite (in-memory SQLite plus a Redis service, on PHP 8.5) for every push to `main`/release branches and every pull request. Treat it as the authoritative execution of step 2 and configure branch protection so a failing run cannot be merged.

**For new modules specifically**, also verify:
- `module.json` exists and `php artisan module:sync --only-local` succeeds
- Permissions are in the database: check with `php artisan tinker --execute 'echo Permission::where("name","your.permission")->exists() ? "YES" : "NO";'`
- Translation keys exist in `lang/en/admin.php` (use `grep` to verify)
- View namespace is registered (test by visiting the page in Dusk)
- Route middleware includes `web` (sessions), `auth.panel` (auth), and `throttle` (rate limit)
- Super Admin group has the new permissions: `php artisan db:seed --class=AdminSeeder`

**Any deviation from this checklist is a bug.** Do not skip steps when claiming completion.

## Changelog Maintenance & Release Management

### CHANGELOG.md Rules
- The authoritative changelog is `CHANGELOG.md`, following the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) standard and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
- Keep an active `## [Unreleased]` section at the top of the file for in-progress work.
- When adding features, fixing bugs, or modifying security/system behavior, update `[Unreleased]` under the appropriate standard subheading:
  - `### Added` for new features or modules
  - `### Changed` for changes in existing functionality
  - `### Deprecated` for soon-to-be-removed features
  - `### Removed` for removed features
  - `### Fixed` for any bug fixes
  - `### Security` for vulnerability fixes or hardening
- Do not dump raw git commit logs; explain changes clearly in plain English from the perspective of an administrator or user.
- If a change requires database migrations (`php artisan migrate`), new Linux packages (e.g. `nftables`), FreeSWITCH reloads, or `.env` updates, note it explicitly.
- When cutting an official release, move `[Unreleased]` notes into a versioned section (e.g., `## [1.1.0] - YYYY-MM-DD`) and open a new empty `## [Unreleased]` block.

### Branching & Sync Strategy
- `main` is the primary development branch.
- Minor release branches (`1.0`, `1.1`) represent release series.
  - `1.0` is the frozen maintenance branch for 1.0.x (bug fixes only). Do not push new feature work (like the Security module) to `1.0`.
  - `1.1` is the current release branch for 1.1.x features.
  - When syncing development work, keep `main` and the active release branch (`1.1`) synchronized.

### Tagging Best Practices (Do NOT Tag Every Commit)
- **Never tag individual commits or task completions.**
- Tags (`v1.0.0`, `v1.1.0`, `v1.0.1`) are reserved strictly for official, finished production releases.
- Use branch heads and commit SHAs for intermediate work and references.
- Only tag after full verification passes, the changelog version is dated, and the release is ready for users.

## Application File Permissions
- `ApplicationFilePermissions` is the authoritative PHP permission service. It exposes `generated`, `runtime`, and `full` scopes through `php artisan permissions:repair --scope=<scope>`. Full repair keeps deployed source owned by `root:www-data` (directories `755`, ordinary files `644`), Composer dependencies owned by `root:www-data` (directories `2775`, files `664`, preserving executable `vendor/bin` entry points), dependency lockfiles `root:www-data` with mode `664`, `.env` `root:www-data` with mode `640`, and `storage/` and `bootstrap/cache/` writable by `www-data` (directories `2775`, files `664`). The setgid directory mode ensures newly created runtime and vendor files keep the `www-data` group.
- The installer runs `php artisan permissions:repair --scope=full` during the Laravel setup step, again after caches/build files are created, and once more as its final reconciliation step. Installer re-runs therefore repair the complete application tree automatically.
- `composer update` runs the full Artisan repair through Composer's `post-update-cmd` hook after Laravel asset publication. `composer update --no-scripts` bypasses the hook and requires a manual full repair afterward. `composer install` does not use this hook.
- Use `sudo php artisan permissions:repair --scope=full` after a manual deployment, a root-owned file operation, or a permission/ownership error involving source, runtime files, or `.env`. `scripts/repair-application-permissions.sh` remains a standalone emergency fallback when Laravel cannot boot. Do not use recursive `chown` or `chmod` commands against the whole application tree; they can make source writable by the web user or break executable scripts.
- A FreeSWITCH restart or reload does not change Laravel file ownership and does not need to run the repair script. Run it only after an operation that created or changed application files.
- `bootstrap/cache` and `public/build` are generated by Artisan/Vite and must remain readable by PHP-FPM/Nginx (`www-data`). `php artisan optimize:clear` and other Artisan commands repair their generated paths automatically through `App\Support\GeneratedFilePermissions`.
- `npm run build` runs `vite build && bash scripts/fix-generated-permissions.sh`; keep this lightweight post-build repair in place. The full repair script calls it as its final generated-file pass.
- If only manually run Vite, Composer scripts, or root-owned cache/build commands caused a generated-file problem, use:
```
bash scripts/fix-generated-permissions.sh
```
- Do not commit generated cache/build artifacts or permission-only mode changes.

## Dusk Database Isolation
- Dusk must use local `.env.dusk` (auto-provisioned from `.env.dusk.example` by `scripts/dusk.sh`) with `DUSK_TESTING=true`, a database name ending in `_dusk`, and a database user name ending in `_dusk`. `App\Support\DuskDatabaseSafety` fails application boot when any of these conditions would allow a browser test to use the primary application database.
- The installer creates a separate `${database_name}_dusk` database and `${database_username}_dusk` MariaDB user. The Dusk user receives privileges only on the disposable Dusk database; never grant it access to the primary database.
- Run browser tests with `bash scripts/dusk.sh`. It starts an isolated `APP_ENV=dusk` Laravel server on `127.0.0.1:8001`; do not point Dusk at Nginx/PHP-FPM. `php artisan app:test --full` invokes this runner automatically.
- Do not use the web panel while Dusk is running. `php artisan dusk` temporarily swaps the application's `.env` with `.env.dusk` for the duration of the run; any request served by Nginx/PHP-FPM in that window (for example, an administrator logging in) boots with the Dusk environment — it operates on the disposable `*_dusk` database, shares sessions and Redis counters with the running tests (making browser tests flaky or fail), and failed logins are still counted by intrusion detection. Privileged firewall execution is blocked whenever `DUSK_TESTING=true`, including this swap window.
- Documentation screenshots under `docs/images/` are captured on demand only: normal `bash scripts/dusk.sh` runs never touch them. To recapture after a UI change run `DUSK_CAPTURE_DOCS=1 bash scripts/dusk.sh` and commit only the images whose pages actually changed (the capture step copies an image only when its content differs).

## Linux Command Execution & Privileged Host Helper Policy

TallPBX enforces a strict two-tier policy for executing Linux commands to prevent command injection (CWE-78) and root privilege escalation:

### 1. Unprivileged Application Commands (Non-Root)
- **Scope**: Git update operations, Composer package actions, application cache/build commands, and non-mutating system diagnostics (`df`, `systemctl is-active`, `getconf`).
- **Implementation**: Use `Symfony\Component\Process\Process` or `App\Support\SystemProcessRunner`.
- **Security Controls**:
  - Run exclusively under the unprivileged web/CLI user (`www-data`).
  - Always pass discrete argument arrays (`new Process(['git', '-C', $path, 'status'])`) rather than interpolated shell strings.
  - When shell string execution is unavoidable (e.g. piped shell diagnostics), all dynamic arguments MUST be wrapped with `escapeshellarg()`, executed with standard binary paths explicitly defined in `PATH`, and bounded with `timeout -k 30s <seconds>`.

### 2. Privileged Host Commands (Bounded Sudoers Helper Pattern)
- **Scope**: Kernel firewall configuration (`nftables`) and full system database/telephony restores (`tallpbx-restore`).
- **Policy**: Direct sudo execution of general-purpose system binaries (e.g. `sudo bash`, `sudo nft`, `sudo systemctl`, or wildcard `ALL=(ALL) NOPASSWD: ALL`) is STRICTLY FORBIDDEN.
- **Required Architecture**:
  1. **Dedicated Bounded Helper**: Privileged operations MUST be encapsulated in a dedicated root-owned script located in `/usr/local/sbin/` with permissions `0750 root:www-data` (e.g. `/usr/local/sbin/tallpbx-security`, `/usr/local/sbin/tallpbx-restore`).
  2. **Bounded Sudoers File**: A matching sudoers drop-in under `/etc/sudoers.d/` grants `NOPASSWD` access exclusively to that single executable path for `www-data` (e.g. `/etc/sudoers.d/tallpbx-security`).
  3. **Strict Input Whitelisting**: Helper scripts must reject all unexpected arguments. Every parameter (IP address, duration, operation UUID) MUST be validated against strict regular expressions before executing underlying utilities.
  4. **Non-Interactive & Non-Escapable**: Helpers must run non-interactively (`set -euo pipefail`), invoke underlying binaries with hardcoded absolute paths (`/usr/sbin/nft`), and NEVER call tools with subshell or interactive escape vectors (e.g. editors, pagers, or `find -exec`).
  5. **Preflight Syntax Verification**: State-altering operations (such as firewall application) must validate syntax atomically (e.g. `nft -c -f <pending>`) before replacing active configuration, ensuring system integrity and preventing administrative lockout.

## Code style
- Follow TALL stack best practices; fall back to general PHP best practices for anything TALL does not cover.
- Comment all functions, methods, and classes explaining what they do in simple language. Also comment any line or section where the intent is not obvious. Every class and every method must have a PHPDoc comment explaining what it does in easy-to-understand language, and inline comments must be added to code sections where the intent is not immediately obvious.
- Installer scripts must be readable by an administrator who does not know Bash. Before changing any file under `scripts/install.sh` or `scripts/resources/`, review the whole script and add or improve plain-language comments for every function and logical step. Explain what each step changes, why it is needed, what is preserved on re-runs, and any destructive or security-sensitive effect. Do not add comments that merely repeat the command name.
- The main installer must collect its applicable interactive choices before package, service, database, or application work starts. Resource scripts invoked by the installer must consume those preflight values without prompting; a direct standalone resource-script invocation may retain a clearly documented interactive fallback.
- Follow PSR-12/PER coding standards and SOLID principles.
- Use strict types (`declare(strict_types=1);`) and native type hints.
- Keep controllers thin — move business logic to Service classes and use Form Requests for validation.
- Use Eloquent relationships and eager loading (`with()`) to avoid N+1 queries.
- Wrap multi-step database operations in `DB::transaction()`.

## Modular Architecture
- Follow the modular structure layout where each functional domain resides under `app-modules/ModuleName/`.
- Autoloading uses Composer path repositories — each module's `composer.json` maps `Modules\ModuleName\` to its `src/` folder (e.g. `Modules\Extensions\` maps to `app-modules/extensions/src`). Modules are auto-discovered via `extra.laravel.providers` in their composer.json.
- Define route files under `app-modules/ModuleName/routes/web.php` or `api.php`. Use standard Laravel routing `Route::get('/path', Component::class)` instead of `Route::livewire()`.
- The unified panel sidebar lives in `resources/views/layouts/app.blade.php` and uses `data-panel-sidebar-scroll` on both the drawer shell and nav list with `resources/js/app.js` to preserve scroll position across `wire:navigate` requests. Sidebar links must use `wire:navigate.preserve-scroll` so Livewire does not reset the window scroll when the sidebar itself makes the page taller than the viewport. Do not reintroduce `layouts.admin` or `data-admin-sidebar-scroll`.
- Sidebar menu items are recursively rendered by `resources/views/components/sidebar-menu-item.blade.php`. When passing state such as `persistedNavigation` into recursive children, always forward it in the nested `@include`; otherwise deep sections such as PBX → Advanced can lose Livewire persisted-navigation behavior.

## Unified Panel Architecture

The application uses a single unified panel with one layout (`layouts.app`), one sidebar, and one route tree (`/panel/` prefix). Both Admin and User models access the same interface. Permission gating controls what each sees — there is no separate "admin panel" and "client panel".

**Key differences between Admin and User:**
- Admins authenticate via the `admin` guard (`Admin` model), have system-wide permissions through groups (`admin_group` pivot), can impersonate tenant users
- Users authenticate via the `web` guard (`User` model), belong to one or more tenants (`tenant_user` pivot), are auto-scoped to their tenant
- Both models implement `hasPermission()` for Gate authorization
- Menu visibility is controlled by `MenuService::cannotView()` which checks permissions for both models

**Auth guards** (two separate guards remain for session management):
- `admin` guard → `Admin` model → system administrators
- `web` guard → `User` model → tenant users

**AuthPanelMiddleware** (`auth.panel` alias) checks both guards. Unauthenticated users are redirected to `/panel/login` (unified login page authenticating both system administrators and tenant users).

### ModuleServiceProvider Base Class

All module service providers MUST extend `App\Support\ModuleServiceProvider` instead of `Illuminate\Support\ServiceProvider`. The base class auto-registers views, migrations (if they exist), routes, Livewire components, menu items, and permissions via declarative methods. This eliminates repetitive boot() boilerplate.

**Required overrides:**
- `moduleName(): string` — Return the kebab-case module name (e.g., `'bridges'`, `'active-calls'`). Used as the view namespace, Livewire namespace, route path, and menu/permission group key.
- `moduleNamespace(): string` — Return the PHP root namespace (e.g., `'Modules\Bridges'`, `'Modules\ActiveCalls'`).

**Optional overrides:**
- `menuItems(): array` — Return menu items in the format expected by `MenuService::register()`. Menu items do NOT include a `guard` key — visibility is controlled by permissions alone.
- `permissions(): array` — Return permissions as `[key => description]` pairs. Permission names use the `admin.*` prefix (e.g., `'bridges.view'`), NOT the route prefix.
- `hasTranslations(): bool` — Override to return `true` if the module has a `resources/lang/` directory.
- `routeGroups(): array` — Override to customize route groups. By default, a single group with `/panel/` prefix, `panel.*` route names, and `auth.panel` middleware is registered.

**Service bindings (Laravel 13):**
Use Laravel 13's `$bindings` and `$singletons` properties for service container bindings instead of overriding `register()`. The framework auto-processes these properties.

```php
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    public $bindings = [
        BridgeServiceInterface::class => BridgeService::class,
    ];

    protected function moduleName(): string { return 'bridges'; }
    protected function moduleNamespace(): string { return 'Modules\\Bridges'; }
    protected function menuItems(): array { return [/* ... */]; }
    protected function permissions(): array { return [/* ... */]; }
}
```

**Do NOT extend the base class if:** The module has extensive custom boot logic (only `admin` qualifies today — its provider is 289 lines of bespoke menu/permission setup).

### CrudService Base Class for Services

For modules whose services only implement standard CRUD operations (create, update, delete) without custom business logic, the service class should extend `App\Support\CrudService` instead of implementing a separate interface and duplicating method bodies. The base class provides `create()`, `update()`, and `delete()` methods that delegate to the Eloquent model.

```php
use App\Support\CrudService;
use Modules\Bridges\Models\Bridge;

class BridgeService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = Bridge::class;
    }
}
```

**When to use CrudService:**
- The service only needs `create`, `update`, `delete`
- No custom query methods (like `getByTenant()`, `findByMailbox()`, `generateConfig()`)
- No custom business logic beyond model persistence

**When NOT to use CrudService (keep a dedicated interface + implementation):**
- The service has custom query or business logic methods
- Examples: `ProvisionService`, `SipProfileService`, `EmergencyService`, modules with `getByTenant()` or custom logic

When converting to `CrudService`, the `ServiceInterface` file is deleted, Livewire components inject the concrete service class directly (auto-resolved by the container), and the `$bindings` entry is removed from the provider.

### Route Auto-Registration (Pattern 4)

Modules with standard Livewire component naming conventions get their admin routes auto-registered under the `/panel/` prefix — no `routes/web.php` file needed. The base class detects which components exist and generates routes automatically.

**How it works:**
1. The base class checks if `routes/web.php` exists in the module
2. If it does, the file is loaded as-is (full control for custom routing)
3. If it doesn't, conventions are used to auto-register routes

**Standard naming convention:**
- `{PascalModule}List.php` → registers an index route (`GET /panel/{module}`) named `panel.{module}.index`
- `{PascalModule}Edit.php` → registers create and edit routes (`GET /panel/{module}/create`, `GET /panel/{module}/{id}/edit`) named `panel.{module}.create` and `panel.{module}.edit`
- PascalModule is derived from the kebab-case module name (e.g., `sip-profiles` → `SipProfiles`)

**Route prefix:** All auto-registered routes use `/panel/` prefix with the `panel.` route name prefix. Both Admin and User models authenticate via `auth.panel` middleware.

**When to create a routes/web.php file (instead of relying on auto-registration):**
- The module uses non-standard Livewire component names (e.g., `QueueList` instead of `CallCentersList`)
- The module needs public/non-authenticated endpoints (e.g., `provision` has a public provisioning URL)
- The module needs additional routes beyond index/create/edit
- The module routes use non-standard URL structures

The `routeGroups()` method can be overridden to define additional auto-registration groups (e.g., for client-area routes), each with its own prefix, middleware, and naming convention. This preserves auto-registration benefits while enabling multi-area expansion.

### Creating a New Module

When creating a new module:
1. Create the directory `app-modules/{module-name}/` with `src/`, `resources/views/` structure (no `routes/` directory needed for standard CRUD — routes auto-register under `/panel/`)
2. The service provider extends `\App\Support\ModuleServiceProvider` and defines `moduleName()` and `moduleNamespace()`
3. If the module has a CRUD service with no custom logic, extend `\App\Support\CrudService`
4. If the module has custom business logic, create a dedicated service interface + implementation
5. Create Livewire components as needed (see Livewire component base classes below). Follow standard naming: `{PascalModule}List` and `{PascalModule}Edit`
6. Add a path repository entry in root `composer.json` and run `composer update {package-name}` to register the new module

### Safe Module Uninstall

- Module uninstall support is opt-in through an explicit `ModuleUninstaller` handler. A module without a reviewed handler must remain non-uninstallable.
- The currently reviewed table-owned modules are `pin-numbers`, `access-controls`, `email-templates`, `email-queue`, `tenant-limits`, and `call-broadcast`. Their ownership is isolated and their schemas can be recreated on reinstall.
- Do not add uninstall handlers to core SIP, directory, or routing modules, XML dialplan contributors with application-level indexes, modules involved in cross-module foreign-key chains, file-owning modules, or operational modules with runtime state until their cleanup and retention contracts are explicit.
- Before making another module uninstallable, audit table and file ownership, cross-module references, foreign keys, application-level migrations and indexes, runtime jobs/events/ESL behavior, and retention requirements.
- Add TDD lifecycle coverage proving handler registration, exact owned schema/data removal, preservation of unrelated tenant and permission data, migration-record cleanup, schema recreation, and successful writes after reinstall.

### Livewire Component Base Classes

List and Edit components should extend the following base classes instead of `Livewire\Component` directly. These base classes apply the single `layouts.app` layout and provide guard detection helpers for tenant-scoped queries.

**List components** extend `\App\Support\BaseListComponent`:
- Applies `layouts.app` layout automatically
- Resolves the Blade view automatically from the class name (e.g., `BridgesList` → `bridges::bridges-list`)
- Provides `isAdminGuard(): bool` helper for tenant-scoped queries
- Subclasses only need to define the collection property, `mount()`, load/delete methods
- Do NOT include a `render()` method unless passing data to the view

```php
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Bridges\Models\Bridge;

class BridgesList extends BaseListComponent
{
    public Collection $bridges;

    public function mount(): void
    {
        $this->bridges = Bridge::orderBy('bridge_name')
            ->when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))
            ->get();
    }

    public function deleteBridge(string $bridgeId): void
    {
        $bridge = Bridge::withoutGlobalScope('tenant')->findOrFail($bridgeId);
        $bridge->delete();
        $this->dispatch('bridge-deleted');
    }
}
```

**Edit components** extend `\App\Support\BaseEditComponent`:
- Applies `layouts.app` layout automatically
- Provides `$tenantId`, `$enabled`, and `$tenants` properties
- Provides `isAdminGuard()`, `resolveTenantId()`, and guard-aware `loadTenants()` helpers
- Resolves the Blade view automatically from the class name
- Subclasses call `$this->loadTenants()` from `mount()` and use `$this->isAdminGuard()` for guard-conditional validation and queries

```php
use App\Support\BaseEditComponent;
use Modules\Bridges\Models\Bridge;
use Modules\Bridges\Services\BridgeService;

class BridgesEdit extends BaseEditComponent
{
    public string $bridgeName = '';
    public string $destinationNumber = '';
    public ?string $bridgeId = null;

    private BridgeService $bridgeService;

    public function boot(BridgeService $bridgeService): void
    {
        $this->bridgeService = $bridgeService;
    }

    public function mount(?string $bridgeId = null): void
    {
        $this->loadTenants();
        if ($bridgeId !== null) {
            $bridge = Bridge::when($this->isAdminGuard(), fn ($q) => $q->withoutGlobalScope('tenant'))
                ->findOrFail($bridgeId);
            $this->bridgeName = $bridge->bridge_name;
        }
    }

    public function save(): void
    {
        $this->validate();
        // create or update via service
        $this->redirect(route('panel.bridges.index'));
    }

    public function rules(): array
    {
        $rules = [/* field rules */];
        // Only admin users need to pick a tenant from the dropdown
        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }
        return $rules;
    }
}
```

**Blade views** use `route('panel.X')` since all routes are under the `/panel/` prefix:
```blade
<a href="{{ route('panel.bridges.index') }}">Cancel</a>
<a href="{{ route('panel.bridges.edit', $bridge->id) }}">Edit</a>
```

**Module providers** register a single menu item without `guard` restriction — permission gating via `MenuService::cannotView()` controls visibility for both Admin and User models:
```php
protected function menuItems(): array
{
    return [
        // Unified panel sidebar — both admin and tenant users see this
        // Permission gating controls visibility automatically.
        [
            'key' => 'bridges',
            'label' => 'admin.bridges',
            'route' => 'panel.bridges.index',
            'permission' => 'bridges.view',
            'icon' => 'heroicon-o-arrow-right-on-rectangle',
            'parent' => 'pbx.features.routing',
            'order' => 70,
        ],
    ];
}
```

**Do NOT extend these base classes for:**
- Admin module components (admin has its own patterns)
- Components with custom render methods that pass data to views (keep the render method, but add `->layout('layouts.app')` to the return)

### Real-Time Responsiveness & Push Events Policy (No Polling, No Manual Refresh Buttons)

- **Strict Prohibitions**:
  - `wire:poll` and manual "Refresh Status" / "Reload" buttons are STRICTLY PROHIBITED project-wide for any status indicators, metrics, tables, or operational dashboards that can be pushed or reactively updated.
  - Periodic polling wastes network bandwidth, database queries, and CPU cycles, while manual refresh buttons introduce poor UX and stale data.
- **Canonical Implementation (`resources/views/components/dashboard/stats.blade.php`)**:
  - Livewire components must be real-time reactive using push-based mechanisms:
    1. **WebSocket Broadcasting (Laravel Reverb)**: Listen to real-time events via Livewire 4's Echo attributes:
       ```php
       #[On('echo:<channel>,.<EventClass>')]
       public function refreshData(): void { /* update properties */ }
       ```
    2. **Livewire Event Binding**: For user interactions and local actions across components, listen using `#[On('event-name')]`:
       ```php
       #[On('refresh-monitoring')]
       #[On('echo:dashboard.monitoring,.DashboardStatsUpdated')]
       public function refreshMonitoringData(): void
       ```
    3. **Computed Properties**: Use `#[Computed]` for derived data so Livewire re-computes them automatically when dependencies change.
  - Automated tests must assert the absence of polling and manual refresh:
    ```php
    ->assertDontSee('wire:poll')
    ->assertDontSee('wire:click="refreshStatus"', false);
    ```

### Instant Auto-Application of System Configuration (Zero-Staging Workflow)

- **Avoid Manual "Stage then Apply" Workflows**:
  - Do NOT require users to perform a multi-step staging workflow (e.g. edit a rule, increment a pending counter, and then click a separate "Save & Apply Changes" button) when atomic execution is practical and safe.
  - Modern administrative interfaces must apply state changes immediately upon user interaction without fragile, cumbersome staging intermediate states.
- **Immediate Subsystem Synchronization**:
  - When an administrator or user modifies a configuration (e.g. whitelisting/blacklisting an IP, toggling a firewall rule, changing a sensitivity threshold, or reordering priorities), the change must be persisted to the database AND immediately applied to the underlying subsystem (e.g. Linux kernel `nftables` packet filter, FreeSWITCH runtime XML, cache).
- **Atomic Safety & Rollback**:
  - Always execute preflight safety checks (e.g. `LockoutGuardService::assertSafe()`) and atomic syntax checks (e.g. `nft -c`) before loading configuration into the kernel or FreeSWITCH.
  - If a preflight check fails, reject the change immediately, notify the user with a descriptive toast alert (`$this->showError(...)`), and keep active running configuration untouched.

## FreeSWITCH Telephony Integration
- Do not write static XML configuration files to disk. Instead, serve dynamic dialplans, directories, configurations, and phrases using FreeSWITCH's `mod_xml_curl` through the application's XML Handler API (`/api/v1/xml-handler`).
- Store media payloads such as call recordings, uploaded recordings, voicemails, and fax files as files. Database tables should store file paths and metadata only; do not introduce base64/blob media storage for these payloads.
- Do not vendor default FreeSWITCH prompt or music binaries into the Laravel repository. Fresh installs should use FreeSWITCH sound packages such as the US English Callie prompts and packaged music-on-hold files, with app-managed custom media stored as files.
- Fresh installs are expected to install `freeswitch-mod-sofia`, `freeswitch-mod-callcenter`, `freeswitch-mod-dptools`, `freeswitch-mod-hiredis`, `freeswitch-mod-local-stream`, `freeswitch-mod-sndfile`, and `freeswitch-mod-xml-curl`; enable `mod_sofia`, `mod_callcenter`, `mod_dptools`, `mod_hiredis`, `mod_local_stream`, `mod_sndfile`, and `mod_xml_curl` in `modules.conf.xml`; disable legacy `mod_redis` and `mod_memcache`; write `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`; and install `/etc/systemd/system/freeswitch.service.d/10-tallpbx-dependencies.conf` so FreeSWITCH starts after Nginx, PHP-FPM, MariaDB, and Redis. For existing servers or token rotation, run `scripts/resources/freeswitch.sh --configure-only` after `.env` contains `FREESWITCH_XML_HANDLER_TOKEN`.
- Fresh non-demo installs create only the Default tenant and administrator access through `DatabaseSeeder`; demo mode is the sole creator of sample PBX data. Keep both paths idempotent so installer re-runs do not duplicate their seeded data.
- Prefer tenant context strings (for example, `tenant_{id}_internal` and `tenant_{id}_public`) as the primary tenant discriminator for FreeSWITCH XML handling. Domain-based tenant resolution is a fallback only and must fail closed or require additional identity data when a domain is shared by multiple tenants.
- Ensure all multi-step database writes (e.g., creating an Extension along with Voicemails and Dialplans) are wrapped inside `DB::transaction()` to prevent partial failure states.
- For dynamic dialplan load testing, use `php artisan pbx:load-test:seed` and `php artisan pbx:load-test:dialplan` against the real Nginx/PHP-FPM endpoint (for example, `http://PBX_HOST/api/v1/xml-handler`). Do not use `php artisan serve` for performance conclusions because it is not representative under concurrency.
- Keep XML handler auth enabled for real-server tests and pass `--token="$FREESWITCH_XML_HANDLER_TOKEN"` to `pbx:load-test:dialplan`. If the token changes, run `php artisan optimize:clear` so PHP-FPM sees the new config.
- XML handler successful request logging is intentionally opt-in through `FREESWITCH_XML_HANDLER_LOG_REQUESTS`; avoid enabling it during load tests. Redis is the recommended default cache and session store for this project (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `SESSION_CONNECTION=cache`). The generated dialplan XML cache is controlled by `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL` and `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE`; avoid file/database stores for concurrency tests because they add their own latency. First-party context-wide contributor fragments are controlled by `FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL`. Keep `mod_hiredis` installed/loaded by default, but keep generated per-call hiredis dialplan behavior opt-in through `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED` and `FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED`.
- Use `--label`, `--max-failure-rate`, `--max-p95-ms`, and `--max-p99-ms` on `pbx:load-test:dialplan` when comparing repeat runs. The JSON report is the durable source for run ID, Git state, environment, target scenario mix, success/failure rates, latency percentiles, thresholds, and pass/fail reasons.
- For SIPp end-to-end validation, prefer running `scripts/pbx-sipp-validate.sh` from WSL2 or a separate Linux VM against the PBX host. The runner registers seeded users, starts auto-answer UAS scenarios, places extension-to-extension and outbound-route calls, and stores artifacts under `storage/app/load-tests/sipp-e2e-*`. Set `MEDIA_FLOW=1` only when live recording/MOH/announcement RTP checks are needed; it seeds synthetic media destinations and uses SIPp RTP echo with optional tcpdump pcaps. Use `docs/sipp-server-to-server-validation.md` as the detailed operating guide for topology, manual WSL2 commands, artifact interpretation, and known current results. If `sipp` is missing, install it on the load generator before running the script.
- A recording media validation must prove that the call remains active long enough to capture media, ends normally, and produces a non-empty retained recording. Merely reaching `record_session` is not a pass; the default `*732` path has previously started recording and immediately hung up with SIP `480`, causing FreeSWITCH to discard an empty file.
- Installer changes that affect deployment dependencies must keep Redis explicit and preserve FreeSWITCH dynamic XML defaults: install `redis-server`/`redis-tools`, enable `redis-server`, keep `php8.5-redis`, install/enable Sofia, callcenter, dptools, hiredis, local-stream, sndfile, and xml-curl FreeSWITCH modules, disable legacy redis/memcache FreeSWITCH modules, and document upgrade steps for existing file-cache/file-session or manual XML curl installs.
- Installer mode selection must remain idempotent: with no demo or development flag, interactive runs ask for each choice and use the persisted value as the default; pressing Enter must preserve the current installation. Non-interactive runs reuse persisted or detected state. Never remove Composer development dependencies or Laravel Boost unless `--production` is explicit or production mode was explicitly recorded. Keep only approved installer flags; do not add aliases without user approval. Demo reconciliation must remain non-destructive.
- Validate installer and installer-rerun idempotency on a fresh disposable Debian server. Do not use a host that has been repeatedly resized, tuned, or populated with synthetic load-test data for production installer conclusions.
- When reviewing PHP-FPM limits, inspect `php-fpm8.5 -tt` and `/var/log/php8.5-fpm.log` for `pm.max_children` saturation. The standard install recommendation is `pm = static` and `pm.max_children = 12` for a 4 GB combined PBX/application server; leave `pm.max_requests` at its default of `0` unless sustained monitoring demonstrates worker memory growth. See `INSTALL.md` before changing sizing guidance. More workers may help moderate concurrency but can make CPU contention worse; confirm with before/after `pbx:load-test:dialplan` reports.
- When changing XML handler, dialplan contributor, module-state, load-test code, or hot-path XML-handler indexes, run focused tests such as `php artisan test --compact tests/Feature/Http/XmlHandlerControllerTest.php tests/Feature/Services/ModuleStateTest.php tests/Feature/XmlHandlerContributorIndexTest.php tests/Feature/PbxLoadTestSeedCommandTest.php tests/Feature/PbxDialplanLoadTestCommandTest.php`, then run `php artisan app:test --smoke` for shared hot-path changes. Before real PHP-FPM load testing, run `php artisan optimize` after the required `php artisan optimize:clear`; do not clear optimized files while test traffic is active.
- Key FreeSWITCH 1.11 runtime rules learned from operational drills: FreeSWITCH drops supplementary groups at startup, so its runtime group must be `tallpbx-media` with managed media directories using mode `2775`; `mod_voicemail` resolves deposit directory paths from directory user `<params>` (`vm-domain-storage-dir`), not `<variables>`; ESL event listening must decode framed message bodies beyond frame headers; and feature code extensions must specify `continue="true"` so subsequent call-processing extensions can execute.

## Testing
- Use Pest for all tests (not PHPUnit).
- Write tests first (TDD), then implement code to make them pass. For every feature or bugfix, write a failing Pest test first, run it and watch it fail for the expected reason (feature missing, not a typo), then implement the minimal code and watch it pass (red-green-refactor). The task is only complete when the previously failing test passes; a test written after the code passes immediately and proves nothing.
- Use the tiered test runner to avoid running the full suite unnecessarily:

```bash
# Smoke — critical-path only (~200 tests, ~20s)
php artisan app:test --smoke

# Default — all feature tests with --parallel (~1,993 tests, ~65s)
php artisan app:test

# Full — features + Dusk browser tests (~150s)
php artisan app:test --full

# Sequential — for debugging without --parallel
php artisan app:test --sequential

# Optional cache refresh before a run
php artisan app:test --smoke --clear-cache
```

**Do NOT run `php artisan test --parallel` directly.** Use `app:test` instead — it applies `--compact` and `--parallel` automatically. Both test commands force in-memory SQLite and refuse any other test database; `app:test` also removes inherited `.env` values before starting Pest. Run smoke after small changes, default before committing, and full only before pushing.

**Never re-run the same tests without code changes.** Tests take 60-180 seconds and re-running without changes wastes time.

## Commits
- Do not commit or push changes without explicit approval from the user.

## External References
- FusionPBX repository: https://github.com/fusionpbx/fusionpbx
- FusionPBX installer script: https://github.com/fusionpbx/fusionpbx-install.sh
- FreeSWITCH official documentation: https://developer.signalwire.com/freeswitch/
  - Getting Started / Installing from Packages is the recommended installation approach
  - Uses `freeswitch-meta-vanilla` + sound packages, not a custom module list
- FreeSWITCH source repository: https://github.com/signalwire/freeswitch
  - README.md links to package install and source build guides

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

<skills_system priority="1">

## Available Skills

<!-- SKILLS_TABLE_START -->
<usage>
When users ask you to perform tasks, check if any of the available skills below can help complete the task more effectively. Skills provide specialized capabilities and domain knowledge.

How to use skills:
- Invoke: Bash("composer read-skill <skill-name>")
- The skill content will load with detailed instructions on how to complete the task
- IMPORTANT: Always cd to the Base Directory shown in output before executing scripts or accessing bundled resources

Usage notes:
- For project-specific tasks, only use skills listed in <available_skills> below
- Note: Native capabilities (e.g., via the Skill tool) remain available alongside project skills
- Do not invoke a skill that is already loaded in your context
- Each skill invocation is stateless
</usage>

<available_skills>

</available_skills>
<!-- SKILLS_TABLE_END -->

</skills_system>
