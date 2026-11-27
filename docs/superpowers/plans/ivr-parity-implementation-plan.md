# IVR Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the IVR gap so an administrator can build a FusionPBX/FreePBX-equivalent auto-attendant from the panel, executed by FreeSWITCH's native IVR engine.

**Architecture:** Typed destinations resolve to FreeSWITCH menu entries rendered into the dynamically served `ivr.conf`; each menu gets a dialplan stub extension that answers, applies presentation settings, and calls `application="ivr"` keyed by the menu UUID. The broken `bind_digit_action`/`read` path is deleted.

**Tech Stack:** Laravel 13, Livewire 4, Pest 4, MariaDB, FreeSWITCH 1.11 (`mod_dptools` ivr, `mod_xml_curl`), SIPp.

**Spec:** `docs/superpowers/specs/2026-11-27-ivr-parity-design.md`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; every class and method has a plain-language PHPDoc.
- Tenant isolation: every query uses `withoutGlobalScope('tenant')` + explicit `tenant_id` filter.
- XML values escaped with `ENT_XML1 | ENT_QUOTES`; no `${` may appear in generated `ivr.conf` entry params.
- No static FreeSWITCH XML files on disk; all XML through the XML Handler (`mod_xml_curl`).
- Media stored as files with paths in DB; never blobs.
- `ivr_menus.extension` values: digits only, unique per tenant, must not shadow `extensions.extension_number`, `feature_codes.code`, `call_flows.extension`, or another menu.
- `ivr.conf` menu names are menu UUIDs and the generator serves all tenants regardless of domain for `key_value = ivr.conf`.
- UI: DaisyUI semantic tokens only, `toggle-sm`, `badge-xs font-mono`, present-continuous toggle labels, `wire:loading` pointer locks and spinners.
- Translations: `en`/`es`/`fr` full parity in `app-modules/ivr-menus/lang/<locale>/admin.php`.
- After any change: `tpbx optimize:clear`. PHP changes: `./vendor/bin/pint --dirty`. Tests: `./vendor/bin/pest <path>`.

## Review Focus

1. **Option-less menu, direct dial off** — announcement plays, call hangs up cleanly; no `read`/digit loop and no `ivr` invoked when no entries would exist (menu with zero entries still plays greet-long then exits via max-timeouts).
2. **Two tenants with identical menu names** — `ivr.conf` is UUID-keyed and complete for both tenants; no tenant can reference the other's menu or destinations.
3. **Target disabled/deleted after save** — an option or fallback whose target no longer resolves is skipped with a log warning; the dialplan still answers and the remaining entries work.
4. **Caller never presses a digit** — timeout counter reaches `max-timeouts`, `exec-on-max-timeouts` runs (or the call exits cleanly); never an infinite prompt loop.
5. **Menu extension collides with a dialable number** — save is rejected with a clear validation message naming the colliding record type.

---

### Task 1: Schema, models, media categories, factories

**Files:**
- Create: `app-modules/ivr-menus/database/migrations/2026_11_27_000001_add_parity_columns_to_ivr_tables.php`
- Modify: `app-modules/ivr-menus/src/Models/IvrMenu.php`, `app-modules/ivr-menus/src/Models/IvrMenuOption.php`
- Modify: `app-modules/file-stores/src/Enums/MediaCategory.php`, `app-modules/file-stores/src/Services/MediaAssetAuthorizationService.php:20-30`
- Modify: `app-modules/ivr-menus/src/Database/Factories/IvrMenuFactory.php`, `IvrMenuOptionFactory.php`
- Test: `app-modules/ivr-menus/tests/IvrMenuModelTest.php`

**Interfaces:**
- Produces: `IvrMenu` fillable includes `extension, greet_short, invalid_sound, exit_sound, inter_digit_timeout, max_timeouts, direct_dial, ringback, cid_prefix, exit_kind, exit_data, invalid_kind, invalid_data, timeout_kind, timeout_data`; `IvrMenu::mediaAssets(): MorphMany`; `IvrMenuOption` fillable includes `description, return_to_menu`.

