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
- TallPBX follows the **[Laravel framework versioned-branch model](https://laravel.com/docs/releases#versioning-scheme)** (e.g. `1.1`, `2.0`, `3.x`) and adheres to **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`).
- **No `main` or `master` Branch**: Active development occurs directly on the current major release series branch (`3.x`). Confirm with `git branch --show-current`. Never attempt to merge into or reference `main`.
- **No Backwards Compatibility for Unreleased Series Branches**: Because major series branch `3.x` is currently unreleased (no `v3.0.0` tag exists yet) and is explicitly not backwards compatible with earlier series (1.x / 2.x), DO NOT introduce or retain backward-compatibility fallbacks, deprecated aliases, transitional shims, or migration bridges for 2.x or unreleased 3.x iterations. Write all configuration, schema, routes, services, and tests directly in their modern canonical form.
- **Tagging Best Practices**: Never tag individual commits or task completions. Tags (`v1.0.0`, `v1.1.0`, `v2.0.0`, `v2.1.0`, `v2.1.1`, `v3.0.0`, etc.) are reserved strictly for official, finished production releases on series branch heads.
- **Reference**: Detailed explanations are available in [`README.md`](../README.md), [`INSTALL.md`](../INSTALL.md), and [`CHANGELOG.md`](../CHANGELOG.md).

## Cache Clearing Rule (Mandatory)
After ANY code changes (Blade views, config, routes, events, Livewire components, compiled classes), ALWAYS run:
```bash
php artisan optimize:clear
```

## Mandatory Verification Order
Before claiming a feature is complete:
1. `php artisan optimize:clear`
2. `php artisan test --compact --parallel`
3. `./vendor/bin/pest tests/Browser` (for UI changes)
4. `php artisan route:list --name=<feature-name>` (for new routes)
