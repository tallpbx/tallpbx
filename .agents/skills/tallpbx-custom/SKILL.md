---
name: tallpbx-custom
description: "Invoke when working on TallPBX-specific patterns: the installer and resource scripts, the x-tooltip Blade component, DaisyUI 5 tooltip positioning and safelisting, the custom.css Tailwind v4 architecture, Livewire 4 + Alpine 5 reactive UI toggling, scroll preservation with wire:navigate:scroll, the TALL stack dual-event binding pattern, authentication guards (admin/web), tenant context and isolation, impersonation, group permissions, permission seeding, cross-tenant data boundaries, primary-database safety guards, or changelog maintenance and release tagging conventions."
license: MIT
metadata:
  author: tallpbx
---

# TallPBX Custom Frontend Patterns

## Installer And Resource Scripts

Use this section whenever changing `scripts/install.sh` or a script under
`scripts/resources/`.

- Read the complete script before editing it. These scripts are idempotent
  deployment code, so a local-looking command can affect a re-run or upgrade.
- Comment every function and logical step in simple, non-technical language.
  State what it changes, why it is needed, what remains safe on a re-run, and
  any effect on secrets, ownership, services, packages, files, or data. Do not
  write comments that only restate a command.
- Keep the main installer in two phases: a preflight questionnaire first, then
  execution. Collect and validate all applicable choices and secrets before
  package, database, service, or application changes begin. Persist accepted
  values in the root-only installer state file so a failed run can resume.
- Resource scripts launched by the main installer must read exported `FSPBX_*`
  values and must not prompt. A resource script may retain an interactive
  fallback only for a documented direct standalone invocation.
- Preserve idempotency. Re-runs must reuse recorded values by default and must
  not delete application data, rotate secrets, or change installation mode
  unless an explicit user choice authorizes it.
- Validate shell syntax with `bash -n` for every changed shell script. Test a
  safe non-mutating path whenever one exists; do not invoke package, database,
  or service-changing paths merely to test parsing.

## Privileged Host Command Architecture (Bounded Sudoers Pattern)

TallPBX enforces a strict two-tier policy for running host Linux commands to prevent command injection (CWE-78) and root privilege escalation:

- **Unprivileged Commands**: Use `Symfony\Component\Process\Process` passing discrete argument arrays (`new Process(['git', '-C', $path, 'status'])`) under `www-data`. When shell string execution is unavoidable, wrap dynamic parameters with `escapeshellarg()` and bound with GNU `timeout -k 30s <seconds>`.
- **Privileged Operations (Root)**: Direct sudo execution of general-purpose system binaries (e.g. `sudo bash`, `sudo nft`, `sudo systemctl`, or wildcard `ALL=(ALL) NOPASSWD: ALL`) is STRICTLY FORBIDDEN.
- **The Bounded Helper Pattern**:
  1. Dedicated Helper: Encapsulate root operations in a dedicated script under `/usr/local/sbin/` with permissions `0750 root:www-data` (e.g. `/usr/local/sbin/tallpbx-security`, `/usr/local/sbin/tallpbx-restore`).
  2. Matching Sudoers Drop-In: `/etc/sudoers.d/` grants `NOPASSWD` exclusively to that single executable for `www-data`.
  3. Strict Regex Whitelisting: Reject all unexpected arguments. Every parameter (IP address, duration, operation UUID) MUST be validated against strict regular expressions before calling underlying utilities.
  4. Non-Interactive: Run with `set -euo pipefail` and hardcoded absolute paths (`/usr/sbin/nft`). Never call pagers, editors, or utilities with interactive escape vectors (GTFOBins).
  5. Preflight Syntax Checks: Validate pending state atomically (`nft -c -f <pending>`) before replacing active configuration, ensuring system integrity and zero lockout.

## x-tooltip Component

The `x-tooltip` Blade component (`resources/views/components/tooltip.blade.php`) wraps
DaisyUI 5's CSS-only tooltip. It generates tooltip position classes dynamically at
runtime (e.g. `'tooltip-' . $position`).

### Props

| Prop | Type | Default | Description |
|---|---|---|---|
| `tip` | `string|null` | `null` | Tooltip text rendered via `data-tip` attribute |
| `position` | `string` | `'top'` | DaisyUI position: `'top'`, `'bottom'`, `'left'`, `'right'` |
| `align` | `string|null` | `null` | Vertical alignment for left/right tooltips: `'start'`, `'center'`, `'end'` |
| `icon` | `string|null` | `null` | Heroicon component name (rendered when no slot is provided) |

