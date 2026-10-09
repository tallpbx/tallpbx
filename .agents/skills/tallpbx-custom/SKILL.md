---
name: tallpbx-custom
description: "Invoke when working on TallPBX-specific patterns: Laravel Boost MCP tool priority (database-schema, database-query, search-docs, application-info), versioning and release strategy (Laravel versioned series model, SemVer, unreleased 3.x modernization), the installer and resource scripts, plain-language administrative copy and prompt standards, the x-tooltip Blade component, DaisyUI 5 tooltip positioning and safelisting, the custom.css Tailwind v4 architecture, Livewire 4 + Alpine 5 reactive UI toggling, scroll preservation with wire:navigate:scroll, the TALL stack dual-event binding pattern, authentication guards (admin/web), tenant context and isolation, impersonation, group permissions, permission seeding, cross-tenant data boundaries, primary-database safety guards, changelog maintenance and release tagging conventions, UI alert and feedback patterns (inline alerts, in-dialog error states, and top-right toasts), action progress animation architecture across three patterns (top alert banners, form/tool action buttons, and table row dimming with icon-button auto-spinners), or live-firewall safety and lockout prevention (nftables change rules, the loopback local-services guard, and lockout recovery)."
license: MIT
metadata:
  author: tallpbx
---

# TallPBX Custom Frontend Patterns

## Laravel Boost MCP Tool Priority

Laravel Boost runs as an active Model Context Protocol (MCP) server for this workspace (`php artisan boost:mcp`).
Agents MUST prefer Boost tools over shell commands, Tinker scripts, or manual file grepping:
- **Introspection**: Call `application-info` on new tasks/chats to inspect PHP version, Laravel framework version, database engine, and installed package catalog.
- **Database Schema**: Use `database-schema` to inspect table definitions, columns, and foreign keys before writing migrations or Eloquent models.
- **Database Queries**: Use `database-query` for read-only database queries instead of running raw SQL in Tinker.
- **Documentation**: Use `search-docs` to check official Laravel, Livewire, Pest, and Tailwind documentation before writing version-sensitive APIs.
- **Error & Log Inspection**: Use `last-error` and `read-log-entries` to inspect recent application exceptions and stack traces instead of parsing `storage/logs/laravel.log`.
- **Browser Diagnostics**: Use `browser-logs` to inspect browser console diagnostics during frontend/Livewire testing.

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
- Standardize all interactive multiple-choice questionnaire prompts to the concise format:
  `<Field Label> [<default_number>]: `
  Option 1 must always be the recommended, production-safe baseline. Empty input (`Enter`) takes this default.
  Always format questionnaire items with:
  1. A clear section header (`verbose "<Title>"`)
  2. A 1–2 sentence plain-English explanation of why this choice matters, avoiding developer or telephony jargon.
  3. Cleanly indented numbered options starting with the anchor keywords: `  1) Clean — ... (recommended)`
  4. Prompt line using the opening keywords directly: `<Keyword 1> or <Keyword 2> [1]: ` (e.g., `Clean or demo [1]: `, `Production or development [1]: `)
  5. Case handling that accepts numbers (`1`, `2`) as well as descriptive words (`clean`, `demo`, `production`, `development`, `yes`, `no`) without failing.
- Resource scripts launched by the main installer must read exported `FSPBX_*`
  values and must not prompt. A resource script may retain an interactive
  fallback only for a documented direct standalone invocation.
- Preserve idempotency. Re-runs must reuse recorded values by default and must
  not delete application data, rotate secrets, or change installation mode
  unless an explicit user choice authorizes it.
- Validate shell syntax with `bash -n` for every changed shell script. Test a
  safe non-mutating path whenever one exists; do not invoke package, database,
  or service-changing paths merely to test parsing.

## Core Project Philosophy & Audience Duality

> **"Modern simplicity on the surface, enterprise-grade telecommunications engineering under the hood."**

TallPBX bridges two historically opposed worlds in business communications:
1. **The Non-Technical Administrator & Office Manager**:
   - Must never feel intimidated, confused, or overwhelmed.
   - All user-facing interfaces, web panel forms, CLI prompts, tooltips, and headline documentation must use plain, descriptive language (e.g., "spoken greetings", "business hours", "average/fastest/slowest call setup") with clearly designated recommended defaults (Option 1).
