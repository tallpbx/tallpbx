<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

## Laravel Boost MCP Tools Priority
Laravel Boost is active as an MCP server (`php artisan boost:mcp`). Always prefer Boost tools over shell commands or manual file reads:
- Use `application-info` to inspect PHP version, Laravel framework version, database engine, and installed package versions.
- Use `database-schema` to inspect table structure, columns, and foreign keys before writing migrations or Eloquent models.
- Use `database-query` for read-only database queries instead of running SQL in Tinker.
- Use `search-docs` to check official Laravel, Livewire, and Tailwind v4 documentation before writing version-sensitive APIs.
- Use `last-error` and `read-log-entries` to inspect recent application exceptions and stack traces instead of parsing log files.
- Use `browser-logs` to inspect browser console diagnostics during UI testing.

Before relying on a package's API, confirm its installed version:
- Run `application-info` via Laravel Boost MCP, or `composer show --direct` for PHP dependencies.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Git Workflow & Versioning Strategy (Laravel Model)

- Consult `AGENTS.md` and `.agents/skills/tallpbx-custom/SKILL.md` as the authoritative source of truth for repository rules and patterns.
- TallPBX follows the **[Laravel framework versioned-branch model](https://laravel.com/docs/releases#versioning-scheme)** (e.g. `1.1`, `2.0`, `3.x`, `4.x`, `5.x`) and **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`).
- There is NO `main` or `master` branch. All active development takes place directly on the `5.x` series branch. Always confirm the active branch before committing: `git branch --show-current`.
- **No Backwards Compatibility for Unreleased Series Branches**: Major series branch `5.x` is explicitly not backwards compatible with the `4.x`/`3.x`/`2.x`/`1.x` series and requires a fresh install rather than an in-place upgrade from earlier major lines (such as `4.x`). Do not introduce or retain backward-compatibility fallbacks, deprecated aliases, transitional shims, or migration bridges for `4.x` or earlier. Write all configuration, schema, routes, services, and tests directly in their modern canonical form. Backwards compatibility guarantees apply strictly to maintenance releases within the `5.x` series.
- **Never tag individual commits or task completions.** Tags (`v1.0.0`, `v1.1.0`, `v2.0.0`, `v2.1.0`, `v2.1.1`, `v3.0.0`, `v4.0.0`, etc.) are reserved strictly for official, finished production releases.
- **Cache Clearing**: After ANY code change (views, config, routes, events, components), ALWAYS run `tpbx optimize:clear`.

## Mandatory Verification Order
Before claiming a feature is complete:
1. `tpbx optimize:clear`
2. `./vendor/bin/pint --dirty` (PHP files only)
3. `./vendor/bin/pest --parallel path/to/SpecificTest.php` (targeted tests for routine work)
4. `tpbx test --compact --parallel` and `./vendor/bin/pest --parallel tests/Browser` (at most once before commit, and only for significant multi-subsystem changes)
5. `tpbx route:list --name=<feature-name>` (when routes change)

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Domain-Driven Modular Monolith Architecture

- **Modular Monolith Foundation**: TallPBX 5.x is explicitly designed as a **Domain-Driven Modular Monolith**. All 60 built-in telephony and administration features are partitioned into cohesive bounded contexts under `app-modules/{domain}/`.
- **Atomic Deployment**: Built-in modules are part of the core deployable unit and update atomically via Git. They are NOT independent microservices or separate packages.
- **Direct PSR-4 Autoloading**: All built-in modules are mapped directly in root `composer.json` via PSR-4 (`Modules\{Domain}\` => `app-modules/{domain}/src/`). Zero path repositories are used.
- **No Deletion of Core Modules**: Built-in modules in `app-modules/` must **NEVER** be uninstalled or deleted from disk. Disabling a module is strictly logical (`enabled = false` in registry, suppressing route and provider registration).
- **Extensibility Boundary (`custom-modules/`)**: Third-party community modules reside in the untracked, git-ignored `custom-modules/` directory or in standard Composer packages under `vendor/`. External modules are guarded by pre-flight collision checks.
- **Domain Boundaries & Decoupling**: All future design decisions, code changes, and refactors MUST maintain high cohesion within domains, low coupling across domains, and route cross-domain communication through events, contracts, or service layer abstractions. Avoid deep coupling to another module's internal schema.
- **Autonomous System Utilities**: System administration tools like Backups are self-contained modules under Core Administration (Superadmin-centric).
- **Custom Module Scaffolding**: `tpbx make:module <name>` scaffolds exclusively into `custom-modules/{name}/` (no `--custom` flag, no root `composer.json` edits). Core modules in `app-modules/` are created and maintained directly in code by AI agents and developers.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Standardized Action Progress Animation Architecture (Three Patterns)

All asynchronous or mutating actions triggered via Livewire must display a standardized in-progress loading animation (DaisyUI spinner and disabled state). Never use raw CSS animations or `animate-spin`. Full specifications live in `AGENTS.md` and `.agents/skills/tallpbx-custom/SKILL.md`:
- **Pattern 1 (Top Alert Banner Actions)**: Double-click lockout (`wire:loading.attr="disabled"`), target scoping (`wire:target="<action>"`), state swapping (`wire:loading.remove` / `wire:loading`), and DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`) with present-continuous copy.
- **Pattern 2 (Form & Panel Action/Submit Buttons)**: Button disablement (`wire:loading.attr="disabled" wire:target="<action>"`), DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`), and icon/label swapping.
- **Pattern 3 (Table Row Operations & Icon-Button Loading)**: Row dimming (`<tr wire:key="..." wire:loading.class="opacity-40 pointer-events-none" wire:target="<action>(<id>)">`), automatic icon-to-spinner swapping via `<x-icon-button>` (or explicit button disablement and spinner for text/badge row buttons).

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

- If you have modified any PHP files, you must run `./vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Never scan the entire repository during routine work. Run `./vendor/bin/pint --dirty` to format uncommitted PHP edits, or `./vendor/bin/pint --dirty --test` to verify formatting without modifying files. Never run unscoped `vendor/bin/pint --test` or `vendor/bin/pint --format agent`.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `tpbx make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `tpbx test --compact` or `./vendor/bin/pest`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, run `tpbx test --compact --parallel` (or `tpbx app:test`).

</laravel-boost-guidelines>