### Alignment Behavior

When `position="right"` or `position="left"` is used, the `align` prop controls
where the tooltip bubble anchors relative to the trigger element:

| `align` | DaisyUI class | Anchors | Extends |
|---|---|---|---|
| `null` (default) | — | Center of trigger | Equally up and down |
| `'start'` | `tooltip-start` | **Top** of trigger | **Downward** — use for elements near page top |
| `'end'` | `tooltip-end` | Bottom of trigger | Upward |

**Rule**: For tooltips near the top of the page (title icons, first form fields),
always use `align="start"` so the bubble extends downward into visible space
instead of being clipped by the header.

```blade
{{-- Near top of page: use align="start" so bubble extends downward --}}
<x-tooltip :tip="__('admin.event_guard_tooltip')" align="start" position="right">
    <x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" />
</x-tooltip>
```

### Standardized Tooltip Pattern: Always Use the Information (i) Icon

TallPBX standardizes on using an explicit information `(i)` icon (`<x-heroicon-o-information-circle>`) as the trigger for all tooltips across headers, form field labels, and table headers. Do **not** wrap raw `<label>` or header text elements directly in `<x-tooltip>` without an icon.

**Why this pattern is mandatory:**
1. **Discoverability**: Gives the user an immediate, universal visual affordance that contextual help is available.
2. **Touch/Mobile Usability**: On mobile and tablet screens, users can tap the `(i)` icon to view the tooltip without accidentally focusing the input or toggling checkboxes.
3. **Clean Layout**: Prevents DaisyUI's inline-block `.tooltip` container from distorting `<label>` widths, margins, or flex alignments.

**Standard form field pattern:**
```blade
<label class="label justify-start gap-2">
    <span class="label-text">Field Name</span>
    <x-tooltip :tip="__('admin.field_tooltip')" position="right">
        <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
    </x-tooltip>
</label>
```

**Standard checkbox / radio pattern:**
```blade
<label class="label cursor-pointer justify-start gap-3">
    <input type="checkbox" wire:model="enabled" class="checkbox checkbox-primary" />
    <span class="label-text">Enabled</span>
    <x-tooltip :tip="__('admin.enabled_tooltip')" position="right">
        <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50 hover:opacity-100 text-base-content/70" />
    </x-tooltip>
</label>
```

### Tooltip Position Safelist

DaisyUI 5 tooltip position and alignment classes (`tooltip-right`, `tooltip-left`,
`tooltip-start`, `tooltip-end`) are generated dynamically by the `x-tooltip`
component. Tailwind v4's static analyzer cannot discover them through Blade
`@props` or PHP string concatenation, so they are **treeshaken from the
compiled CSS unless explicitly safelisted**.

**Safelist mechanism** (`resources/views/components/tooltip-safelist.blade.php`):
```blade
{{-- Tailwind v4 safelist --}}
<div class="hidden tooltip-right tooltip-left tooltip-end tooltip-start"></div>
```

This small Blade partial contains the class names as literal strings. The
`@source` directive in `custom.css` tells Tailwind to scan this file, forcing
the classes into the compiled CSS bundle.

When adding a new dynamically-generated DaisyUI class to the `x-tooltip`
component, **also add it to the safelist partial**.

## CSS Architecture

### custom.css

`resources/css/custom.css` is a project-specific CSS file that contains
Tailwind v4 directives (`@source`, `@import`, etc.) that extend the base
configuration without modifying `resources/css/app.css` (which may be
overwritten by Laravel updates).

```css
/* resources/css/custom.css */
@source "../views/components/tooltip-safelist.blade.php";
```

### Vite Integration

`custom.css` must be registered in three places:

1. **`vite.config.js` input array** — so Vite watches and rebuilds on changes:
   ```js
   input: ['resources/css/app.css', 'resources/css/custom.css', 'resources/js/app.js'],
   ```

2. **`@import` in `app.css`** — so custom.css is included during Tailwind compilation:
   ```css
   @import "tailwindcss";
   @plugin "daisyui";
   @import "./custom.css";
   ```

3. **`@vite` directive in the Blade layout** — so the built CSS is served:
   ```blade
   @vite(['resources/css/app.css', 'resources/css/custom.css', 'resources/js/app.js'])
   ```

### Rebuilding After CSS Changes

After any change to `custom.css`, `tooltip-safelist.blade.php`, or `app.css`,
run `npm run build` to recompile the DaisyUI classes into the production CSS
bundle. For development, `npm run dev` with Vite HMR will pick up changes
automatically.

