---
name: livewire-development
description: "Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, wire:sort, or islands, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, drag-and-drop, loading states (standardized three action progress animation patterns: alert banner actions, form/tool action buttons, and table row dimming with icon-button auto-spinners), migrating from Livewire 3 to 4, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire."
license: MIT
metadata:
  author: laravel
---

# Livewire Development

## Documentation

Use `search-docs` for detailed Livewire 4 patterns and documentation.

## Basic Usage

### Creating Components

```bash
# Single-file component (SFC - default in v4)
# Creates: resources/views/components/⚡create-post.blade.php
php artisan make:livewire create-post

# Page component (SFC - Full Page in v4)
# Creates: resources/views/pages/⚡create-post.blade.php
php artisan make:livewire pages::create-post

# Multi-file component (MFC)
# Creates: resources/views/components/⚡create-post/create-post.php
#          resources/views/components/⚡create-post/create-post.blade.php
php artisan make:livewire create-post --mfc

# Class-based component (v3 style)
# Creates: app/Livewire/CreatePost.php AND resources/views/livewire/create-post.blade.php
php artisan make:livewire create-post --class

# With namespace
php artisan make:livewire Posts/CreatePost
```

### Converting Between Formats

Use `php artisan livewire:convert create-post` to convert between single-file, multi-file, and class-based formats.

### Choosing a Component Format

> **Always follow the project's existing conventions first.** Before creating any component, inspect the project's existing Livewire components to determine the established format (SFC, MFC, or class-based) and directory structure. Check `app/Livewire/`, `resources/views/components/`, and `resources/views/livewire/` for existing components. If the project already uses a consistent format, **use that same format** — even if it differs from the Livewire v4 defaults below. Only fall back to the v4 defaults (SFC in `resources/views/components/`) when no existing convention is established.

Also check `config/livewire.php` for `make_command.type`, `make_command.emoji`, `component_locations`, and `component_namespaces` overrides, which change the default format and where files are stored.

### Component Format Reference

| Format | Flag | Class Path | View Path |
|--------|------|------------|-----------|
| Single-file (SFC) | default | — | `resources/views/components/⚡create-post.blade.php` (PHP + Blade in one file) |
| Full Page SFC | `pages::name` | — | `resources/views/pages/⚡create-post.blade.php` |
| Multi-file (MFC) | `--mfc` | `resources/views/components/⚡create-post/create-post.php` | `resources/views/components/⚡create-post/create-post.blade.php` |
| Class-based | `--class` | `app/Livewire/CreatePost.php` | `resources/views/livewire/create-post.blade.php` |
| View-based | default (Blade-only) | — | `resources/views/components/⚡create-post.blade.php` (Blade-only with functional state) |

> **Important:** The ⚡ prefix shown above is the **default** behavior in Livewire v4 — it is **configurable**. Check `config/livewire.php` for the `make_command.emoji` setting. When `true` (default), always include the ⚡ prefix in filenames you create. When `false`, omit the ⚡ prefix from all paths above.

Namespaced components map to subdirectories: `make:livewire Posts/CreatePost` creates `resources/views/components/posts/⚡create-post.blade.php` (single-file by default). Use `make:livewire Posts/CreatePost --mfc` for multi-file output at `resources/views/components/posts/⚡create-post/create-post.php` and `resources/views/components/posts/⚡create-post/create-post.blade.php`.

### Single-File Component Example

<!-- Single-File Component Example -->
```php
<?php
use Livewire\Component;

new class extends Component {
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
};
?>

<div>
    <button wire:click="increment">Count: @{{ $count }}</button>
</div>
```

## Livewire 4 Specifics

### Key Changes From Livewire 3

These things changed in Livewire 4, but may not have been updated in this application. Verify this application's setup to ensure you follow existing conventions.

- Use `Route::livewire()` for full-page components (e.g., `Route::livewire('/posts/create', CreatePost::class)`); config keys renamed: `layout` → `component_layout`, `lazy_placeholder` → `component_placeholder`.
- `wire:model` now ignores child events by default (use `wire:model.deep` for old behavior); `wire:scroll` renamed to `wire:navigate:scroll`.
- Component tags must be properly closed; `wire:transition` now uses View Transitions API (modifiers removed).
- JavaScript: `$wire.$js('name', fn)` → `$wire.$js.name = fn`; `commit`/`request` hooks → `interceptMessage()`/`interceptRequest()`.

### New Features

- Component formats: single-file (SFC), multi-file (MFC), view-based components.
- Islands (`@island`) for isolated updates; async actions (`wire:click.async`, `#[Async]`) for parallel execution.
- Deferred/bundled loading: `defer`, `lazy.bundle` for optimized component loading.

