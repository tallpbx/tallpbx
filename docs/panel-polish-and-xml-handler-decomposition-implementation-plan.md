# Panel Polish & XML Handler Decomposition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining deferred backlog from the internal security-audit remediation in three independent workstreams: shared loading feedback for slow panel actions (L5), accessible labels for every icon-only action button (M9 remainder), and decomposition of the 1,643-line `XmlHandlerController` into focused builder services (M1).

**Architecture:** All three workstreams are behavior-preserving or additive. L5 adds `wire:loading` states to shared Blade components and two slow form buttons. M9 introduces one shared `x-icon-button` Blade component (label required, `aria-label` enforced) and migrates 55 view files in five module batches. M1 extracts an `EscapesXml` trait plus three builder services from the controller, moving methods verbatim with the existing 65-test `XmlHandlerControllerTest` as the safety net.

**Tech Stack:** PHP 8.5, Laravel 13.8, Livewire 4, Pest 4, Blade components, DaisyUI 5 (`loading loading-spinner`), Tailwind v4. No new dependencies.

**Spec:** No external spec — this plan implements the deferred backlog recorded after the audit remediation commit range (`ea7c81d..d69c387`); the source audit report was deleted by convention. The audit's original findings, counts, and line references were re-verified during planning (measurements in each task below).

---

## Global Constraints

- TDD for behavior changes: failing test first, watch it fail, implement, watch it pass. Pure refactors (M1) rely on the existing suites as the safety net — capture a passing baseline before each move.
- Run minimum tests needed with targeted filters; always `--parallel`. Never run the full suite in one invocation.
- Run `php artisan optimize:clear` after every code change (views, components, PHP classes).
- Run `vendor/bin/pint --dirty --format agent` before every commit; it must report `passed`.
- Browser tests run sequentially via `./vendor/bin/pest tests/Browser/<file>`; cap heavy runs (`--parallel --processes=4`) as documented in AGENTS.md.
- Every class and method gets a plain-language PHPDoc comment; comment non-obvious lines (AGENTS.md).
- Granular conventional commits, one logical change each; **never commit or push without explicit user approval** this session.
- CHANGELOG entries: L5 under `### Changed`/`### Fixed`, M9 under `### Fixed`, M1 under `### Changed` — add them in the final task.
- No new Composer or npm dependencies.

## Review Focus

Failure modes this plan's tests pin, most likely first:

1. **Dialplan/directory XML byte drift after the M1 moves.** A moved method that silently references a different service, cache key, or config value changes FreeSWITCH behavior. Pinned by re-running `tests/Feature/Http/XmlHandlerControllerTest.php` (65 tests) after every extraction, plus `tests/Feature/XmlHandlerCacheConfigTest.php` for cache-key/store behavior — Tasks C2–C4.
2. **Cache keys/stores changing during the move** (e.g. a dropped `FS_XML_HANDLER_*` prefix) → stale or shared cache entries across tenants. Pinned by `XmlHandlerCacheConfigTest` and the tenant-scoped cache assertions in the controller suite — Tasks C2–C4.
3. **Tenant context leakage in builders**: the `handle*` methods own `setTenantId`/`clear` sequencing; a builder that clears or resets tenant context corrupts sibling requests. The moved cache-key methods must only READ `getTenantId()` — verified by the tenant isolation cases in the controller suite — Tasks C2–C4.
4. **Modal loading state blocking the typed-confirmation flow**: disabling/spinning the shared confirm button must not break the typed-deletion drills. Pinned by `tests/Browser/RiskConfirmationBrowserTest.php` (both drills) — Task A1.
5. **Raw translation keys leaking into `aria-label`**: if `client.view` (or delete/edit) is missing from a locale file, the accessible label literally reads `client.view`. Pinned by the locale-coverage test in Task B1.

---

## File Structure

**Created:**
- `resources/views/components/icon-button.blade.php` — shared labeled icon button/link (Task B1)
- `tests/Feature/Components/IconButtonTest.php` — component + locale-label tests (Task B1)
- `app/Support/Concerns/EscapesXml.php` — shared XML escaping trait (Task C1)
- `tests/Feature/Support/EscapesXmlTest.php` — trait unit test (Task C1)
- `app/Services/Xml/DirectoryXmlBuilder.php` — directory-section XML (Task C2)
- `app/Services/Xml/DialplanXmlBuilder.php` — dialplan-section XML + hiredis + caches (Task C3)
- `app/Services/Xml/SofiaConfigXmlBuilder.php` — configuration section: sofia, switch, ACL, IVR, conference, call center, local stream, DSNs (Task C4)