- [ ] **Step 1: Write the failing test**

```php
it('exposes parity columns and casts', function (): void {
    $menu = IvrMenu::factory()->create([
        'extension' => '2600', 'direct_dial' => true, 'max_timeouts' => 4,
        'exit_kind' => 'extension', 'exit_data' => '2000',
    ]);
    expect($menu->fresh())
        ->extension->toBe('2600')
        ->direct_dial->toBeTrue()
        ->max_timeouts->toBe(4)
        ->exit_kind->toBe('extension');

    $option = IvrMenuOption::factory()->create([
        'ivr_menu_id' => $menu->id, 'return_to_menu' => true, 'description' => 'Sales',
    ]);
    expect($option->fresh()->return_to_menu)->toBeTrue()
        ->and($option->fresh()->description)->toBe('Sales');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenuModelTest.php`
Expected: FAIL — unknown column `extension`.

- [ ] **Step 3: Implement migration and models**

Migration: add the columns from the spec's data-model table to `ivr_menus` (all `nullable` or with the spec defaults) plus `description` (text, nullable) and `return_to_menu` (boolean, default false) to `ivr_menu_options`; `down()` drops them. Update fillables/casts (`direct_dial`, `return_to_menu` boolean; integer casts for the three timeout/retry columns). Replace `mediaAsset(): MorphOne` with `mediaAssets(): MorphMany` (`morphMany(MediaAsset::class, 'owner')->withoutGlobalScope('tenant')`) and keep `managedAsset(MediaCategory $category): ?MediaAsset` as a helper returning the first matching asset. Add `MediaCategory` cases `IvrGreetingShort`, `IvrInvalidPrompt`, `IvrExitPrompt` (values `ivr-greeting-short`, `ivr-invalid-prompt`, `ivr-exit-prompt`) and map all four IVR categories to `ivr-menus.view` in `MediaAssetAuthorizationService`. Factories: default `extension => (string) fake()->unique()->numberBetween(2500, 2699)`, `direct_dial => false`, `max_timeouts => 3`, `inter_digit_timeout => 3`; option factory adds `description => fake()->optional()->sentence()`, `return_to_menu => false`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenuModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus app-modules/file-stores
git commit -m "feat(ivr): add parity schema, media categories, and model casts"
```

---

### Task 2: Destination kind enum and renderer

**Files:**
- Create: `app-modules/ivr-menus/src/Enums/IvrDestinationKind.php`
- Create: `app-modules/ivr-menus/src/Support/IvrDestinationRenderer.php`
- Test: `app-modules/ivr-menus/tests/IvrDestinationRendererTest.php`

**Interfaces:**
- Produces: `IvrDestinationKind: string` cases `Extension, RingGroup, Voicemail, Conference, Queue, External, Playback, Ivr, MenuAction` (values equal to the `DestinationResolver::KIND_*` strings; `playback`, `ivr`, `menu_action` are module-local); `IvrDestinationKind::selectable(): array<int, self>`.
- Produces: `IvrDestinationRenderer::render(IvrDestinationKind $kind, string $identifier, int $tenantId, string $tenantDomain): ?array{entry_action: string, param: string}` — `entry_action` is `menu-exec-app`, `menu-sub`, `menu-play-sound`, `menu-top`, `menu-back`, or `menu-exit`; returns `null` when unresolvable.
- Produces: `IvrDestinationRenderer::execString(IvrDestinationKind $kind, string $identifier, int $tenantId, string $tenantDomain): ?string` — `"<application> <data>"` for exit/`exec-on-*`; `null` for non-executable kinds (Playback returns `"playback <path>"`).

- [ ] **Step 1: Write the failing tests**

```php
it('renders transfer-style kinds through the resolver', function (): void {
    // extension 2000 exists for the tenant; voicemail 2003 with domain
    expect($renderer->render(IvrDestinationKind::Extension, '2000', $tenant->id, 'sip.example.com'))
        ->toBe(['entry_action' => 'menu-exec-app', 'param' => 'transfer user/2000']);
});