| Feature | Usage | Purpose |
|---------|-------|---------|
| Islands | `@island(name: 'stats')` | Isolated update regions |
| Async | `wire:click.async` or `#[Async]` | Non-blocking actions |
| Deferred | `defer` attribute | Load after page render |
| Bundled | `lazy.bundle` | Load multiple together |

### New Directives

- `wire:sort`, `wire:intersect`, `wire:ref`, `.renderless`, `.preserve-scroll` are available for use.
- `data-loading` attribute automatically added to elements triggering network requests.

| Directive | Purpose |
|-----------|---------|
| `wire:sort` | Drag-and-drop sorting |
| `wire:intersect` | Viewport intersection detection |
| `wire:ref` | Element references for JS |
| `.renderless` | Component without rendering |
| `.preserve-scroll` | Preserve scroll position |

## Best Practices

- Always use `wire:key` in loops
- Use `wire:loading` for loading states
- Use `wire:model.live` for live updates; `wire:model` is deferred by default
- Validate and authorize in actions (treat like HTTP requests)

## Standardized Action Progress Animation Architecture (Three Patterns)

All asynchronous or mutating actions triggered via Livewire must display a standardized in-progress loading animation to prevent double-clicks, provide instant tactile feedback, and maintain uniform visual polish across the UI. Follow the three established patterns (detailed specifications and templates in `tallpbx-custom` skill):

### Pattern 1: Top Notification & Alert Banner Action Buttons
Used for actionable banners at the top of pages or cards (e.g. Lockout warning "Protect My IP", Unapplied Changes "Apply Firewall Changes", "Enable Normal Mode"):
- Lock out clicks: `wire:loading.attr="disabled" wire:target="<action>"`
- Scope the target: explicitly use `wire:target="<action>"` on both loading and removal directives
- State swapping: wrap idle state with `<span wire:loading.remove wire:target="<action>">` and active state with `<span wire:loading wire:target="<action>" class="inline-flex items-center gap-1.5">`
- Use DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`) with localized present-continuous verb (e.g., "Applying Changes...")

### Pattern 2: Form & Panel Action/Submit Buttons (Add / Save / Tool Triggers)
Used for forms, quick-add toolbars, card header actions, and tool buttons (e.g. "Add to Whitelist", "Save Settings", "Fetch", "Retry All", "Send Test", "Refresh"):
- Button disablement: `wire:loading.attr="disabled" wire:target="<action>"`
- Standard DaisyUI spinner: `<span class="loading loading-spinner loading-xs"></span>` (never raw CSS animations or `animate-spin`)
- Icon/label swapping: hide idle icon/text with `wire:loading.remove wire:target="<action>"` and show the spinner with `wire:loading wire:target="<action>"`

### Pattern 3: Table Row Operations & Icon-Button Loading (Row Dimming Standard)
Used for actions triggered directly from table rows (e.g. deleting an entry, promoting an IP, unbanning, ending a session):
- Row dimming & pointer locking: `<tr wire:key="..." wire:loading.class="opacity-40 pointer-events-none" wire:target="<action>(<id>)">`
- Automatic icon-to-spinner swapping: use `<x-icon-button wire:click="<action>(<id>)">` which automatically infers the target, disables the button during network roundtrips, and replaces the static icon with `<span class="loading loading-spinner loading-xs"></span>`
- Plain text / badge row buttons: apply `wire:loading.attr="disabled" wire:target="<action>(<id>)"` and swap content with `<span wire:loading wire:target="<action>(<id>)" class="loading loading-spinner loading-xs"></span>`

## Configuration

- `smart_wire_keys` defaults to `true`; new configs: `component_locations`, `component_namespaces`, `make_command`, `csp_safe`.

## Alpine & JavaScript

- `wire:transition` uses browser View Transitions API; `$errors` and `$intercept` magic properties available.
- Non-blocking `wire:poll` and parallel `wire:model.live` updates improve performance.

For interceptors and hooks, see [reference/javascript-hooks.md](reference/javascript-hooks.md).

## Testing

<!-- Testing Example -->
```php
Livewire::test(Counter::class)
    ->assertSet('count', 0)
    ->call('increment')
    ->assertSet('count', 1);
```

## Verification

1. Browser console: Check for JS errors
2. Network tab: Verify Livewire requests return 200
3. Ensure `wire:key` on all `@foreach` loops

## Common Pitfalls

- Missing `wire:key` in loops → unexpected re-rendering
- Expecting `wire:model` real-time → use `wire:model.live`
- Unclosed component tags → syntax errors in v4
- Using deprecated config keys or JS hooks
- Including Alpine.js separately (already bundled in Livewire 4)