## Livewire 4 + Alpine 5 Reactive UI Toggling

For form sections that toggle based on a Livewire property (like switching
between Password and OAuth 2.0 auth types), use the idiomatic **direct `$wire`
access** pattern. Do NOT use `$wire.entangle()` — it is discouraged in
Livewire 4 and `@entangle` is deprecated.

```blade
{{-- Alpine owns the show/hide — instant, no server roundtrip --}}
<div x-data="{ authType: $wire.smtp_auth_type }">
    {{-- Update both Alpine state (instant UI) and $wire (server sync) --}}
    <label @click="authType = 'password'; $wire.smtp_auth_type = 'password'">
        <input type="radio" wire:model="smtp_auth_type" value="password"> Password
    </label>
    <label @click="authType = 'oauth'; $wire.smtp_auth_type = 'oauth'">
        <input type="radio" wire:model="smtp_auth_type" value="oauth"> OAuth 2.0
    </label>

    <div x-show="authType === 'password'" x-cloak> ... password fields ... </div>
    <div x-show="authType === 'oauth'"   x-cloak> ... OAuth fields ...   </div>
</div>
```

Key points:
- `x-data="{ authType: $wire.smtp_auth_type }"` — initializes Alpine from Livewire
- `@click` updates both Alpine (`authType`) and Livewire (`$wire.smtp_auth_type`)
- `x-show` provides instant client-side toggle with zero server roundtrip
- Keep `wire:model` on form inputs that need server-side validation
- Use `x-cloak` to prevent flash of hidden content on initial render

## Scroll Preservation with wire:navigate

The layout uses `min-h-screen` on the drawer-content wrapper, which means the
**window** never scrolls. The actual page scroll happens inside the `<main>`
element via `overflow-y-auto`.

To preserve scroll position across `wire:navigate` transitions, add
`wire:navigate:scroll` to the scrolling container:

```blade
<main class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden flex flex-col justify-between"
      wire:navigate:scroll>
```

The sidebar `<nav>` already has this directive. Without it on `<main>`,
navigating to a short page (SMTP connector, git-update, monitoring) reset
the content scroll position to the top.

## TALL Stack Dual-Event Binding

When a DaisyUI-styled radio button fails to fire the native `change` event
that `wire:model` listens for, use dual event binding — Alpine `@click` for
the immediate state change plus `wire:model` for the server sync:

```blade
<label @click="authType = 'password'; $wire.smtp_auth_type = 'password'">
    <input type="radio" wire:model="smtp_auth_type" value="password" class="radio radio-sm" />
    <span>Password</span>
</label>
```

The `@click` on the label fires reliably regardless of DaisyUI's radio styling,
updating both Alpine state (instant UI) and the Livewire property (next
server request).

## Real-Time Push Responsiveness (No Polling or Manual Refresh)

- **Strict Prohibitions**: `wire:poll` and manual "Refresh" / "Reload" buttons are strictly prohibited for dashboards, tables, and system statuses.
- **Push Architecture (See `components/dashboard/stats.blade.php`)**:
  - Livewire components must be real-time reactive using push-based mechanisms:
    1. **Laravel Reverb (WebSockets)**: `#[On('echo:<channel>,.<EventClass>')]`
    2. **Livewire Event Binding**: `#[On('event-name')]`
    3. **Computed Properties**: `#[Computed]`
  - Automated tests must assert `->assertDontSee('wire:poll')` and `->assertDontSee('wire:click="refreshStatus"', false)`.

## Instant Auto-Application of System Configuration (Zero-Staging Workflow)

- **Avoid Staging Friction**: Never force users into multi-step "stage changes, then click Save & Apply" flows when atomic application is safe.
- **Immediate Subsystem Synchronization**: When an administrator toggles a rule, updates sensitivity, adds an IP, or reorders priorities, persist to the DB and apply to the kernel (`nftables`) or FreeSWITCH immediately.
- **Atomic Preflight Safety**: Always run `LockoutGuardService::assertSafe()` and preflight syntax checks (`nft -c`) before applying. On failure, notify via toast alert and preserve active configuration.

## DaisyUI 5 CSS Compilation Behavior

DaisyUI 5 registers utility classes (tooltip, btn, badge, etc.) via Tailwind v4's
`@plugin` directive. Tailwind v4 only includes classes that appear as **literal
strings** in scanned source files (Blade templates, JSX, etc.).

