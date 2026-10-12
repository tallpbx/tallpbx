# GitHub Copilot Instructions for TallPBX

## Agent Documentation Source of Truth
Consult [`AGENTS.md`](../AGENTS.md) and [`.agents/skills/`](../.agents/skills/) (especially `tallpbx-custom`, `laravel-best-practices`, `freeswitch-development`) for authoritative project rules and patterns.

## Laravel Boost MCP Priority
Laravel Boost is active as an MCP server (`php artisan boost:mcp`). **Prefer Boost MCP tools over shell commands or manual file reads:**
- Use `application-info` to inspect PHP, Laravel, database engine, and installed package versions.
- Use `database-schema` to inspect table definitions, columns, and foreign keys before writing migrations or Eloquent models.
- Use `database-query` for read-only database queries instead of running SQL in Tinker.
- Use `search-docs` to check official Laravel, Livewire, and Tailwind v4 documentation.
- Use `last-error` and `read-log-entries` to inspect recent application exceptions and stack traces.
- Use `browser-logs` to inspect browser console diagnostics during UI testing.

## Versioning & Git Release Strategy (Laravel Model)
- TallPBX follows the **[Laravel framework versioned-branch model](https://laravel.com/docs/releases#versioning-scheme)** (e.g. `1.1`, `2.0`, `3.x`, `4.x`) and adheres to **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`).
- **No `main` or `master` Branch**: Active development occurs directly on the current major release series branch (`4.x`). Confirm with `git branch --show-current`. Never attempt to merge into or reference `main`.
- **No Backwards Compatibility for Unreleased Series Branches**: Because major series branch `4.x` is currently unreleased (no `v4.0.0` tag exists yet) and is explicitly not backwards compatible with earlier series (1.x / 2.x / 3.x), DO NOT introduce or retain backward-compatibility fallbacks, deprecated aliases, transitional shims, or migration bridges for 3.x, 2.x, or unreleased 4.x iterations. Write all configuration, schema, routes, services, and tests directly in their modern canonical form.
- **Tagging Best Practices**: Never tag individual commits or task completions. Tags (`v1.0.0`, `v1.1.0`, `v2.0.0`, `v2.1.0`, `v2.1.1`, `v3.0.0`, `v4.0.0`, etc.) are reserved strictly for official, finished production releases on series branch heads.
- **Reference**: Detailed explanations are available in [`README.md`](../README.md), [`INSTALL.md`](../INSTALL.md), and [`CHANGELOG.md`](../CHANGELOG.md).

## Domain-Driven Modular Monolith Architecture
- **Architecture Model**: TallPBX 4.x is explicitly designed as a **Domain-Driven Modular Monolith**. All 60 built-in telephony and administration features are partitioned into cohesive bounded contexts under `app-modules/{domain}/`.
- **Atomic Deployment**: Built-in modules are part of the core repository and update atomically via Git. They are NOT independent microservices or separate Composer packages.
- **Direct PSR-4 Autoloading**: All built-in modules are mapped directly in root `composer.json` via PSR-4 (`Modules\{Domain}\` => `app-modules/{domain}/src/`). Zero path repositories are used.
- **No Deletion of Core Modules**: Built-in modules in `app-modules/` are **NEVER deleted or uninstalled from disk**. Disabling is strictly logical (`enabled = false` in registry, suppressing route and provider registration).
- **Extensibility Boundary (`custom-modules/`)**: Third-party community extensions reside in the untracked, git-ignored `custom-modules/` directory or in standard Composer packages under `vendor/`, guarded by strict Pre-flight Collision Guards.
- **Domain Boundaries & Decoupling**: All future AI design decisions and code changes must maintain high cohesion within domains, low coupling across domains, and route cross-domain communication through events, contracts, or service layer abstractions. Avoid deep coupling to another module's internal schema.
- **Autonomous System Utilities**: System administration tools like Backups are self-contained modules under Core Administration (Superadmin-centric).

## Cache Clearing Rule (Mandatory)
After ANY code changes (Blade views, config, routes, events, Livewire components, compiled classes), ALWAYS run:
```bash
php artisan optimize:clear
```

## Standardized Action Progress Animation Architecture (Three Patterns)
All asynchronous or mutating actions triggered via Livewire must display a standardized in-progress loading animation (DaisyUI spinner and disabled state). Never use raw CSS animations or `animate-spin`. Full specifications in [`AGENTS.md`](../AGENTS.md) and [`.agents/skills/tallpbx-custom/SKILL.md`](../.agents/skills/tallpbx-custom/SKILL.md):
- **Pattern 1 (Top Alert Banner Actions)**: Double-click lockout (`wire:loading.attr="disabled"`), target scoping (`wire:target="<action>"`), state swapping (`wire:loading.remove` / `wire:loading`), and DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`) with present-continuous copy.
- **Pattern 2 (Form & Panel Action/Submit Buttons)**: Button disablement (`wire:loading.attr="disabled" wire:target="<action>"`), DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`), and icon/label swapping.
- **Pattern 3 (Table Row Operations & Icon-Button Loading)**: Row dimming (`<tr wire:key="..." wire:loading.class="opacity-40 pointer-events-none" wire:target="<action>(<id>)">`), automatic icon-to-spinner swapping via `<x-icon-button>` (or explicit button disablement and spinner for text/badge row buttons).


## Mandatory Verification Order
Before claiming a feature is complete:
1. `php artisan optimize:clear`
2. `php artisan test --compact --parallel`
3. `./vendor/bin/pest tests/Browser` (for UI changes)
4. `php artisan route:list --name=<feature-name>` (for new routes)