2. **The Telecom Architect, Developer & VoIP Veteran** (coming from FusionPBX or FreePBX®):
   - Must immediately recognize the project's sophisticated foundation: FreeSWITCH 1.11, Sofia SIP engine, ESL event sockets, dynamic `mod_xml_curl` dialplan rendering, Redis keyspace caching, atomic kernel firewalling (`nftables`), and comprehensive test suites (Pest, including Playwright browser tests).
   - Detailed statistical percentiles (`p50`, `p90`, `p95`, `p99`, `std_dev`), SIPp scenario traces, and packet flow architectures must be preserved and easily accessible.

### Concrete Rules for AI Agents:
- **Layered (Progressive) Disclosure**: Never expose raw technical complexity on the surface. Present clean, intuitive headline summaries by default, placing advanced technical depth (percentiles, packet dumps, FreeSWITCH channel variables) inside collapsible `<details>` blocks or linked deep-dive guides.
- **Context-Specific Adaptations (Avoid Repeating Canned Slogans)**: When communicating this duality across user-facing pages, adapt the phrasing naturally to the specific context rather than repeating an identical marketing line across multiple documents. (The exact phrase *"Modern simplicity on the surface, enterprise-grade telecommunications engineering under the hood."* is reserved exclusively for the Guest Landing Page).
- **Friendly Without Being Condescending**: Explain *why* a setting matters in 1–2 plain-English sentences before asking for input.
- **No Unexplained Jargon**: Never present raw abbreviations or internal mechanisms without clear context (e.g., explain that voice prompts are "spoken recordings for voicemail, call menus, and system greetings" rather than "say grammar modules").
- **Uncompromised Under-the-Hood Rigor**: Never "dumb down" the backend telephony architecture to achieve simplicity; achieve simplicity through thoughtful UI design and smart defaults while keeping the underlying telecommunications engine uncompromised.

## Target Audience & Plain-Language Standards

TallPBX is built for administrators who **may or may not be technical** telephony or Linux experts. Whether someone is an office manager, general IT technician, or telephony specialist, all user-facing interactions must feel welcoming, polished, and immediately understandable.

### Core Principles
1. **Descriptive Without Being Wordy**: Explain what a setting or option does and why it matters in 1–2 plain-English sentences. Avoid multi-paragraph terminal walls of text.
2. **Avoid Telephony & Developer Jargon**:
   - Instead of "grammar say modules", say "spoken voice recordings for voicemail, call menus, and system greetings".
   - Instead of "database seeding with demo fixtures", say "include sample demo data (extensions, call flows) to explore the system".
   - Instead of "dev dependencies, testing tools, and AI agent guidelines", say "developer tools and debugging utilities".
   - When telephony concepts are required (e.g., SIP trunks, DID, IVR, Ring Groups), accompany them with a short, real-world explanation in the UI tooltip or prompt text.
3. **Always Highlight the Recommended Default**:
   - In CLI and installer prompts: Option 1 is always the safe, recommended default for production deployments. Pressing Enter must safely select Option 1.
   - In web UI forms: Sensible production baselines must be pre-populated, with clear helper text indicating recommended settings.
4. **Actionable Guidance & Error Messages**:
   - Explain what happened in human terms and immediately provide the recovery step (for example, providing the free registration link `https://signalwire.com` when a SignalWire token is requested).

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

## Live Firewall Safety & Lockout Prevention

**Mandatory for any change that touches firewall behavior, the security helper, ruleset
generation, or the security switches.** Two production lockouts on 2026-09-29 drove these
rules; see the recovery runbooks in `docs/operations.md` and `docs/security-architecture.md` §9.3.

### The two failure modes that must never recur

1. **A pre-filter-off ruleset with a blocking default policy cuts the server's own loopback
   services.** The pre-filter pipeline is what emits `iif "lo" accept` and the
   `ct state established,related accept` fast path. Without it, loopback traffic to services
   that are *not* in the port catalog — MariaDB (`127.0.0.1:3306`) and Redis
   (`127.0.0.1:6379`) — falls through to the DROP policy. Every PHP page and Artisan command
   hangs (the apply itself dies on its post-apply database write) while SSH keeps working,
   because port 22 is a stateless catalog rule. The web panel looks dead even though HTTP is
   not port-dropped — PHP-FPM (unix socket) simply cannot reach its database.