**Classes generated dynamically at runtime are treeshaken** unless explicitly
safelisted. This affects:
- The `x-tooltip` component (position classes built via `'tooltip-' . $position`)
- Any component that constructs DaisyUI class names via PHP string concatenation

### Treeshaking Symptoms

A class exists in `node_modules/daisyui/components/tooltip.css` but does NOT
appear in `public/build/assets/app-*.css` after `npm run build`. At runtime,
the CSS rule never applies and the element uses default styling (e.g. tooltip
always appears at the top instead of the requested position).

### Verification

```bash
# Check which tooltip classes are in the compiled CSS
grep -o "tooltip-[a-z]*" public/build/assets/app-*.css | sort -u
```

### Fix: Safelist via @source

Add the missing class as a literal in a Blade file, then tell Tailwind to
scan it via `@source`:

1. Add the class to `resources/views/components/tooltip-safelist.blade.php`
2. Ensure `resources/css/custom.css` has `@source` pointing to it
3. Run `npm run build`

## SMTP Connector OAuth 2.0 Backend Patterns

### Credential Encryption

SMTP credentials (password, OAuth client_secret, refresh_token, access_token)
are stored in the `settings` database table via the `Setting` model as
system-scoped records (`tenant_id = null`). Sensitive values are encrypted
with Laravel's `Crypt::encryptString()` before storage and decrypted on read.

```php
// Service layer pattern — SmtpConnectorService
private const ENCRYPTED_KEYS = [
    'smtp_password',
    'smtp_oauth_client_secret',
    'smtp_oauth_refresh_token',
    'smtp_oauth_access_token',
];

// On write: encrypt sensitive keys
if (in_array($key, self::ENCRYPTED_KEYS, true)) {
    $value = Crypt::encryptString($value);
}

// On read: decrypt, with graceful fallback for corrupted data
try {
    $value = Crypt::decryptString($value);
} catch (\Throwable) {
    $value = '';
}
```

### OAuth Authorization Code Flow (No Third-Party Dependencies)

The SMTP connector implements OAuth 2.0 directly via Laravel's `Http` facade —
no Passport, Socialite, or Google API client required:

1. **Build auth URL** — assemble query params (client_id, redirect_uri, scope,
   response_type=code, state) and redirect the admin
2. **Exchange code** — `Http::asForm()->post($tokenEndpoint, [...])` to swap
   the authorization code for tokens
3. **Refresh token** — same POST to token endpoint with `grant_type=refresh_token`
4. **Use token** — pass the access token to Symfony Mailer's `XOAuth2Authenticator`
   via a custom `EsmtpTransport`

### XOAUTH2 SMTP Transport

```php
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

$transport = new EsmtpTransport(
    host: $settings['smtp_host'],
    port: (int) $settings['smtp_port'],
    tls: $settings['smtp_encryption'] === 'tls' ? true : false,
    authenticators: [new XOAuth2Authenticator()],
);
$transport->setUsername($settings['smtp_username'] ?? '');
$transport->setPassword($accessToken);
```

Note: `EsmtpTransport` uses positional constructor parameters, not named
parameters for all arguments. The third parameter is `?bool $tls`, not
`?string $encryption`.

### Configuration Status Caching Pitfall

Do NOT cache `isConfigured()` results. A cached `true` persists even after
the database settings are deleted (e.g. during test teardown), causing the
UI to show "Configured" when nothing is saved. Read directly from the
database each time — the query is trivial.

## Module Development Quick Reference

### ModuleServiceProvider Base Class

All module service providers MUST extend `App\Support\ModuleServiceProvider`
(not `Illuminate\Support\ServiceProvider`). The base class auto-registers
views, migrations, routes, Livewire components, menu items, and permissions.

Required overrides:
- `moduleName(): string` — kebab-case name (e.g. `'bridges'`, `'smtp-connector'`)
- `moduleNamespace(): string` — PHP namespace (e.g. `'Modules\Bridges'`)

Optional overrides:
- `menuItems(): array` — sidebar menu entries
- `permissions(): array` — permission definitions as `[key => description]`
- `hasTranslations(): bool` — return `true` if module has `resources/lang/`

### Route Auto-Registration

Modules with standard Livewire component naming (`{PascalModule}List`,
`{PascalModule}Edit`) get routes auto-registered under `/panel/` — no
`routes/web.php` file needed. Create a `routes/web.php` only when:
- The module uses non-standard component names
- The module needs public/non-authenticated endpoints
- The module needs additional routes beyond index/create/edit

### Setting Model Pattern