it('renders voicemail with a concrete domain and no channel variables', function (): void {
    $rendered = $renderer->render(IvrDestinationKind::Voicemail, '2003', $tenant->id, 'sip.example.com');
    expect($rendered['param'])->toBe('voicemail default sip.example.com 2003')
        ->and($rendered['param'])->not->toContain('${');
});

it('maps ivr and menu actions to navigation entries', function (): void {
    expect($renderer->render(IvrDestinationKind::Ivr, $otherMenu->id, $tenant->id, 'sip.example.com'))
        ->toBe(['entry_action' => 'menu-sub', 'param' => $otherMenu->id]);
    expect($renderer->render(IvrDestinationKind::MenuAction, 'back', $tenant->id, 'sip.example.com'))
        ->toBe(['entry_action' => 'menu-back', 'param' => '']);
});

it('returns null for deleted or disabled targets', function (): void {
    expect($renderer->render(IvrDestinationKind::Extension, '9999', $tenant->id, 'sip.example.com'))->toBeNull();
});

it('renders playback as path, menu-play-sound handled by caller', function (): void {
    // playback identifier = raw path when not a recording id
    expect($renderer->render(IvrDestinationKind::Playback, '/sounds/hours.wav', $tenant->id, 'sip.example.com'))
        ->toBe(['entry_action' => 'menu-exec-app', 'param' => 'playback /sounds/hours.wav']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrDestinationRendererTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement enum and renderer**

`render()`: `MenuAction` maps `top|back|exit` → `menu-top|menu-back|menu-exit` (param `''`). `Ivr` → `menu-sub` with the target menu UUID. `Playback` → resolve a `recordings` module path when the identifier matches a recording id, else raw path; entry action `menu-exec-app`, param `playback <path>`. All other kinds call `DestinationResolver::resolve($kind->value, $identifier, $tenantId)`; post-process `data` by replacing `${domain}` and `${domain_name}` with `$tenantDomain`; if `${` remains, return `null`. `execString()` uses the same resolution and returns `"{$application} {$data}"`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrDestinationRendererTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus/src/Enums app-modules/ivr-menus/src/Support app-modules/ivr-menus/tests/IvrDestinationRendererTest.php
git commit -m "feat(ivr): add typed destination renderer for FreeSWITCH menu entries"
```

---

### Task 3: Dialplan stub and return-helper extensions

**Files:**
- Modify: `app-modules/ivr-menus/src/Services/IvrMenuService.php:104-166` (replace `generateDialplanXml`; delete `resolveGreetingPath` legacy path use in favor of `managedAsset`)
- Test: `app-modules/ivr-menus/tests/IvrDialplanXmlTest.php`

**Interfaces:**
- Consumes: `IvrDestinationRenderer::execString()` from Task 2; `IvrMenu::mediaAssets()` from Task 1.
- Produces: stub extension named `ivr_<uuid>` matching `^<extension>$` with actions `answer`, `sleep 1000`, `hangup_after_bridge=true`, optional `ringback`/`transfer_ringback`/CID-prefix sets, `ivr <uuid>`, optional exit action; helper extensions `ivr_return_<option uuid>` matching `^ivr_return_<option uuid>$` that execute the option destination and `transfer <menu extension>`.

- [ ] **Step 1: Write the failing tests**

```php
it('emits an extension-matched stub that invokes the ivr app', function (): void {
    $menu = IvrMenu::factory()->create(['extension' => '2600', 'enabled' => true]);
    $xml = $service->generateDialplanXml($menu->tenant_id, 'tenant_'.$menu->tenant_id.'_internal', '2600');

    expect($xml)
        ->toContain('<extension name="ivr_'.$menu->id.'">')
        ->toContain('expression="^2600$"')
        ->toContain('application="ivr" data="'.$menu->id.'"')
        ->not->toContain('bind_digit_action')
        ->not->toContain('touchexec');
});

it('applies ringback and cid prefix only when configured', function (): void {
    // menu with ringback 'us-ring', cid_prefix 'IVR'
    expect($xml)->toContain('data="ringback=tone_stream://%(2000,4000,440,480)"')
        ->toContain('data="caller_id_name=IVR#${caller_id_name}"');
});

it('emits helper extensions only for return_to_menu transfer options', function (): void {
    // option returns true, kind extension, target 2000
    expect($xml)->toContain('extension name="ivr_return_'.$option->id.'"')
        ->toContain('data="transfer 2000"')       // executed inside the helper
        ->toContain('data="transfer 2600"');      // back to the stub
});

it('skips disabled menus and menus without an extension', function (): void {
    // two menus: disabled / extension null
    expect($service->generateDialplanXml($tenantId, $context, '2600'))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrDialplanXmlTest.php`
Expected: FAIL — current output still contains `bind_digit_action`.

- [ ] **Step 3: Implement the generator**

Query enabled menus with `extension` not null, eager-load options and media assets, order by name. Emit stubs then helper extensions; skip unresolvable options with `Log::warning`. Use `IvrDestinationRenderer::execString()` for the exit action and helper bodies (helper body = rendered exec string per action; for a transfer-kind option the helper executes `transfer <target>` then `transfer <menu extension>`). Escape every value with `ENT_XML1 | ENT_QUOTES`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrDialplanXmlTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus/src/Services/IvrMenuService.php app-modules/ivr-menus/tests/IvrDialplanXmlTest.php
git commit -m "feat(ivr): replace broken digit loop with ivr-app stubs and return helpers"
```

---

### Task 4: Service write path — option sync and asset cleanup

**Files:**
- Modify: `app-modules/ivr-menus/src/Services/IvrMenuService.php`, `app-modules/ivr-menus/src/Services/IvrMenuServiceInterface.php`
- Test: `app-modules/ivr-menus/tests/IvrMenuServiceWriteTest.php`

**Interfaces:**
- Produces: `create(array $data, array $options = []): IvrMenu` and `update(IvrMenu $menu, array $data, array $options = []): IvrMenu`; option row shape `['digit' => string, 'action' => string, 'action_data' => ?string, 'description' => ?string, 'return_to_menu' => bool, 'order' => int, 'enabled' => bool]`.
- Produces: `delete()` requests deletion of every owned `MediaAsset` through `MediaStorageServiceInterface::requestDeletion()`.

- [ ] **Step 1: Write the failing tests**

```php
it('creates a menu with options in one transaction', function (): void {
    $menu = $service->create(['tenant_id' => $tenant->id, 'name' => 'Main', 'extension' => '2600'],
        [['digit' => '1', 'action' => 'extension', 'action_data' => '2000', 'return_to_menu' => false, 'order' => 0, 'enabled' => true]]);
    expect($menu->options)->toHaveCount(1);
});

it('replaces options on update and keeps unrelated assets', function (): void {
    $menu = $service->create([...], [['digit' => '1', 'action' => 'extension', 'action_data' => '2000']]);
    $service->update($menu, ['name' => 'Renamed'], [['digit' => '2', 'action' => 'extension', 'action_data' => '2001']]);
    expect($menu->fresh()->options->pluck('digit')->all())->toBe(['2']);
});

it('deletes every owned media asset with the menu', function (): void {
    $assetA = app(MediaStorageServiceInterface::class)->storeLocal($menu, MediaCategory::IvrGreeting, $sourceA, 'a.wav', 'audio/wav');
    $assetB = app(MediaStorageServiceInterface::class)->storeLocal($menu, MediaCategory::IvrGreetingShort, $sourceB, 'b.wav', 'audio/wav');
    $service->delete($menu);
    expect(MediaAsset::find($assetA->id))->toBeNull()->and(MediaAsset::find($assetB->id))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenuServiceWriteTest.php`
Expected: FAIL — signature mismatch.

- [ ] **Step 3: Implement**

Wrap writes in `DB::transaction()`; `update()` deletes option rows absent from the payload and upserts the rest by digit. `delete()` iterates `mediaAssets` before `$menu->delete()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenuServiceWriteTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus/src/Services app-modules/ivr-menus/tests/IvrMenuServiceWriteTest.php
git commit -m "feat(ivr): persist options transactionally and clean up owned media"
```

---

### Task 5: `ivr.conf` generator rewrite

**Files:**
- Modify: `app/Services/Xml/SofiaConfigXmlBuilder.php:330-411`
- Test: `tests/Feature/Http/XmlHandlerFixtureTest.php` (update `serves ivr.conf with tenant IVR menu entries`, add cases), `tests/Feature/Pbx/PbxXmlValidationTest.php:213-219`

**Interfaces:**
- Consumes: `IvrDestinationRenderer::render()` and `IvrMenu::mediaAssets()`.
- Produces: `<menu name="<uuid>" …>` with `greet-long`, `greet-short`, `invalid-sound`, `exit-sound`, `timeout` (ms), `inter-digit-timeout` (ms), `max-failures`, `max-timeouts`, `digit-len`, optional `exec-on-max-failures`/`exec-on-max-timeouts`; entries in option order; direct-dial regex entry last; all tenants served for `ivr.conf` regardless of domain.

- [ ] **Step 1: Write the failing tests**

```php
it('serves ivr.conf menus keyed by uuid for all tenants', function (): void {
    // two tenants, same menu name 'Main'
    expect($xml)
        ->toContain('<menu name="'.$menuA->id.'"')
        ->toContain('<menu name="'.$menuB->id.'"')
        ->toContain('exec-on-max-timeouts="transfer 0"');
});

it('renders entries, nesting, playback and direct dial in order', function (): void {
    expect($xml)
        ->toContain('<entry action="menu-exec-app" digits="1" param="transfer user/2000"/>')
        ->toContain('<entry action="menu-sub" digits="2" param="'.$menuB->id.'"/>')
        ->toContain('<entry action="menu-play-sound" digits="3" param="/sounds/hours.wav"/>')
        ->toContain('<entry action="menu-exec-app" digits="/^(\d{2,10})$/" param="transfer $1"/>');
});

it('never emits channel variables in entry params', function (): void {
    expect($xml)->not->toContain('${');
});

it('renders a practical digit-len when direct dial is enabled', function (): void {
    // tenant extensions are 4 digits, direct_dial true, digit_length 0
    expect($xml)->toContain('digit-len="4"');
});

it('renders an option-less menu as an announcement', function (): void {
    // menu with greeting and zero options
    expect($xml)->toContain('<menu name="'.$menu->id.'"')
        ->toContain('greet-long="/sounds/welcome.wav"')
        ->not->toContain('<entry');
});

it('omits an unresolvable option but keeps the menu', function (): void {
    // option points at a deleted extension
    expect($xml)->toContain('<menu name="'.$menu->id.'"')
        ->not->toContain('digits="9"');
});

it('renders retry counters from the menu', function (): void {
    // menu with max_failures 3, max_timeouts 4
    expect($xml)->toContain('max-failures="3"')->toContain('max-timeouts="4"');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerFixtureTest.php --filter=ivr`
Expected: FAIL — names are friendly names, no attributes/entries of the new shape.

- [ ] **Step 3: Implement**

Drop the tenant/domain filter for `ivr.conf` (always all enabled menus; keep the no-domain fail-closed behavior for other config keys untouched). `tenantDomainFor(int $tenantId): string` = enabled `TenantDomain` with `purpose = 'sip_realm'`, else first enabled domain, else `''`. `digit-len` = `digit_length > 0 ? digit_length : (direct_dial ? max extension length clamped 2–10 : 0)`. Entries: option rows in `order`; `return_to_menu` playback → `menu-play-sound`; `return_to_menu` transfer → `transfer ivr_return_<option uuid>`; direct-dial regex entry appended last. `exec-on-max-*` values from `execString()`; omit when null.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerFixtureTest.php --filter=ivr && ./vendor/bin/pest tests/Feature/Pbx/PbxXmlValidationTest.php`
Expected: PASS after updating the two legacy assertions to the new shape.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Xml/SofiaConfigXmlBuilder.php tests/Feature/Http/XmlHandlerFixtureTest.php tests/Feature/Pbx/PbxXmlValidationTest.php
git commit -m "feat(xml): render full IVR menu definitions into ivr.conf"
```

---

### Task 6: Resolver transfer and call-flow context fix

**Files:**
- Modify: `app/Services/DestinationResolver.php:188-207`, `app-modules/call-flows/src/Services/CallFlowService.php:65`
- Test: `tests/Feature/Pbx/DialplanDestinationIntegrationTest.php:110-130`, `tests/Feature/Pbx/Services/PbxRoutingServicesTest.php` (add IVR resolver case)

**Interfaces:**
- Produces: `resolveIvr()` returns `['application' => 'transfer', 'data' => '<menu extension>']`; throws `InvalidArgumentException` when the menu has no extension.

- [ ] **Step 1: Write the failing tests**

```php
it('resolves an ivr destination to its stub extension', function (): void {
    $menu = IvrMenu::factory()->create(['tenant_id' => $tenant->id, 'extension' => '2600', 'enabled' => true]);
    expect(app(DestinationResolver::class)->resolve(DestinationResolver::KIND_IVR, '2600', $tenant->id))
        ->toBe(['application' => 'transfer', 'data' => '2600']);
});

it('rejects an ivr menu without an extension', function (): void {
    // menu with extension null -> throws InvalidArgumentException containing 'no extension'
})->throws(InvalidArgumentException::class);
```

and update the integration assertion to `data="transfer 2600"`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Pbx/Services/PbxRoutingServicesTest.php --filter=ivr && ./vendor/bin/pest tests/Feature/Pbx/DialplanDestinationIntegrationTest.php --filter=ivr`
Expected: FAIL — current data is `<name> XML <name>`.

- [ ] **Step 3: Implement**

Resolver: look up by name or id as today, throw when `extension` is null, return the transfer pair. `CallFlowService`: `transfer {$safeDest}` (no `XML default`). Keep escaping.

- [ ] **Step 4: Run tests to verify they pass**

Run the two commands from Step 2.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DestinationResolver.php app-modules/call-flows/src/Services/CallFlowService.php tests/Feature/Pbx
git commit -m "feat(routing): transfer into IVR stub extensions; fix call-flow context"
```

---

### Task 7: Edit form — menu-level parity fields and prompts

**Files:**
- Modify: `app-modules/ivr-menus/src/Livewire/IvrMenusEdit.php`, `app-modules/ivr-menus/src/Livewire/Validation/IvrMenuValidation.php`, `app-modules/ivr-menus/resources/views/ivr-menus-edit.blade.php`
- Test: `app-modules/ivr-menus/tests/IvrMenusEditorTest.php`

**Interfaces:**
- Consumes: `IvrMenuServiceInterface::create/update` option-aware signatures (Task 4).
- Produces: public properties `extension`, `greetShortUpload`, `invalidSoundUpload`, `exitSoundUpload`, `interDigitTimeout`, `maxTimeouts`, `directDial`, `ringback`, `cidPrefix`, `exitKind`, `exitDestination`, `invalidKind`, `invalidDestination`, `timeoutKind`, `timeoutDestination`; `rules()` validates the dialable-collision set.

- [ ] **Step 1: Write the failing tests** — Livewire component test using the `AdminSeeder` permission convention (AGENTS.md). Pin at least:

```php
it('saves parity fields and prompts', function (): void {
    $this->actingAs($this->admin, 'admin')
        ->post(...)  // use Livewire::test(IvrMenusEdit::class) per existing module tests
    expect(IvrMenu::first())->extension->toBe('2600')
        ->and(IvrMenu::first()->direct_dial)->toBeTrue();
});

it('rejects an extension that shadows a feature code', function (): void {
    FeatureCode::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '2600']);
    // assert validation error on 'extension'
});
```

Also cover: non-digit extension rejected; collision with `extensions.extension_number` and `call_flows.extension`; uploads stored with the new categories and resolved paths written; existing prompt kept when no upload.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenusEditorTest.php`
Expected: FAIL — unknown properties.

- [ ] **Step 3: Implement component, validation, Blade**

Props, mount hydration, save data assembly (resolve tenant, dispatch `ReloadFreeSwitchXml`), collision rules (extensions, `feature_codes.code`, `call_flows.extension`, menus), prompt uploads via `MediaStorageServiceInterface::storeLocal()` with the new categories, fallback kind/target pairs. Blade: Prompts card (four file inputs + current-path hints), Behavior grid (toggle `toggle-sm toggle-success` with "Enabled/Disabled" label, direct dial toggle, ringback select, CID prefix, two timeout fields), Fallbacks section (kind select + target input each). Follow existing tooltip/error components.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenusEditorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus/src/Livewire app-modules/ivr-menus/resources/views/ivr-menus-edit.blade.php app-modules/ivr-menus/tests/IvrMenusEditorTest.php
git commit -m "feat(ivr): expose parity fields, prompts and fallbacks in the editor"
```

---

### Task 8: Option editor rows

**Files:**
- Modify: `app-modules/ivr-menus/src/Livewire/IvrMenusEdit.php`, `app-modules/ivr-menus/resources/views/ivr-menus-edit.blade.php`
- Test: `app-modules/ivr-menus/tests/IvrOptionsEditorTest.php`

**Interfaces:**
- Produces: public array `options` of rows (`digit`, `action`, `action_data`, `description`, `return_to_menu`, `order`, `enabled`); `addOption()`, `removeOption(int $index)`, `moveOption(int $index, int $direction)`; save passes `options` to the service; `withValidator` rejects duplicate digits and unresolvable destinations via the renderer.

- [ ] **Step 1: Write the failing tests** — pin at least:

```php
it('adds and removes option rows', function (): void {
    Livewire::test(IvrMenusEdit::class)
        ->call('addOption')->set('options.0.digit', '1')
        ->set('options.0.action', 'extension')->set('options.0.action_data', '2000')
        ->call('save')
        ->assertHasNoErrors();
    expect(IvrMenuOption::where('digit', '1')->exists())->toBeTrue();
});

it('rejects duplicate digits and unresolvable destinations', function (): void {
    // two rows digit '1'; then one row with action_data '9999'
});
```

Also cover: remove/reorder rows; submenu rows ignore `return_to_menu`; save passes rows to the service in `order`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrOptionsEditorTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** — repeater state + Blade rows (digit input `input-bordered w-full font-mono`, kind select from `IvrDestinationKind::selectable()`, target input/select, description input, return toggle, enabled toggle, up/down icon buttons, delete icon button with row-dim `wire:loading.class="opacity-40 pointer-events-none"`), validation wiring, tenant-scoped target checks.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrOptionsEditorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus
git commit -m "feat(ivr): add digit-to-destination option editor"
```

---

### Task 9: List page extension column and delete reload

**Files:**
- Modify: `app-modules/ivr-menus/src/Livewire/IvrMenusList.php`, `app-modules/ivr-menus/resources/views/ivr-menus-list.blade.php`
- Test: `app-modules/ivr-menus/tests/IvrMenusListTest.php`

- [ ] **Step 1: Write the failing tests** — list shows `extension` and options count; `deleteMenu()` dispatches `ReloadFreeSwitchXml` (`Queue::fake()` + `Queue::assertPushed`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenusListTest.php`
Expected: FAIL — no extension column, no dispatch.

- [ ] **Step 3: Implement** — add the column (`badge-xs font-mono`), dispatch `ReloadFreeSwitchXml::dispatch('IVR menu deleted')` after successful delete.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenusListTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/ivr-menus
git commit -m "feat(ivr): show extensions and reload XML after deletion"
```

---

### Task 10: Translations

**Files:**
- Modify: `app-modules/ivr-menus/lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`

- [ ] **Step 1: Add keys** — extension, prompt labels for the three new prompts, behavior labels (inter-digit timeout, max timeouts, direct dial, ringback, CID prefix, fallback exit/invalid/timeout, destination kind, target, return to menu, add option, remove option, move up/down), and validation messages — in all three locales with identical key sets.

- [ ] **Step 2: Verify parity**

Run: `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app-modules/ivr-menus/lang
git commit -m "feat(ivr): add en/es/fr translations for parity fields"
```

---

### Task 11: Load-test fixtures and SIPp digit scenario

**Files:**
- Modify: `app/Console/Commands/PbxLoadTestSeedCommand.php:405-416`
- Create: `load-testing/tools/sipp/uac-ivr-digit.xml`
- Modify: `load-testing/scripts/pbx-sipp-validate.sh:55-57,417-422`
- Modify: `docs/load-testing-guide.md` (media flow destination list)

**Interfaces:**
- Produces: seeded menu `load_test_announcement` with `extension = 2600` and one option (`digit = 1`, `action = queue`, `action_data = load_test_moh`); `MEDIA_ANNOUNCEMENT_DESTINATION` default becomes `2600`; new `IVR_DIGIT_SCENARIO` and an `ivr-digit` media step.

- [ ] **Step 1: Update the seeder** — add `extension => '2600'` and the option row (delete-then-create so re-seeding stays idempotent).

- [ ] **Step 2: Create the SIPp scenario** — copy `uac-media-server-hangup.xml`, dial `[field0]` (passed destination), after the greeting `pause` 3000 ms then `send` DTMF `1`, then pause and hang up. Keep `-nostdin` usage in the script; no scenario-side stdin.

- [ ] **Step 3: Wire the script** — default `MEDIA_ANNOUNCEMENT_DESTINATION="${MEDIA_ANNOUNCEMENT_DESTINATION:-2600}"`; add `IVR_DIGIT_SCENARIO="${LOAD_TESTING_DIR}/tools/sipp/uac-ivr-digit.xml"`; add `run_media_step ivr-digit "${IVR_DIGIT_SCENARIO}" "${MEDIA_ANNOUNCEMENT_DESTINATION}" "$((MEDIA_UAC_LOCAL_PORT + 6))" "$((MEDIA_RTP_PORT + 6))"` inside the `MEDIA_FLOW` block.

- [ ] **Step 4: Verify statically**

Run: `bash -n load-testing/scripts/pbx-sipp-validate.sh && xmllint --noout load-testing/tools/sipp/uac-ivr-digit.xml`
Expected: both exit 0. (A live `MEDIA_FLOW=1` run happens on the real harness — this task's plan-level gate is syntax only; record the live-run result with the release checklist.)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PbxLoadTestSeedCommand.php load-testing docs/load-testing-guide.md
git commit -m "test(ivr): seed an extension-backed menu and press a digit in SIPp"
```

---

### Task 12: Documentation, changelog, verification gate

**Files:**
- Modify: `docs/parity-comparison.md:72`, `CHANGELOG.md` (`[Unreleased]`)

- [ ] **Step 1: Parity row** — replace the IVR row with an evidence-backed claim (greet-long/short, options with typed destinations, invalid/timeout retries with destinations, direct dial, nested menus, exit action) and note the external-destination risk.

- [ ] **Step 2: Changelog** — `Added` (interactive options, direct dial, nesting, fallbacks, prompts), `Changed` (native `ivr` engine; menus dialed by extension number, not name; `ivr.conf` UUID keys), `Fixed` (delete reload; parity overclaim), and a note that this requires `tpbx migrate`.

- [ ] **Step 3: Run the verification gate**

```bash
tpbx optimize:clear
./vendor/bin/pint --dirty
./vendor/bin/pest app-modules/ivr-menus tests/Feature/Pbx tests/Feature/Http/XmlHandlerFixtureTest.php tests/Feature/Architecture/TranslationParityTest.php
tpbx route:list --name=ivr-menus
```

Expected: Pint clean; all tests pass; four routes listed. Full suite (`tpbx test --compact --parallel`) once before commit per AGENTS.md.

- [ ] **Step 4: Commit**

```bash
git add docs/parity-comparison.md CHANGELOG.md
git commit -m "docs(ivr): record parity and release notes for interactive IVRs"
```