**Modified (highlights):**
- `resources/views/components/confirmation-modal.blade.php` (Task A1)
- `app-modules/backups/resources/views/backups-edit.blade.php`, `app-modules/extensions/resources/views/extensions-bulk-create.blade.php` (Task A2)
- `lang/{en,es,fr}/client.php` — add `view` label (Task B1)
- 55 module view files across five batches (Tasks B2–B6)
- `app/Http/Controllers/Api/XmlHandlerController.php` (Tasks C1–C4)

**Deleted:** none.

---

## Workstream A — Loading feedback for slow actions (L5)

### Task A1: Shared confirmation-modal loading state

**Files:**
- Modify: `resources/views/components/confirmation-modal.blade.php:46-47`
- Modify: `tests/Feature/Components/ConfirmationModalTest.php` (exists — append one test)
- Verify: `tests/Browser/RiskConfirmationBrowserTest.php`

**Interfaces:**
- Consumes: existing modal props (`confirmAction`, `confirmLabel`, `requiredText`)
- Produces: confirm button renders `wire:loading.attr="disabled"` and a DaisyUI spinner; every list/restore confirmation in the panel inherits it

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Components/ConfirmationModalTest.php
<?php

declare(strict_types=1);

test('it disables the confirm button and shows a spinner while the action runs', function (): void {
    $view = $this->blade('<x-confirmation-modal :open="true" title="Delete?" message="Sure?" confirm-label="Delete" confirm-action="deleteRecord()" cancel-action="cancel" />');

    $view->assertSee('wire:loading.attr="disabled"', false)
        ->assertSee('loading loading-spinner', false);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --parallel --filter="disables the confirm button"`
Expected: FAIL — markup not present yet.

- [ ] **Step 3: Implement**

Edit the confirm button in `resources/views/components/confirmation-modal.blade.php`:

```blade
{{-- While the confirmed action is in flight the button disables itself
     and shows a spinner, so slow operations (backups, restores) cannot
     be double-submitted. wire:model on the typed input is deferred in
     Livewire 4, so no requests fire while the user types. --}}
<button type="button" class="btn btn-error" wire:click="{{ $confirmAction }}"
        wire:loading.attr="disabled"
        @if ($requiredText !== '') :disabled="typed.trim() !== @js($requiredText)" @endif>
    <span wire:loading class="loading loading-spinner loading-sm"></span>
    {{ $confirmLabel }}
</button>
```

- [ ] **Step 4: Verify tests pass**

Run: `php artisan test --parallel --filter="ConfirmationModalTest"`
Expected: PASS.
Then run the real-flow drill: `./vendor/bin/pest tests/Browser/RiskConfirmationBrowserTest.php`
Expected: 2 passed — the typed tenant deletion and the user deletion still work.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add resources/views/components/confirmation-modal.blade.php tests/Feature/Components/ConfirmationModalTest.php
git commit -m "fix(ui): disable and spin the confirm button while slow actions run"
```

### Task A2: Slow form submit buttons (backup create, bulk extension create)

**Files:**
- Modify: `app-modules/backups/resources/views/backups-edit.blade.php:94`, `app-modules/extensions/resources/views/extensions-bulk-create.blade.php:128`
- Test: `app-modules/backups/tests/BackupsEditFeedbackTest.php`, `app-modules/extensions/tests/ExtensionsBulkCreateTest.php`

**Interfaces:**
- Consumes: form `wire:submit="save"` (both forms already use it)
- Produces: both save buttons disable + spin while `save` runs

- [ ] **Step 1: Write the failing assertions (one per module test file)**

```php
it('shows a loading state on the save button while the backup is created', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BackupsEdit::class)
        ->assertSee('wire:target="save"', false)
        ->assertSee('loading loading-spinner', false);
});
```

```php
it('shows a loading state on the save button while extensions are created', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsBulkCreate::class)
        ->assertSee('wire:target="save"', false)
        ->assertSee('loading loading-spinner', false);
});
```

(Adapt the `actingAs`/permission setup to each file's existing `beforeEach` conventions.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --parallel --filter="loading state on the save button"`
Expected: FAIL for both.

- [ ] **Step 3: Implement (same pattern in both views)**

```blade
<button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
    <span wire:loading wire:target="save" class="loading loading-spinner loading-sm"></span>
    {{-- keep the existing button label verbatim --}}
</button>
```

- [ ] **Step 4: Verify tests pass**

Run: `php artisan test --parallel --filter="BackupsEditFeedbackTest|ExtensionsBulkCreateTest"`
Expected: PASS.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/backups/resources/views/backups-edit.blade.php app-modules/extensions/resources/views/extensions-bulk-create.blade.php app-modules/backups/tests/BackupsEditFeedbackTest.php app-modules/extensions/tests/ExtensionsBulkCreateTest.php
git commit -m "fix(ui): spin and disable the slow create buttons"
```

---

## Workstream B — Accessible labels for icon-only actions (M9 remainder)

Measured scope: 51 views contain icon-only delete-confirm buttons (`btn-xs text-error`), all 43 edit-pencil links sit inside those same files, and 5 views use an eye/view icon. Total unique files: 55.

### Task B1: `x-icon-button` component, locale labels, component tests

**Files:**
- Create: `resources/views/components/icon-button.blade.php`
- Create: `tests/Feature/Components/IconButtonTest.php`
- Modify: `lang/en/client.php`, `lang/es/client.php`, `lang/fr/client.php` (add `'view' => 'View' / 'Ver' / 'Voir'` next to the existing `delete`/`edit` keys)

**Interfaces:**
- Produces: `<x-icon-button icon="heroicon-o-trash" :label="..." wire:click="..." class="text-error" />` and `<x-icon-button icon="heroicon-o-pencil" :label="..." :href="route(...)" />`; renders `<button type="button">` without `href`, `<a wire:navigate>` with it; THROWS `InvalidArgumentException` when `label` is blank; base classes `btn btn-ghost btn-xs`; icon at `w-4 h-4`

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Components/IconButtonTest.php
<?php

declare(strict_types=1);

test('it renders a labeled button with the accessible name', function (): void {
    $view = $this->blade('<x-icon-button icon="heroicon-o-trash" label="Delete recording" />');

    $view->assertSee('<button', false)
        ->assertSee('aria-label="Delete recording"', false)
        ->assertSee('btn btn-ghost btn-xs', false);
});

test('it renders a labeled navigation link when href is given', function (): void {
    $view = $this->blade('<x-icon-button icon="heroicon-o-pencil" label="Edit recording" href="/panel/recordings/1/edit" />');

    $view->assertSee('<a', false)
        ->assertSee('href="/panel/recordings/1/edit"', false)
        ->assertSee('wire:navigate', false)
        ->assertSee('aria-label="Edit recording"', false);
});

test('it refuses to render without an accessible label', function (): void {
    // Rendering is triggered by the first assertion, so the component's
    // exception surfaces from inside assertSee.
    expect(fn () => $this->blade('<x-icon-button icon="heroicon-o-trash" />')->assertSee('aria-label'))
        ->toThrow(InvalidArgumentException::class, 'requires a non-empty label');
});

test('every locale defines the client action labels', function (): void {
    foreach (['en', 'es', 'fr'] as $locale) {
        $labels = require base_path("lang/{$locale}/client.php");

        expect($labels)->toHaveKeys(['delete', 'edit', 'view']);
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --parallel --filter="IconButtonTest"`
Expected: FAIL — component file does not exist (and `view` key missing).

- [ ] **Step 3: Implement the component**

```blade
{{-- resources/views/components/icon-button.blade.php --}}
@props([
    'icon',
    'label' => null,
    'href' => null,
])

@php
    // Fail loudly instead of shipping an unlabeled icon-only control: the
    // label is the accessible name screen readers announce.
    if (blank($label)) {
        throw new \InvalidArgumentException('The x-icon-button component requires a non-empty label.');
    }

    // Render a link for navigation actions and a real button otherwise.
    $tag = $href !== null ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" wire:navigate @endif
    @if ($tag === 'button') type="button" @endif
    aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'btn btn-ghost btn-xs']) }}
>
    <x-dynamic-component :component="$icon" class="w-4 h-4" />
</{{ $tag }}>
```

Add the `view` key to all three locale files (after the existing `'edit'` line):

```php
'view' => 'View',      // lang/en/client.php
'view' => 'Ver',       // lang/es/client.php
'view' => 'Voir',      // lang/fr/client.php
```

- [ ] **Step 4: Verify tests pass**

Run: `php artisan test --parallel --filter="IconButtonTest"`
Expected: 4 passed.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add resources/views/components/icon-button.blade.php tests/Feature/Components/IconButtonTest.php lang/en/client.php lang/es/client.php lang/fr/client.php
git commit -m "feat(ui): add labeled icon-button component"
```

### Task B2: Migrate batch 1 — core telephony lists

**Files (13):**
- `app-modules/extensions/resources/views/extensions-list.blade.php` + `extensions-edit.blade.php` (eye)
- `app-modules/devices/resources/views/devices-list.blade.php`
- `app-modules/sip-accounts/resources/views/sip-accounts-list.blade.php` + `sip-accounts-edit.blade.php` (eye)
- `app-modules/sip-trunks/resources/views/sip-trunks-list.blade.php` + `sip-trunks-edit.blade.php` (eye)
- `app-modules/sip-profiles/resources/views/sip-profiles-list.blade.php`
- `app-modules/gateways/resources/views/gateways-list.blade.php`
- `app-modules/destinations/resources/views/destinations-list.blade.php`
- `app-modules/outbound-routes/resources/views/outbound-routes-list.blade.php`
- `app-modules/inbound-routes/resources/views/inbound-routes-list.blade.php`
- `app-modules/dialplans/resources/views/dialplans-list.blade.php`

**Interfaces:**
- Consumes: `x-icon-button` from Task B1
- Produces: identical DOM behavior with accessible names; each delete keeps its existing `wire:click="confirm...Deletion('{{ $record->id }}')"` call exactly

- [ ] **Step 1: Migrate the delete buttons (pattern)**

Before (varies between single-line and multi-line, e.g. `dialplans-list.blade.php`):

```blade
<button
    wire:click="confirmDialplanDeletion('{{ $dialplan->id }}')"
    class="btn btn-ghost btn-xs text-error"
>
    <x-heroicon-o-trash class="w-4 h-4" />
</button>
```

After:

```blade
<x-icon-button
    icon="heroicon-o-trash"
    :label="__('client.delete').' '.$dialplan->name"
    wire:click="confirmDialplanDeletion('{{ $dialplan->id }}')"
    class="text-error"
/>
```

The pattern for the other formats is identical: keep the exact `wire:click` value, keep `.text-error` in the passed class, and use the row's visible identifier (name / number / email) in the label.

- [ ] **Step 2: Migrate the edit links (pattern, e.g. `extensions-list.blade.php`)**

Before:

```blade
<a href="{{ route('panel.extensions.edit', $extension->id) }}" class="btn btn-ghost btn-xs">
    <x-heroicon-o-pencil class="w-4 h-4" />
</a>
```

After:

```blade
<x-icon-button
    icon="heroicon-o-pencil"
    :label="__('client.edit')"
    :href="route('panel.extensions.edit', $extension->id)"
/>
```

- [ ] **Step 3: Migrate the eye/view links (pattern, e.g. `cdr-list.blade.php`)**

After:

```blade
<x-icon-button icon="heroicon-o-eye" :label="__('client.view')" :href="route('panel.cdr.detail', $record->id)" />
```

- [ ] **Step 4: Verify render tests and one browser page**

Run: `php artisan test --compact --parallel --filter="ExtensionsListTest|ExtensionsEditTest|DialplansListTest|SipAccounts|Gateways|Destinations"`
Expected: PASS (all list/edit components still render).
Then: `./vendor/bin/pest tests/Browser/PanelSmokeTest.php --filter="extensions"`
Expected: PASS.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/extensions/resources/views app-modules/devices/resources/views app-modules/sip-accounts/resources/views app-modules/sip-trunks/resources/views app-modules/sip-profiles/resources/views app-modules/gateways/resources/views app-modules/destinations/resources/views app-modules/outbound-routes/resources/views app-modules/inbound-routes/resources/views app-modules/dialplans/resources/views
git commit -m "fix(ui): label icon buttons on core telephony lists"
```

### Task B3: Migrate batch 2 — routing & call features

**Files (10):** `ring-groups`, `call-flows`, `call-forwards`, `call-blocks`, `call-centers` (`queue-list.blade.php`), `call-recordings`, `call-broadcast` (`broadcast-list.blade.php`), `conferences`, `conference-centers`, `ivr-menus` — each `<module>/resources/views/*-list.blade.php`.

**Interfaces:** same patterns as Task B2 (delete + edit; these files have no eye links).

- [ ] **Step 1: Apply the delete-button pattern** from Task B2 Step 1 to all 10 files (delete-only for `call-recordings`, `call-broadcast`; delete + edit for the rest).
- [ ] **Step 2: Apply the edit-link pattern** from Task B2 Step 2 to the files that contain pencil links (all except `call-recordings`, `call-broadcast`).
- [ ] **Step 3: Verify render tests**

Run: `php artisan test --compact --parallel --filter="CallFlows|CallForwards|CallBlocks|CallRecordings|RingGroups|IvrMenus|Conference"`
Expected: PASS.

- [ ] **Step 4: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/ring-groups/resources/views app-modules/call-flows/resources/views app-modules/call-forwards/resources/views app-modules/call-blocks/resources/views app-modules/call-centers/resources/views app-modules/call-recordings/resources/views app-modules/call-broadcast/resources/views app-modules/conferences/resources/views app-modules/conference-centers/resources/views app-modules/ivr-menus/resources/views
git commit -m "fix(ui): label icon buttons on routing and call feature lists"
```

### Task B4: Migrate batch 3 — media, limits & listings

**Files (10):** `time-conditions`, `feature-codes`, `follow-me`, `music-on-hold`, `number-translations`, `pin-numbers`, `tenant-limits`, `provision` (`template-list.blade.php`), `recordings`, `voicemails` — each `<module>/resources/views/*-list.blade.php`.

- [ ] **Step 1: Apply the delete-button pattern** (Task B2 Step 1) to all 10 files.
- [ ] **Step 2: Apply the edit-link pattern** (Task B2 Step 2) to the files that contain pencil links.
- [ ] **Step 3: Verify render tests**

Run: `php artisan test --compact --parallel --filter="TimeConditions|FeatureCodes|FollowMe|MusicOnHold|NumberTranslations|PinNumbers|TenantLimits|Recordings|Voicemails"`
Expected: PASS.

- [ ] **Step 4: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/time-conditions/resources/views app-modules/feature-codes/resources/views app-modules/follow-me/resources/views app-modules/music-on-hold/resources/views app-modules/number-translations/resources/views app-modules/pin-numbers/resources/views app-modules/tenant-limits/resources/views app-modules/provision/resources/views app-modules/recordings/resources/views app-modules/voicemails/resources/views
git commit -m "fix(ui): label icon buttons on media and limits lists"
```

### Task B5: Migrate batch 4 — messages, stores & operations

**Files (10):** `voicemail-messages`, `email-queue`, `email-templates`, `extension-settings`, `hot-desking`, `emergency`, `speech`, `transcribe` (`transcription-list.blade.php`), `file-stores`, `xml-cdr` (`cdr-list.blade.php`, delete + eye).

- [ ] **Step 1: Apply the delete-button pattern** (Task B2 Step 1) to all 10 files.
- [ ] **Step 2: Apply the edit-link pattern** (Task B2 Step 2) and the eye pattern (Task B2 Step 3, `cdr-list.blade.php` only).
- [ ] **Step 3: Verify render tests**

Run: `php artisan test --compact --parallel --filter="VoicemailMessages|EmailQueue|EmailTemplates|ExtensionSettings|HotDesking|Emergency|Speech|Transcribe|FileStoresList|Cdr"`
Expected: PASS.

- [ ] **Step 4: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/voicemail-messages/resources/views app-modules/email-queue/resources/views app-modules/email-templates/resources/views app-modules/extension-settings/resources/views app-modules/hot-desking/resources/views app-modules/emergency/resources/views app-modules/speech/resources/views app-modules/transcribe/resources/views app-modules/file-stores/resources/views app-modules/xml-cdr/resources/views
git commit -m "fix(ui): label icon buttons on message and store lists"
```

### Task B6: Migrate batch 5 — admin, security & remaining

**Files (12):** `acl` (`acl-list.blade.php`), `backups` (`backups-list.blade.php`), `bridges`, `security` (`security-manager.blade.php`), `fax` (`fax-inbox.blade.php`, eye), and admin module views: `admins-list`, `groups-list`, `notifications-list` (delete only), `settings-edit`, `tenant-domains-list`, `tenants-list`, `users-list`.

- [ ] **Step 1: Apply the delete-button pattern** (Task B2 Step 1) to every file with a delete button.
- [ ] **Step 2: Apply the edit-link pattern** to files with pencil links, and the eye pattern to `fax-inbox.blade.php`.
- [ ] **Step 3: Sweep for leftovers**

Run: `grep -rn "btn-xs text-error\|heroicon-o-pencil\|heroicon-o-eye" app-modules/*/resources/views/*.blade.php`
Expected: no matches outside `x-confirmation-modal` usages (the shared modal keeps its own buttons).

- [ ] **Step 4: Verify render tests and a browser page**

Run: `php artisan test --compact --parallel --filter="AclList|Backups|SecurityManager|Bridges|Admin"`
Expected: PASS.
Then: `./vendor/bin/pest tests/Browser/PanelSmokeTest.php --filter="render"`
Expected: PASS.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app-modules/acl/resources/views app-modules/backups/resources/views app-modules/bridges/resources/views app-modules/security/resources/views app-modules/fax/resources/views app-modules/admin/resources/views
git commit -m "fix(ui): label icon buttons on admin and security views"
```

---

## Workstream C — XmlHandlerController decomposition (M1)

Baseline: `app/Http/Controllers/Api/XmlHandlerController.php` = 1,643 lines, 48 methods, constructor injects `TenantManager`, `TenantIdentityResolverInterface`, `SipProfileServiceInterface`, `DialplanXmlCollector`, `MediaStorageServiceInterface`, `NumberTranslationServiceInterface`. Entry points called from the section handlers: `buildCachedDirectoryXml` (line 236), `buildCachedDialplanXml` (line 292), `buildConfigurationXml` (line 373). `handle()`, `handleDirectory()`, `handleDialplan()`, `handlePhrases()`, `handleConfiguration()`, `errorXml()`, `logRequestDebug()`, `xmlResponse()` STAY in the controller.

### Task C1: Extract the shared `EscapesXml` trait

**Files:**
- Create: `app/Support/Concerns/EscapesXml.php`
- Create: `tests/Feature/Support/EscapesXmlTest.php`
- Modify: `app/Http/Controllers/Api/XmlHandlerController.php` (use trait, delete the private `escapeXml` at line ~1629)

**Interfaces:**
- Produces: `protected function escapeXml(string $value): string` via trait `App\Support\Concerns\EscapesXml`; used by the controller and all three builders (53 call sites keep working unchanged)

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Support/EscapesXmlTest.php
<?php

declare(strict_types=1);

use App\Support\Concerns\EscapesXml;

it('escapes XML special characters', function (): void {
    $host = new class
    {
        use EscapesXml;

        public function escape(string $value): string
        {
            return $this->escapeXml($value);
        }
    };

    expect($host->escape('<name & "quoted">'))->toBe('&lt;name &amp; &quot;quoted&quot;&gt;');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --parallel --filter="EscapesXmlTest"`
Expected: FAIL — trait not found.

- [ ] **Step 3: Implement the trait and apply it**

```php
// app/Support/Concerns/EscapesXml.php
<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Escapes values for safe inclusion in XML documents.
 */
trait EscapesXml
{
    /**
     * Escape special XML characters in a string.
     */
    protected function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

In the controller: add `use App\Support\Concerns\EscapesXml;` inside the class (alongside the existing trait usage pattern), delete the private `escapeXml` method, keep `errorXml()` calling `$this->escapeXml(...)` unchanged.

- [ ] **Step 4: Verify** — baseline preserved

Run: `php artisan test --compact --parallel --filter="EscapesXmlTest|XmlHandlerController"`
Expected: PASS (1 new + 65 existing).

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app/Support/Concerns/EscapesXml.php tests/Feature/Support/EscapesXmlTest.php app/Http/Controllers/Api/XmlHandlerController.php
git commit -m "refactor(xml-handler): share XML escaping through a trait"
```

### Task C2: Extract `DirectoryXmlBuilder`

**Files:**
- Create: `app/Services/Xml/DirectoryXmlBuilder.php`
- Modify: `app/Http/Controllers/Api/XmlHandlerController.php`
- Guard: `tests/Feature/Http/XmlHandlerControllerTest.php`, `tests/Feature/XmlHandlerCacheConfigTest.php`

**Interfaces:**
- Consumes: `EscapesXml` trait; `App\Services\TenantManager`
- Produces: `public function buildCachedDirectoryXml(string $tagName, string $domain, string $username, string $keyValue): string` — the only public method; the controller calls it from `handleDirectory()` at line 236

- [ ] **Step 1: Capture the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig"`
Expected: PASS — record the pass counts.

- [ ] **Step 2: Move the methods verbatim**

Move these methods, unchanged in body, out of the controller into the new `DirectoryXmlBuilder` class (give each its existing PHPDoc):

| Method | Current line (approx.) | Visibility in builder |
|---|---|---|
| `buildDirectoryXml` | 388 | private |
| `buildCachedDirectoryXml` | 461 | **public** |
| `directoryCacheKey` | 490 | private |
| `buildUserEntryXml` | 508 | private |

Class shell:

```php
namespace App\Services\Xml;

use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;

/**
 * Builds the FreeSWITCH directory-section XML, with per-tenant caching.
 */
class DirectoryXmlBuilder
{
    use EscapesXml;

    /**
     * Create the builder with the tenant context source used by cache keys.
     */
    public function __construct(private readonly TenantManager $tenantManager) {}
}
```

Move any imports the methods need (models, facades) into the builder; remove controller imports that became unused. The cache-key method must only READ `$this->tenantManager->getTenantId()` — it must never set or clear tenant context (the `handle*` methods own that sequencing).

- [ ] **Step 3: Rewire the controller**

Add `private readonly \App\Services\Xml\DirectoryXmlBuilder $directoryBuilder` to the constructor (import it), and change the call at line 236:

```php
$xml = $this->directoryBuilder->buildCachedDirectoryXml($tagName, $domain, $sipAuthUsername, $keyValue);
```

- [ ] **Step 4: Verify against the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig"`
Expected: PASS with the same counts as Step 1.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app/Services/Xml/DirectoryXmlBuilder.php app/Http/Controllers/Api/XmlHandlerController.php
git commit -m "refactor(xml-handler): extract DirectoryXmlBuilder"
```

### Task C3: Extract `DialplanXmlBuilder`

**Files:**
- Create: `app/Services/Xml/DialplanXmlBuilder.php`
- Modify: `app/Http/Controllers/Api/XmlHandlerController.php`
- Guard: `tests/Feature/Http/XmlHandlerControllerTest.php`, `tests/Feature/XmlHandlerCacheConfigTest.php`

**Interfaces:**
- Consumes: `EscapesXml`; `TenantManager`; `DialplanXmlCollector`; `NumberTranslationServiceInterface`
- Produces: `public function buildCachedDialplanXml(string $context, string $destination, string $callerId): string` — the only public method; the controller calls it from `handleDialplan()` at line 292

- [ ] **Step 1: Capture the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig"`
Expected: PASS — record the pass counts.

- [ ] **Step 2: Move the methods verbatim**

| Method | Current line (approx.) | Visibility in builder |
|---|---|---|
| `buildDialplanXml` | 586 | private |
| `buildStandardDialplanXml` | 697 | private |
| `optionalHiredisDialplanXml` | 763 | private |
| `shouldSkipOptionalHiredisDialplanAction` | 819 | private |
| `dialplanExtensionAttributes` | 831 | private |
| `dialplanActionData` | 848 | private |
| `buildCachedStandardDialplanXml` | 865 | private |
| `standardDialplanCacheKey` | 888 | private |
| `buildCachedDialplanXml` | 905 | **public** |
| `dialplanCacheKey` | 938 | private |

Class shell:

```php
namespace App\Services\Xml;

use App\Services\DialplanXmlCollector;
use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

/**
 * Builds the FreeSWITCH dialplan-section XML, including hiredis fallbacks,
 * destination rewriting, and per-tenant caching.
 */
class DialplanXmlBuilder
{
    use EscapesXml;

    /**
     * Create the builder with the services the dialplan rendering needs.
     */
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly NumberTranslationServiceInterface $numberTranslationService,
        private readonly DialplanXmlCollector $dialplanXmlCollector,
    ) {}
}
```

The hiredis lookup and every cache key move byte-for-byte — do not reformat their logic.

- [ ] **Step 3: Rewire the controller**

Inject the builder, change line 292 to `$this->dialplanBuilder->buildCachedDialplanXml($context, $destination, $callerId);`, and drop `DialplanXmlCollector` + `NumberTranslationServiceInterface` from the controller constructor (no `handle*` method references them).

- [ ] **Step 4: Verify against the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig|FreeswitchConfig"`
Expected: PASS with the same counts as Step 1.

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app/Services/Xml/DialplanXmlBuilder.php app/Http/Controllers/Api/XmlHandlerController.php
git commit -m "refactor(xml-handler): extract DialplanXmlBuilder"
```

### Task C4: Extract `SofiaConfigXmlBuilder`

**Files:**
- Create: `app/Services/Xml/SofiaConfigXmlBuilder.php`
- Modify: `app/Http/Controllers/Api/XmlHandlerController.php`
- Guard: `tests/Feature/Http/XmlHandlerControllerTest.php`, `tests/Feature/XmlHandlerCacheConfigTest.php`, `tests/Feature/Config/FreeswitchConfigTest.php`

**Interfaces:**
- Consumes: `EscapesXml`; `TenantManager`; `SipProfileServiceInterface`; `MediaStorageServiceInterface`
- Produces: `public function buildConfigurationXml(string $keyName, string $keyValue, bool $includeAllTenantConfiguration = false): string` — the only public method; the controller calls it from `handleConfiguration()` at line 373

- [ ] **Step 1: Capture the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig|FreeswitchConfig"`
Expected: PASS — record the pass counts.

- [ ] **Step 2: Move the methods verbatim**

| Method | Current line (approx.) |
|---|---|
| `buildConfigurationXml` (**public**) | 959 |
| `buildAclConfigurationXml` | 982 |
| `renderAclConfigurationXml` | 995 |
| `buildSwitchConfigurationXml` | 1056 |
| `switchLogLevel` | 1102 |
| `switchSessionsPerSecond` | 1113 |
| `buildDatabaseBackedConfigurationXml` | 1121 |
| `buildSofiaConfigurationXml` | 1147 |
| `sofiaGlobalSettings` | 1197 |
| `freeSwitchScalarValue` | 1216 |
| `indentXml` | 1228 |
| `buildIvrConfigurationXml` | 1244 |
| `managedGreetingPath` | 1314 |
| `buildConferenceConfigurationXml` | 1330 |
| `buildCallCenterConfigurationXml` | 1380 |
| `buildLocalStreamConfigurationXml` | 1431 |
| `buildPlaceholderConfigurationXml` | 1473 |
| `resolveConfigurationName` | 1488 |
| `freeSwitchDatabaseDriver` | 1500 |
| `freeSwitchDatabaseDsn` | 1508 |
| `freeSwitchModuleDatabaseDsn` | 1526 |
| `buildMariaDbDsn` | 1535 |
| `buildPostgresDsn` | 1549 |
| `nullableConfigString` | 1564 |
| `booleanConfigValue` | 1578 |
| `nullableConfigBoolean` | 1586 |

All private except the entry point. `indentXml` moves here (its only external call site, line 1181, is inside `buildSofiaConfigurationXml`). The multi-tenant include logic keeps reading `$this->tenantManager->getTenantId()` only.

Class shell:

```php
namespace App\Services\Xml;

use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;
use Modules\SipProfiles\Services\SipProfileServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * Builds the FreeSWITCH configuration-section XML: switch, sofia,
 * database DSNs, ACL, IVR, conference, call center, and local stream.
 */
class SofiaConfigXmlBuilder
{
    use EscapesXml;

    /**
     * Create the builder with the services the configuration rendering needs.
     */
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly SipProfileServiceInterface $sipProfileService,
        private readonly MediaStorageServiceInterface $mediaStorage,
    ) {}
}
```

- [ ] **Step 3: Rewire the controller**

Inject the builder, change line 373 to `$this->configBuilder->buildConfigurationXml($keyName, $keyValue, $domain === '');`, and drop `SipProfileServiceInterface` + `MediaStorageServiceInterface` from the controller constructor. The controller now injects exactly: `TenantManager`, `TenantIdentityResolverInterface`, and the three builders.

- [ ] **Step 4: Verify against the baseline**

Run: `php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig|FreeswitchConfig"`
Expected: PASS with the same counts as Step 1. Then confirm the controller size:

```bash
wc -l app/Http/Controllers/Api/XmlHandlerController.php
```

Expected: roughly 500-550 lines (request/auth/section orchestration + shared response helpers only).

- [ ] **Step 5: Clear caches, format, commit**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add app/Services/Xml/SofiaConfigXmlBuilder.php app/Http/Controllers/Api/XmlHandlerController.php
git commit -m "refactor(xml-handler): extract SofiaConfigXmlBuilder"
```

---

## Final Verification & Changelog

### Task F1: Whole-plan gate

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]`)

- [ ] **Step 1: Run the combined gates**

```bash
php artisan test --compact --parallel --filter="XmlHandlerController|XmlHandlerCacheConfig|FreeswitchConfig|IconButtonTest|ConfirmationModalTest|EscapesXmlTest|SystemHealthTest"
./vendor/bin/pest tests/Browser/RiskConfirmationBrowserTest.php
php artisan route:list --name=xml-handler >/dev/null && echo route-ok
```

Expected: all PASS; the browser drills still delete successfully.

- [ ] **Step 2: Add CHANGELOG entries**

Under `### Changed`: the XmlHandlerController decomposition (directory/dialplan/configuration builder services; no behavior change) and the new loading states on slow actions. Under `### Fixed`: icon-only action buttons now carry accessible labels (delete/edit/view across all panel lists).

- [ ] **Step 3: Commit the changelog**

```bash
git add CHANGELOG.md
git commit -m "docs: record the panel polish and xml builder extraction"
```

- [ ] **Step 4: Post-completion cleanup (after push approval)**

Per the project convention, delete this plan file once its work is pushed: `git rm docs/panel-polish-and-xml-handler-decomposition-implementation-plan.md` and commit with `docs: remove completed panel polish implementation plan`.

---

## Execution Handoff

Plan complete and saved to `docs/panel-polish-and-xml-handler-decomposition-implementation-plan.md`. Please review the plan. Which execution approach would you prefer?

- **Subagent-driven** - A fresh subagent implements each task and a fresh reviewer checks it before the next one starts, then a whole-branch review at the end. Most thorough; costs a fresh context per task and per review.
- **Native** - I implement every task myself in this session, the way this harness runs work, then one fresh reviewer on the most capable model checks the whole branch. Cheapest and fastest; no independent review until the end. Runs well with a mid-tier session model, since the plan carries the design.

**For this plan I recommend Native**, because the tasks are strictly sequential on two shared files (the controller and the modal), the B-batches are byte-identical mechanical migrations best done without handoff overhead, and every task ends in a suite-verified checkpoint. Does the plan capture what you want, and which approach should we use?