System-scoped settings (shared across all tenants) use `tenant_id = null`:

```php
// Read
$setting = Setting::system()->where('key', 'my_key')->first();

// Write (upsert)
Setting::updateOrCreate(
    ['key' => 'my_key', 'tenant_id' => null],
    ['value' => 'my_value'],
);

// Delete
Setting::system()->where('key', 'my_key')->delete();
```

## Database Safety Guards

TallPBX protects the production database from destructive migrations and
commands. Understand these guards before touching migrations, test tooling, or
anything that runs `migrate:fresh`:

- `PrimaryDatabaseSafety::shouldProhibitDestructiveCommands()` gates
  `DB::prohibitDestructiveCommands()` in `AppServiceProvider`. A connection is
  protected when its database name equals `app.primary_database` (which falls
  back to `DB_DATABASE`). An in-memory database (`:memory:`) is never the
  protected primary.
- `SafeMigrator` + `MigrationSafetyGuard` block pending migrations that contain
  destructive operations (drop/delete/truncate) in `up()` and reject destructive
  SQL during a protected migration run. Prefer additive forward migrations over
  destructive ones; never delete a destructive migration after it shipped.
- `TestDatabaseSafety` forces every test process onto in-memory SQLite and
  refuses any other test database.

### Symptom: tests fail with `no such table: tenants`

phpunit forces `DB_DATABASE=:memory:`, which made the in-memory test database
match `app.primary_database`, so `migrate:fresh` was blocked with "This command
is prohibited from running in this environment" and the test schema was never
built. Every DB-touching test then failed with `no such table: X`. The fix:
`PrimaryDatabaseSafety::shouldProtectConnection()` returns `false` for
`:memory:` databases.

If this ever regresses: check `shouldProtectConnection()` against the sqlite
connection and verify `app.primary_database` does not match the in-memory
database name.

## Common Pitfalls

| Pitfall | Fix |
|---|---|
| `tooltip-right` not working | Add to `tooltip-safelist.blade.php` + `npm run build` |
| Tooltip cut off at page top | Use `align="start"` with `position="right"` |
| Radio button `wire:model` doesn't fire | Add `@click` on parent label with `$wire.property = value` |
| `wire:navigate` resets scroll on short pages | Add `wire:navigate:scroll` to `<main>` element |
| `isConfigured()` shows stale status | Don't cache the result; read DB directly |
| `EsmtpTransport` unknown parameter `encryption` | Use third parameter `tls` (bool), not named `encryption` |
| `Symfony\Mime\Email` from() name rejected | Use `new Address($email, $name)` instead of two string args |
| `admin.can` middleware returns 403 in tests | Create test admin with proper group+permission assignment |
| `http_build_query` encodes spaces as `+` | Assert `rawurlencode($scope).'+offline_access'` not `%20` |
| Module provider double-load in parallel tests | Run tests sequentially with `--filter` or accept as known sandbox issue |
| Tests fail with `no such table: tenants` everywhere | Schema never built: `PrimaryDatabaseSafety` must not treat `:memory:` as the protected primary (see Database Safety Guards) |
| `migrate:fresh` says "prohibited from running in this environment" | The active DB name equals `app.primary_database` — keep the `:memory:` guard in `shouldProtectConnection()` |

## Auth & Tenancy

### Core Model

TallPBX uses one unified panel layout and route tree with separate session guards:
- `admin` guard → `App\Models\Admin` — system-wide operators
- `web` guard → `App\Models\User` — tenant users, scoped to assigned tenants

Tenant users may belong to one or more tenants and must be scoped to the
active tenant unless a feature explicitly operates across only their assigned
tenants. Users with multiple tenants should be able to switch tenant context
from the user area. Superadmin-only operations must stay behind the admin
guard and explicit permissions.

### Key Files

Before editing auth or tenancy behavior, inspect these files:
- Guards and providers: `config/auth.php`
- Unified panel middleware: `app/Http/Middleware/AuthPanelMiddleware.php`
- Tenant scoping middleware: `app/Http/Middleware/ScopeTenant.php`
- Active context service: `app/Services/TenantContext.php`
- Admin and user permission models: `app/Models/Admin.php`, `app/Models/User.php`
- Panel layout and switcher UI: `resources/views/layouts/app.blade.php`
- Panel routes: `routes/web.php` and module route files under `app-modules/*/routes/`
- Permission sync and menu registration: `app/Providers/AppServiceProvider.php`, `app/Services/MenuService.php`, module service providers
- Existing isolation tests: `tests/Feature/CrossTenantIsolationTest.php`, `tests/Feature/Middleware/ScopeTenantTest.php`, `tests/Feature/Services/PermissionSyncTest.php`, and auth tests under `tests/Feature/Auth/`

