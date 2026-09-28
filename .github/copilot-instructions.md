# GitHub Copilot Instructions for TallPBX

## Agent Documentation Source of Truth
Consult [`AGENTS.md`](../AGENTS.md) and [`.agents/skills/`](../.agents/skills/) (especially `tallpbx-custom`, `laravel-best-practices`, `freeswitch-development`) for authoritative project rules and patterns.

## Versioning & Git Release Strategy (Laravel Model)
- TallPBX follows the **[Laravel framework versioned-branch model](https://laravel.com/docs/releases#versioning-scheme)** (e.g. `1.0`, `1.1`, `2.0`) and adheres to **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`).
- **No `main` or `master` Branch**: Active development occurs directly on the current major release series branch (`2.0`). Confirm with `git branch --show-current`. Never attempt to merge into or reference `main`.
- **No Backwards Compatibility for Unreleased Series Branches**: Because major series branch `2.0` is currently unreleased (no `v2.0.0` tag exists yet) and is explicitly not backwards compatible with the 1.x series, DO NOT introduce or retain backward-compatibility fallbacks, deprecated aliases, transitional shims, or migration bridges for 1.x or unreleased 2.0 iterations. Write all configuration, schema, routes, services, and tests directly in their modern canonical form.
- **Tagging Best Practices**: Never tag individual commits or task completions. Tags (`v1.0.0`, `v1.1.0`, `v2.0.0`, etc.) are reserved strictly for official, finished production releases on series branch heads.
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
