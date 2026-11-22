# Developer Guide

How to set up TallPBX for development, where the boundaries are, and which
commands prove a change is safe. The authoritative technical reference is
[System Architecture](architecture.md); this guide is the entry point and the
map.

> **Agent-only material is not documented here.** Files under `.agents/skills/`
> are instructions for AI agents working in this repository, not a developer
> manual. Read the application, the tests, and `architecture.md` instead.

## 1. Reproducible setup

```bash
git clone <repository> /var/www/tallpbx
cd /var/www/tallpbx
composer install
cp .env.example .env
php artisan key:generate
tpbx migrate
tpbx db:seed --class=AdminSeeder
tpbx module:sync --only-local
npm ci && npm run build
```

Tests never touch the appliance's MariaDB or Redis. `phpunit.xml` forces an
in-memory SQLite database, dedicated Redis databases (12–15), a test key prefix,
and a separate compiled-view directory; `tests/Feature/TestEnvironmentSafetyTest.php`
is the tripwire that keeps those forced settings honest.

```bash
tpbx test --compact --parallel    # the normal suite
./vendor/bin/pest path/to/Test.php
```

The CI workflow (`.github/workflows/tests.yml`) is a manual parity check, not the
release gate. Releases follow [release-checklist.md](release-checklist.md).

## 2. Module contracts

The application is a modular monolith under `app-modules/<name>/`.

| Contract | Rule |
| :--- | :--- |
| Provider (`src/Providers/ModuleServiceProvider.php`) | Extends `App\Support\ModuleServiceProvider` and overrides `moduleName()` and `moduleNamespace()` |
| Manifest | `module.json` declares `name`, `version`, `category`, `protected`, and `requirements.modules` for every cross-module class reference |
| Routes | Every built-in module ships `routes/web.php` (`web`, `auth.panel`, and a throttle on panel routes; core modules whose routes live in root `routes/web.php` keep a stub file); auto-registration is retired |
| Views | Resolved as `{module-dir}::{kebab-view}` from the Livewire class name |
| Translations | When the module defines translations, `lang/{en,es,fr}/admin.php` with module-prefixed keys, merged into the global `admin` group, never `module::admin.*` |
| Tests | `app-modules/<name>/tests/*Test.php`, discovered by the `phpunit.xml` glob; cross-module integration tests live in root `tests/` |
| Navigation | `module.json` `category` must equal the sidebar `parent` in the provider |

`tpbx make:module <name>` scaffolds a **custom** module into
`custom-modules/<name>/`. Built-in modules are written directly under
`app-modules/` by developers; the scaffold is not the built-in convention.

## 3. Tenancy and authorization

- **Two guards.** Administrators authenticate on the `admin` guard; tenant users
  on `web`. Both reach the same panel routes.
- **One actor resolver.** `App\Support\PanelActor::resolve()` is the only place
  that decides who is acting. It returns `null` for a disabled account, and for
  an impersonation it validates both the impersonated user and the supervising
  administrator.
- **Tenant scope.** `BelongsToTenant` filters every tenant-owned model by the
  active tenant. A missing tenant context fails closed (`whereRaw('1 = 0')`), so
  a query without context returns nothing rather than everything.
- **Tenant context.** `TenantManager` holds the active tenant; `ScopeTenant`
  establishes it from the session/membership on page requests. In tests, set it
  explicitly (`app(TenantManager::class)->setTenantId(...)`) or use the
  `tenantUser()` / `grantTenantUserPermissions()` helpers in `tests/Pest.php`.
- **Edit forms.** `BaseEditComponent` derives `tenantId` from the authorized
  context for non-admin actors in `loadTenants()`, `hydrate()`, and
  `updated('tenantId')`. Never trust a client-supplied tenant id in a write path.
- **Permissions.** Route middleware `admin.can:<permission>`; Livewire actions
  are gated by `EnforcePanelLivewireActionPermissions`. Permission names are
  owned by the module's `permissions()` map and synced by `AdminSeeder`.

Panel-route tests must bootstrap permissions with `module:sync --only-local` and
`AdminSeeder`, then attach the `Super Administrators` group. Do not hand-build a
permission group.

## 4. Telephony boundaries

- FreeSWITCH reaches the application only through `mod_xml_curl`
  (`/api/v1/xml-handler`), scoped by `DialplanXmlBuilder`, `DirectoryXmlBuilder`,
  and `SofiaConfigXmlBuilder`.
- A CDR's tenant comes from call-time identity: the channel-local `tenant_id`
  the dialplan stamps, or the tenant context the channel executed. A domain
  mapping is diagnostic context only; see [Architecture](architecture.md) and
  `CdrPayloadParser`.
- Direct bridge legs (`sofia_contact(...)`, `sofia/external/...`) carry an
  explicit `[tenant_id=<id>]` block; never a single global block on a
  multi-tenant bridge.

## 5. Verification commands

Run these commands from the TallPBX checkout (`/var/www/tallpbx` on the appliance).

```bash
tpbx optimize:clear                    # after any code or template change
./vendor/bin/pint --dirty              # format changed PHP
./vendor/bin/pint --dirty --test       # verify formatting
./vendor/bin/pest path/to/Test.php     # targeted
tpbx test --compact --parallel         # full suite (once, before a significant commit)
./vendor/bin/pest tests/Browser        # only for rendered-journey overhauls
tpbx route:list --name=<feature>       # confirm a new route exists
```

## 6. Where not to look

- `.agents/skills/*` — agent instructions, not application documentation.
- `docs/changelog/*` — archived release history for older majors.
- `storage/app/load-tests/` — disposable load-test scratch output.

## Related Documentation

- [System Architecture](architecture.md) — bounded contexts, request lifecycle, XML generation, firewall architecture.
- [Operations & Maintenance](operations.md) — services, firewall safety, restore and update prerequisites.
- [Release Checklist](release-checklist.md) — the evidence a release needs.
- [AGENTS.md](../AGENTS.md) — the repository's working agreement (verification gate, Pint scope, changelog rules).