### Multi-Tenant Security & Livewire Action Guards

TallPBX enforces multi-tenant defense-in-depth across multiple tiers:

1. **Model Mutation Guard (`TenantMutationGuard`)**:
   - Integrated into `App\Traits\BelongsToTenant` across `creating`, `updating`, and `deleting` hooks.
   - For `web` guard sessions (and impersonating admins), any write targeting a model whose `tenant_id` does not match `TenantManager::getTenantId()` fails closed with `HTTP 403 Cross-tenant access denied.`.
   - Admin sessions (when not impersonating) and non-web background workers (queues, ESL listeners, CLI, XML handlers) are exempt.

2. **Network-Layer Livewire Action Gating (`EnforcePanelLivewireActionPermissions`)**:
   - Registered on the Livewire update endpoint (`/livewire/update`) via `Livewire::setUpdateRoute`.
   - Intercepts signed snapshot calls before actions execute: `save` on edit components requires module edit or create permissions, `delete*` actions require delete permissions, and other actions require view permissions.
   - Resolves component class names, scopes tenant context via `ScopeTenant` conventions, and rejects unauthorized mutations or unauthenticated calls.

3. **Edit Form Mount Ownership (`assertCanAccessTenantRecord()`)**:
   - `BaseEditComponent::assertCanAccessTenantRecord(Model $record)` verifies record ownership on mount and save whenever queries bypass global scopes.
   - Guarded across all module edit components and continuously verified by `tests/Feature/EditFormTenantGuardRolloutTest`, a self-discovering test that fails if any module edit component loads foreign-tenant records without asserting access.

### Non-Negotiable Invariants

- **Fail closed** when a tenant user has no enabled tenant context.
- **Never trust tenant IDs** from requests, routes, sessions, Livewire public properties, or query strings until checked against the authenticated user's enabled tenant memberships.
- **Keep permission resolution tenant-aware.** Tenant group permissions must be evaluated for the active tenant. Tenant users must not receive `admin.*` permissions even if malformed database state attaches them.
- **Do not auto-grant every permission** to every existing group during normal application boot. Permission definition sync is safe; permission assignment changes must be explicit and test-covered.
- **Treat impersonation as tenant-user mode** for panel scoping. Keep a narrow recovery path so an admin can stop impersonation even if the impersonated user has no enabled tenant.
- **Do not use Livewire component state alone as an authorization boundary.** Authorize on the server in requests, middleware, actions, services, and queries.
- **Use full redirects for guard/context changes** from persistent layouts when needed; persistent Livewire layout components plus `wire:navigate` can leave stale or malformed DOM/state after an auth or tenant-context transition.

### Implementation Guidance

- Prefer Form Requests for tenant-switch and auth-sensitive validation. Put membership authorization in `authorize()` and resolve the tenant again through the membership-scoped query before mutating session context.
- Keep controllers thin. Put context mutation into `TenantContext` or a focused service.
- Preserve the separate `admin` and `web` guards. Do not merge them into a single model or guard.
- When a user belongs to multiple tenants, show a tenant switcher in the user area. When the user has one tenant, select it automatically. When no enabled tenant exists, return `403`.
- When a tenant domain is assigned, logging in on that host should set or constrain tenant context. Direct IP login should remain configurable.
- Google SSO is system-wide for login convenience. Leave LDAP for future work unless explicitly requested.
- Seed demo tenants/users only for explicit demo installs.
- For tenant switching from the persistent unified panel layout, prefer a normal server-rendered form with CSRF and a full redirect over an embedded Livewire component.

### Testing Guidance

Use Pest and test the security boundary directly:
- Authorized tenant switch succeeds and updates context.
- Forged tenant switch to an unassigned tenant returns `403` and leaves context unchanged.
- Disabled tenant membership cannot be selected.
- Admin-only sessions cannot use tenant-user switch routes.
- Tenant users cannot see records, users, or tenants outside their active or assigned tenants.
- Permission cache keys vary by active tenant.
- Impersonated sessions stay tenant-scoped, and stopping impersonation remains possible.
- Revoked permissions stay revoked after boot/provider sync.

Run the narrow affected test files first, then `php artisan app:test --smoke` when shared middleware, layout, permission, or tenant context behavior changes. Always run `php artisan optimize:clear` after code changes.

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