2. **Raw `nft` mutations on a live kernel.** A probe chain left hooked to `input` with
   `policy drop` silently dropped ALL inbound traffic between two commands. Never create,
   change, or defer cleanup of kernel state on a live host.

### Absolute rules for agents

- **No raw `nft` add/insert/chain/delete/flush against a live kernel — not even as a probe,
  test, or command demonstration.** All firewall changes flow through the application
  pipeline (`security:apply`, the Security Center) or the bounded helper.
- **Verification only via** `nft -c -f <file>` dry runs (check-only, never commits),
  mocked-executor tests, or a disposable host. Any kernel-mutating test must be atomic and
  self-reverting in ONE invocation, and a chain with `policy drop` must never be left live —
  not even briefly.
- **Every firewall-affecting switch keeps both guards** before persisting anything:
  1. `LockoutGuardService::assertSafe()` — the administrator's own connection.
  2. `LockoutGuardService::assertLocalServicesSafe()` — the server's loopback services.
  The unsafe state is exactly *firewall enabled ∧ observe off ∧ blocking default policy ∧
  pre-filter off*; refuse it — never silently override the policy.
- **Refusals surface through the toast** (`notifyError()` → Pattern 3
  `<x-operational-toast>`, already rendered on the Security pages) with plain-language
  messages in en/es/fr that name the remedy ("set the default policy to Allow All first, or
  keep the pre-filters on").
- **Backstops**: `autoApplyFirewallRuleset()` and `security:apply` run
  `assertLocalServicesSafe()` (bypassed only by an explicit `--force` from the local
  console). Any new apply path must add it. Switch changes persist only after the guard
  passes — roll the stored value back when the apply is refused (see `setFirewallEnabled()`
  and `saveDefaultPolicy()` for the pattern).

### Lockout recovery (panel and `php artisan` unreachable)

Work over SSH; no PHP, database, or panel needed. Stop as soon as the panel responds —
step 1 alone usually fixes it by restoring the database/cache connection:
1. `nft insert rule inet tallpbx_filter input iif "lo" accept`
2. `nft insert rule inet tallpbx_filter input ip saddr <your-ip> accept`
3. `nft 'chain inet tallpbx_filter input { policy accept; }'`
4. `nft flush ruleset` — last resort; the server is fully open until re-applied.

After recovery, re-apply from the Security Center (or `php artisan security:apply`) so the
saved configuration and the kernel agree again. Full runbooks: `docs/operations.md` →
"Recovering from a Firewall Lockout"; `docs/security-architecture.md` §9.3; `AGENTS.md` →
"Live Firewall Safety".

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
<x-tooltip :tip="__('admin.event_rate_limits_tooltip')" align="start" position="right">
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
- **Atomic Preflight Safety**: Always run both guards — `LockoutGuardService::assertSafe()` (the administrator's connection) and `assertLocalServicesSafe()` (the server's loopback database/cache connections) — plus preflight syntax checks (`nft -c`) before applying. On failure, refuse, notify via toast alert, and preserve the active configuration (see "Live Firewall Safety & Lockout Prevention").

## UI Alert & Feedback Patterns (Three Standard Patterns)

The panel standardizes user-facing messages on three purpose-built patterns. Pick the one that matches the context — never hand-roll alert markup, and never replace one pattern project-wide as a "standardization": each page and flow deliberately uses the pattern that fits it. All three are fed by the same trait (`App\Support\Concerns\HasOperationalFeedback`, inherited via `BaseListComponent` / `BaseEditComponent`; ad-hoc Livewire components must `use` it themselves).

### Pattern 1 — Inline page alert (`<x-inline-alert>`)

In-flow alert rendered at the top of the page content (DaisyUI `alert alert-{type}`, icon, `role="alert"`, no close button), fed by `$operationalMessage` / `$operationalMessageType`.

**Use it when:** the feedback flow involves no modal, an in-flow message reads naturally for the page, or the alert describes a **persistent condition** rather than an action result (for example the "FreeSWITCH not connected" banners and the lockout warning — they use the same component with a fixed `type`).

```blade
@if ($operationalMessage !== null)
    <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
@endif
```

### Pattern 2 — In-dialog error state (`x-confirmation-modal :error`)

The confirmation dialog swaps its entire content for an error view (red icon, the message in an inline alert, a Close button) when the `:error` prop is set.

**Use it when:** a failure must be shown **inside an open dialog** because it belongs to the dialog's context — typically server-side re-check failures such as typed-confirmation mismatches or concurrent-edit guards, where the flow cannot continue.

### Pattern 3 — Fixed toast (`<x-operational-toast>`)

DaisyUI toast layer fixed to the top-right at `z-[9999]` — above any open dialog and independent of page scroll. Renders `role="alert"`, includes a × button that clears the message server-side via `HasOperationalFeedback::dismissFeedback()` (no page refresh needed), and also renders cross-redirect session flashes (`status` / `error`) through the same box.

**Use it when:** feedback must be visible regardless of modal state or scroll position — especially any flow where a failed action **closes its modal** (mirroring the success path) so the page and the toast are both visible, and on pages where the toast already is the established feedback channel (Security Command Center).

```blade
<x-operational-toast :message="$operationalMessage" :type="$operationalMessageType" />
```

### Notes

- Messages persist until the next action replaces them, the × is clicked, or `dismissFeedback()` runs — there is no auto-hide timer because DaisyUI ships CSS only.
- Both Pattern 1 and Pattern 3 render `role="alert"` for accessibility.

## Standardized Action Progress Animation Architecture (Three Patterns)

All asynchronous or mutating actions triggered via Livewire buttons must provide immediate visual feedback using the standardized DaisyUI spinner animation and disabled state. This prevents accidental double-clicks, reassures users during network/server roundtrips, and ensures uniform visual polish across the administrative panel.

TallPBX standardizes **three distinct patterns** across all modules:

---

### Pattern 1: Top Notification & Alert Banner Action Buttons
Used for actionable banners at the top of pages or cards (e.g. Lockout warning "Protect My IP", Unapplied Changes "Apply Firewall Changes", Observe Mode banner "Enable Normal Mode", Service Down notices).

#### Template
```blade
<div class="alert alert-warning shadow-sm border border-warning/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4" role="alert">
    <div class="flex items-center gap-3">
        <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning shrink-0" />
        <span class="text-sm font-medium">{{ $bannerNotice }}</span>
    </div>
    <button wire:click="whitelistCurrentIp"
            wire:loading.attr="disabled"
            wire:target="whitelistCurrentIp"
            type="button"
            class="btn btn-warning btn-sm whitespace-nowrap">
        <span wire:loading.remove wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1.5">
            <x-heroicon-o-shield-check class="w-4 h-4" />
            <span>{{ __('admin.security_protect_my_ip') }}</span>
        </span>
        <span wire:loading wire:target="whitelistCurrentIp" class="inline-flex items-center gap-1.5">
            <span class="loading loading-spinner loading-xs"></span>
            <span>{{ __('admin.security_protecting_my_ip') }}</span>
        </span>
    </button>
</div>
```

---

### Pattern 2: Form & Panel Action/Submit Buttons (Add / Save / Tool Triggers)
Used for forms, quick-add bars, toolbar buttons, and card actions (e.g. "Add to Whitelist", "Save Settings", "Fetch", "Retry All", "Send Test", "Sync Now", "Refresh").

#### Template
```blade
<button wire:click="addWhitelistIp"
        wire:loading.attr="disabled"
        wire:target="addWhitelistIp"
        type="button"
        class="btn btn-primary btn-sm">
    <span wire:loading.remove wire:target="addWhitelistIp" class="inline-flex items-center gap-1.5">
        <x-heroicon-o-plus class="w-4 h-4" />
        <span>{{ __('admin.security_whitelist_add_btn') }}</span>
    </span>
    <span wire:loading wire:target="addWhitelistIp" class="inline-flex items-center gap-1.5">
        <span class="loading loading-spinner loading-xs"></span>
        <span>{{ __('admin.security_adding_to_whitelist') }}</span>
    </span>
</button>
```

#### Toolbar / Tool Button Variant (Icon-First)
```blade
<button wire:click="fetch"
        wire:loading.attr="disabled"
        wire:target="fetch"
        type="button"
        class="btn btn-outline btn-sm gap-2">
    <x-heroicon-o-arrow-path class="w-4 h-4" wire:loading.remove wire:target="fetch" />
    <span class="loading loading-spinner loading-xs" wire:loading wire:target="fetch"></span>
    <span>{{ __('admin.git_fetch') }}</span>
</button>
```

---

### Pattern 3: Table Row Operations & Icon-Button Loading (Row Dimming Pattern)
Used for actions triggered directly from table rows (e.g. deleting an entry, promoting an IP, unbanning, ending a session, reordering items).

1. **Row Dimming & Click Locking**:
   Add `wire:loading.class="opacity-40 pointer-events-none" wire:target="<methodName>(<id>)"` to the parent `<tr>`. This immediately dims the targeted row to 40% opacity and blocks pointer events during execution, providing instant visual feedback that the row is being modified or deleted.
2. **Automatic Icon-to-Spinner Swapping**:
   Use `<x-icon-button>` with `wire:click="<methodName>(<id>)"`. The component automatically infers the target from `wire:click`, swaps the icon for a DaisyUI spinner (`<span class="loading loading-spinner loading-xs"></span>`), and disables the button (`wire:loading.attr="disabled"`). To target a different action or pass explicit expressions, use the `loading-target` prop. To disable loading behavior on an icon button, pass `:loading="false"`.
3. **Inline Non-Icon Buttons in Rows**:
   When table rows use plain text or badge buttons (e.g. "Unblock", "Logout"), apply `wire:loading.attr="disabled" wire:target="<methodName>(<id>)"`, hide default text with `wire:loading.remove`, and show `<span class="loading loading-spinner loading-xs" wire:loading wire:target="..."></span>`.

#### Template
```blade
<tr class="hover"
    wire:key="{{ $item->id }}"
    wire:loading.class="opacity-40 pointer-events-none"
    wire:target="deleteIp({{ $item->id }})">
    <td class="font-mono font-medium">{{ $item->ip_address }}</td>
    <td>{{ $item->description }}</td>
    <td class="text-right">
        <x-icon-button icon="heroicon-o-trash"
                       :label="__('client.delete').' '.$item->ip_address"
                       wire:click="deleteIp({{ $item->id }})"
                       class="text-error" />
    </td>
</tr>
```

---

### Core Architecture Rules

1. **DaisyUI Spinner Standard**:
   - Always use the framework DaisyUI spinner class: `<span class="loading loading-spinner loading-xs"></span>` (use `loading-xs` for `btn-xs` and `btn-sm`; use `loading-sm` for standard `btn` or `btn-md`/`btn-lg`).
   - Do NOT introduce custom CSS spinning animations, raw `@keyframes spin`, or unstyled SVG spinners.
2. **Explicit Target Scoping (`wire:target`)**:
   - Every `wire:loading`, `wire:loading.remove`, and `wire:loading.attr="disabled"` directive MUST specify `wire:target="<methodName>"` matching the triggering action.
   - Without `wire:target`, unrelated Livewire background requests, polling hooks, or tab switches will unintentionally trigger the loading state across the page.
3. **Double-Submission Prevention**:
   - Always add `wire:loading.attr="disabled"` to the `<button>` element. This prevents rapid multi-clicks while the backend mutation or preflight check is running.
4. **State Transition Swapping**:
   - Isolate the idle content with `<span wire:loading.remove wire:target="<method>">` so icons and labels do not awkwardly jump or stack beside the spinner.
   - Render the active content inside `<span wire:loading wire:target="<method>">` with `inline-flex items-center gap-1.5` for balanced alignment.
5. **Plain-Language Present Continuous Copy**:
   - Switch button copy from imperative ("Save", "Protect My IP", "Apply Rules", "Add to Whitelist") to present continuous ("Saving...", "Protecting IP...", "Applying Rules...", "Adding to Whitelist...").
   - Maintain translation keys across all supported locales (`lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`).

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
| Feedback alerts hand-rolled per page | Use the shared components — see "UI Alert & Feedback Patterns" (inline vs in-dialog vs toast) |
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
| Raw `nft` command against a live kernel | Forbidden — use the app pipeline (`security:apply`/panel) or `nft -c -f` dry runs (see "Live Firewall Safety & Lockout Prevention") |
| Firewall switch/policy change without the local-services guard | Refuse via `assertLocalServicesSafe()` before persisting anything (see "Live Firewall Safety & Lockout Prevention") |

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

### Branching & Release Strategy (Laravel Model)
- TallPBX follows the **[Laravel framework versioned-branch model](https://laravel.com/docs/releases#versioning-scheme)** (e.g. `1.1`, `2.0`, `3.x`) and adheres to **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`).
- **Active Branch Confirmation**: Run `git branch --show-current` before starting work. Active development occurs directly on the current major/series branch (`3.x`). There is no perpetual `main` or `master` branch; never attempt to branch off or merge into `main`. All new features, modernizations, and architectural improvements are committed directly to `3.x` or merged into it.
- **No Backwards Compatibility for Unreleased Series Branches**: Because major series branch `3.x` is currently unreleased (no `v3.0.0` tag exists yet) and is explicitly not backwards compatible with the 2.x/1.x series, do not introduce or retain backward-compatibility fallbacks, deprecated aliases, transitional shims, or migration bridges for 2.x or unreleased 3.x iterations. Write all configuration, schema, routes, services, and tests directly in their modern canonical form. Backwards compatibility guarantees apply strictly to maintenance releases after a production tag (`v3.0.0`) is cut.
- **Numbered Release Series Branches**:
  - `1.0`: Frozen maintenance branch for 1.0.x (critical security/bug fixes only). Never push new feature work to `1.0`.
  - `1.1`: Maintenance release series for 1.1.x deployments.
  - `2.0`: Maintenance release series for 2.x deployments (latest stable: `v2.1.1`).
  - `3.x`: Current primary development branch for 3.x features and releases.
- **No Dual-Commit Syncs**: There are no dual-commit syncs between `main` and version branches. Commits belong to the active series branch (`3.x`) or maintenance branches when backporting fixes for existing releases.
- **Web Updater Recognition**: The web updater UI recognizes version branches (`^\d+(\.x|(\.\d+)+)`) as stable release series, sorting them in reverse version order so `3.x` is the top/default target.
- **Documentation**: User-facing versioning details are in [`README.md#versioning--release-strategy`](README.md#versioning--release-strategy), installation notes in [`INSTALL.md`](INSTALL.md), and release logs in [`CHANGELOG.md`](CHANGELOG.md).

### Tagging Best Practices (Do NOT Tag Every Commit)
- **Never tag individual commits or task completions.**
- Tags (`v1.0.0`, `v1.1.0`, `v2.0.0`, `v2.1.0`, `v2.1.1`, `v3.0.0`, etc.) are reserved strictly for official, finished production releases.
- Tags are created directly on their respective series branch heads (`1.0`, `1.1`, `2.0`, `3.x`).
- Use branch heads and commit SHAs for intermediate work and references.
- Only tag after full verification passes, the changelog version is dated, and the release is ready for users.

## Module Design Conventions

Apply when creating or modifying an app-modules module. New modules are
scaffolded with `php artisan make:module <name>` and must keep this shape:

- Code: `src/{Models,Services,Livewire,Support,Database/Factories,Providers}` under `Modules\{Studly}\`.
- Factories: `src/Database/Factories/`, namespace `Modules\{Studly}\Database\Factories`, resolved by standard HasFactory — never `database/factories/Pbx`, never a `newFactory()` override.
- Tests: Pest tests inside `app-modules/{name}/tests/` (`*Test.php`), discovered by the phpunit.xml glob. Cross-module tests stay in the central `tests/` tree; the ModuleAwareTestGuard skips them automatically when a referenced module is missing. Annotate URL-only or Dusk tests with `$this->skipWhenModuleUninstalled('<module>');`.
- Config: ship `config/{name}.php` to override centralized app-level defaults; it merges under the module's key (module values win on conflicts, all other keys survive).
- Dependencies: declare every cross-module class reference in `module.json` → `requirements.modules` (array of module name strings). ModuleBoundaryTest fails on undeclared references or cycles.
- Core modules are protected — never uninstallable, never disable-able: extensions, devices, sip-accounts, destinations, dialplans, dialplan-tools, gateways, sip-profiles, inbound-routes, outbound-routes (plus required admin, auth, tenant).
- Lifecycle: the panel offers enable/disable only. Removal: `php artisan module:uninstall <name>` (typed confirmation; refuses while dependents exist). Restore: `php artisan module:restore <name>` (from git or Composer; database data is not restored).

