# IVR Parity Implementation Plan

> Execute the tasks in order using the implementation workflow agreed for the change. Each task has a test or evidence gate. Keep each planned commit runnable on the TallPBX `5.x` series.

**Goal**

Give TallPBX administrators interactive auto-attendants with typed destinations, reusable prompts, and tenant-safe call routing while preserving existing announcement menus and inbound routes.

**Architecture**

FreeSWITCH's native IVR collects digits and manages navigation and retries. A TallPBX dialplan entry starts the menu. FreeSWITCH asks TallPBX's XML Handler for that call's menu tree, and generated dialplan actions route the call in the owning tenant's context. Announcement-only menus play once and follow their exit action or hang up.

**Tech stack**

Laravel 13, Livewire 4, Pest 4, MariaDB, FreeSWITCH 1.11, SIPp.

**Design**

[IVR parity design](../specs/2026-11-27-ivr-parity-design.md)

## Global constraints

- Follow `AGENTS.md`, including the existing UI and permission conventions, strict types, PHPDoc, focused Pint and Pest checks, and `tpbx optimize:clear` after code changes.
- Serve routing artifacts through the XML Handler and `mod_xml_curl`. Escape XML attributes with `ENT_XML1 | ENT_QUOTES` and validate FreeSWITCH arguments for their own format. Keep `ivr.conf` entry arguments concrete.
- Apply an explicit tenant constraint whenever bypassing a tenant scope. When a child row lacks `tenant_id`, scope through its parent. UUIDs identify records and the call's verified tenant context determines access.
- Keep `file-stores` as the hard IVR module dependency. Keep media in managed local files. Allow tenant recordings as an optional shared source and support inline uploads for installs without the recordings module.
- Require a digit-only extension of at most 20 characters on newly saved menus. Enforce menu-to-menu uniqueness with a database constraint and check other dialable records before saving. Keep existing menus reachable during the upgrade.
- Keep PHP-FPM's fast XML Handler and the optional [Laravel Octane](https://laravel.com/docs/octane) XML worker on the same runtime contract. The fast handler can fall back to the full application on an exception. Use the existing XML route and test representative requests through the serving paths. [Operations guide](../../operations.md#octane-xml-worker-optional-performance-mode)
- Add matching English, Spanish, and French keys with the UI that uses them. Use `AdminSeeder` for panel test permissions.

## Acceptance cases

| Case | Required result | Task |
| --- | --- | --- |
| Existing menu with no extension and a named inbound route | Upgrade preserves the route and the greeting plays once. A later extension assignment keeps stored typed routes valid. | 1, 4, 7 |
| Inbound caller dials an extension or a digit that overlaps direct dial | Only an enabled, eligible extension in the owning tenant can be reached. A configured option wins for its exact sequence. | 3, 4, 7 |
| Destination answers and hangs up, is busy, or never answers | Destination types that support return replay the IVR once if the caller is still connected. Terminal destinations leave the menu. | 4, 7 |
| Menu has no effective entries, invalid input, or no input | An announcement plays once. An interactive menu exhausts the selected counter and invokes one applicable fallback or exits cleanly. | 4, 7 |
| Tenant uses a shared realm or requests another tenant's menu, recording, or destination | The PBX-provided tenant context determines access. Other tenants' records stay out of the response and cannot be reached. | 0, 2–4, 7 |
| An option target disappears or a prompt upload fails partway | A stale target is omitted. A failed write leaves earlier menu, option, and prompt data playable. Retired files can be cleaned up safely on retry. | 2–4, 7 |

---

### Task 0 — Record the native IVR request and call semantics

**Inputs**

`app/Http/Controllers/Api/XmlHandlerController.php`, `app/Services/DialplanContext.php`, and the installed FreeSWITCH build.

- [ ] In a disposable TallPBX lab, use a throwaway fixture served by the authenticated XML Handler to invoke native `ivr`. Capture `mod_xml_curl` requests during an internal IVR call and an inbound call, with credentials and call data redacted. Record the actual request method and field names for `Menu-Name`, caller context, and configuration name. FreeSWITCH attaches the menu name and channel data each time `ivr` executes. [FreeSWITCH 1.11 source](https://github.com/signalwire/freeswitch/blob/v1.11.0/src/mod/applications/mod_dptools/mod_dptools.c#L2156-L2189)
- [ ] Confirm that both call paths can provide a tenant-scoped context. If the inbound call starts in a shared context, have the tenant-resolved inbound route transfer to the owning tenant's internal context before invoking the IVR. Confirm this in the capture. A request with missing, ambiguous, or conflicting ownership must produce an empty menu response.
- [ ] Record the installed version, captured request shape, and evidence for a terminal transfer, a returning bridge, and an invalid or timeout fallback. Carry the request shape into the Task 4 tests. Use the tested FreeSWITCH build for the final live gate.

### Task 1 — Add backward-compatible schema and menu identifiers

**Files**

`app-modules/ivr-menus/database/migrations/`, `app-modules/ivr-menus/src/Models/IvrMenu.php`, `app-modules/ivr-menus/src/Models/IvrMenuOption.php`, and their factories.

- [ ] Add `IvrMenuModelTest.php` with a pre-upgrade fixture. Assert its name is copied to `legacy_name`, its extension remains nullable, and its greeting, enabled state, and options survive. Assert that different tenants may share extension `2600` and that the database rejects two menus with that extension in one tenant.
- [ ] Run `./vendor/bin/pest app-modules/ivr-menus/tests/IvrMenuModelTest.php` and confirm the new checks fail before the migration.
- [ ] Add the menu and option columns and defaults in the design, including the composite unique index on `tenant_id, extension`. Copy the old name to `legacy_name` for rows present during migration. Keep new rows' `legacy_name` null and keep it outside panel input. Add casts and deterministic factories. Keep the existing `mediaAsset()` relation and current XML callers working at this checkpoint.
- [ ] Audit stored custom IVR option values and references in the target 5.x upgrade fixtures. Convert only known legacy actions with equivalent typed behavior and record any entries requiring explicit operator mapping before cutover. Preserve all original rows until they are mapped.
- [ ] Re-run the model tests, existing greeting storage test, and IVR XML fixtures. Commit the additive schema.

### Task 2 — Save options and prompt sources with recoverable media handling

**Files**

`app-modules/ivr-menus/src/Services/IvrMenuService.php` and its interface, new `IvrMenuPrompt` model, migration and cleanup job, and the IVR prompt categories and permission mapping in `app-modules/file-stores/src/`.

**Service contract**

`create(array $data, array $options = [], array $promptUploads = []): IvrMenu` and `update(IvrMenu $menu, array $data, ?array $options = null, array $promptUploads = []): IvrMenu`. An option row contains `digit`, `action`, `action_data`, `description`, `return_to_menu`, `order`, and `enabled`. Omitted options preserve existing rows. An explicit empty list clears them. Uploads are keyed by prompt slot and contain the validated local source path, original filename, and MIME type.

- [ ] Add `IvrMenuServiceWriteTest.php` and extend `IvrGreetingStorageTest.php`. Cover omitted versus empty options, model events for option-only saves and deletes, retention of stable option IDs for unchanged digits, two stored prompt categories on one menu, and replacement of only one category. Induce a failure after staging an upload and confirm the previous menu, options, and prompt bytes survive. Induce a cleanup failure and verify retry marks the retired asset `Missing` and removes its file.
- [ ] Test one shared tenant recording used by two menus. Assert that deleting either menu leaves that recording available, another tenant's recording cannot be selected, and a disabled or deleted recording never falls back to a stale filename.
- [ ] Run the two targeted tests and confirm failures in the new behavior.
- [ ] Keep the existing one-asset-per-owner contract in `file-stores`. Give each private prompt upload an immutable `IvrMenuPrompt` owner UUID with a tenant, slot, nullable menu link, and lifecycle state. Store the local file as pending. In the outer menu transaction, lock the menu for update, save the menu and options, attach the pending revision, and retire the previous private revision for that slot. Keep each referenced recording as a recording UUID in the menu's `prompt_recordings` mapping.
- [ ] Use the existing local managed store and the retryable media deletion job for retired assets. Record retirement in the transaction and schedule cleanup after commit. Reconcile pending uploads abandoned by a failed or interrupted save and retired uploads whose cleanup dispatch failed. Check state under a lock before cleanup, and keep replaced files available for the active routing-cache lifetime so cached actions can finish lookup. When deleting a menu, record retirement before removing it and keep sufficient durable metadata for cleanup to finish. Preserve the old greeting relation and its legacy-path read until the runtime reads in Task 4 use the new prompt resolver.
- [ ] Validate dialable numbers in the service as well as the form. Check enabled and disabled records in the tenant for reserved numbers, including extensions, feature codes, call flows, other menus and their legacy aliases, and exact dialable destinations already claimed by a higher-priority contributor. Reserve a menu's legacy name against reuse by another menu in the same tenant. Report the conflicting type. Exercise the database constraint for simultaneous menu writes.
- [ ] Re-run the media and service tests and `tests/Feature/Observers/RoutingCacheObserverTest.php`. Commit the write and cleanup behavior.

### Task 3 — Define typed, tenant-safe destination actions

**Files**

New `app-modules/ivr-menus/src/Enums/IvrDestinationKind.php` and `app-modules/ivr-menus/src/Support/IvrDestinationRenderer.php`, `app/Services/DestinationResolver.php`, and the affected call-center and bridge runtime producers.

- [ ] Add `IvrDestinationRendererTest.php` and extend `tests/Feature/Pbx/Services/PbxRoutingServicesTest.php`. Cover every kind, deleted/disabled and foreign-tenant targets, nested IVR validation, recording UUIDs versus separately selected host-approved sound paths, and an unavailable optional module. Assert that same-name queues and conference bridges from two tenants resolve to distinct runtime identities. Assert that a queue's resolved name exactly matches its `callcenter.conf` registration and ordinary dialplan route.
- [ ] Run the tests and observe failures for missing typed rendering and the bare queue name.
- [ ] Define `IvrDestinationKind` for extension, ring group, voicemail, conference, queue, external number, playback, submenu, and menu navigation. In `IvrDestinationRenderer::resolve(IvrDestinationKind $kind, string $identifier, int $tenantId): ?array`, produce an allowlisted FreeSWITCH application/data pair for executable targets and return null for invalid or unavailable targets. Resolve navigation and submenu entries explicitly. Reuse the tenant-scoped route resolver where its output matches the target's runtime contract. Catch expected stale-target lookup failures and log the menu or option ID and kind without the private destination data.
- [ ] For IVR extension and ring-group bridges, resolve tenant-correct dial strings and preserve target eligibility. For voicemail, use the target tenant's correct local mailbox/domain binding. For external destinations, accept only validated phone numbers and use the tenant's normal outbound route and gateway rules.
- [ ] Use UUID-based FreeSWITCH queue and conference-room identifiers wherever names can collide. Update the configuration renderer, normal callers, and resolver together for each changed identifier, including any queue membership or agent consumers found by the call-site audit. Preserve display names, profiles, and PIN behavior. Inventory any saved raw FreeSWITCH references that need a mapping, then cover the existing direct queue or conference route in the same change.
- [ ] Re-run the renderer, routing, and affected module tests. Commit the matching configuration and consumers together.

### Task 4 — Switch dialplan and scoped `ivr.conf` rendering together

**Files**

`app-modules/ivr-menus/src/Services/IvrMenuService.php`, `app/Http/Controllers/Api/XmlHandlerController.php`, `app/Services/Xml/SofiaConfigXmlBuilder.php`, `app/Services/DestinationResolver.php`, and `app-modules/call-flows/src/Services/CallFlowService.php`.

- [ ] Write failing tests in `app-modules/ivr-menus/tests/IvrDialplanXmlTest.php`, `tests/Feature/Http/XmlHandlerFixtureTest.php`, `tests/Feature/Pbx/PbxXmlValidationTest.php`, and `tests/Feature/Pbx/DialplanDestinationIntegrationTest.php`. Test the captured GET and POST request data, known and mismatched context, two tenants with shared realm and identical menu names, nesting, disabled menus, stale targets, and absence of `${` in entry arguments. Assert that a known caller gets only its root and reachable same-tenant submenus. Assert other configuration keys retain their behavior.
- [ ] Add XML assertions for the older named inbound route, numeric dialing, renumbering, literal option versus direct dial, invalid/timeout versus general exit, missing greeting, one-shot announcement, and a returning bridge. Assert the exact action order and transfer context. Cover `CallFlowService` in both tenant contexts for the transfer affected by this change.
- [ ] Run `./vendor/bin/pest app-modules/ivr-menus/tests/IvrDialplanXmlTest.php tests/Feature/Http/XmlHandlerFixtureTest.php tests/Feature/Pbx/PbxXmlValidationTest.php tests/Feature/Pbx/DialplanDestinationIntegrationTest.php` and observe the legacy output failures.
- [ ] Render a stable `ivr_entry_<menu UUID>` entry plus exact matches for the assigned extension and pre-upgrade name alias. Quote legacy names as literal regex values before XML escaping. Resolve existing stored identifiers by UUID, then legacy name, then current name. Return `transfer ivr_entry_<uuid> XML <tenant internal context>` even when an upgraded menu has no number. Normalize the affected call-flow transfer to the correct tenant context. Verify the original name alias still works after renaming or renumbering the menu.
- [ ] In the XML Handler, special-case only `ivr.conf` and resolve the requested root inside the tenant established by Task 0. Return its reachable enabled same-tenant submenus and bound traversal with a visited set. Return an empty valid configuration on unknown, disabled, mismatched, or unscoped requests. Add no cross-tenant fallback or switch-wide cache. Take account of the routing-cache revision for generated dialplan when an option, destination, recording, or prompt changes.
- [ ] Build terminal entries and both retry-exhaustion hooks as a single transfer to a tenant-scoped action extension. Require a channel marker set by the owning IVR entry or an equivalent dialplan condition that proves the call entered this tenant's IVR. Each retry hook executes its menu's resolved destination, then falls back to that menu's general exit or hangup only when the retry destination is missing or stale. End cleanly when a terminal application returns. Normal native exit and one-shot announcements use the general exit once. A fallback transfer leaves the menu and owns the call's next action.
- [ ] Allow return for playback and resolvable extension or ring-group bridges. Playback uses `menu-play-sound`. A bridge return action sets `hangup_after_bridge=false` and permits continuation for the supported busy/no-answer outcomes, bridges once, then transfers back to the option's source menu if the caller is still connected. Never chain two transfers in one extension. Submenu navigation uses native back/top actions and the return flag is unavailable for terminal destinations. A submenu with no effective entries plays once through its announcement entry.
- [ ] Build direct dial from exact, eligible tenant extension numbers, excluding the exact sequences of configured effective options. Target the tenant internal route. Use the length of the longest effective option or eligible number as the minimum `digit-len` and test shorter input at the inter-digit timeout. Keep numeric options and supported DTMF navigation keys consistent with FreeSWITCH's terminator setting. Escape and order all emitted values deterministically.
- [ ] Use the same effective menu data in both generators. For an option-less menu with direct dial off, or a menu whose effective entries all disappeared, play the greeting once and run its exit or hangup without invoking `ivr`. Handle a missing or unreadable greeting deterministically with its configured exit or hangup. Retain an interactive menu's invalid and timeout retry controls once entries exist. Set presentation settings on entry without stacking the CID prefix on a return.
- [ ] Re-run the XML, routing, and cache observer tests. Commit the runtime switch as one unit.

### Task 5 — Expose menu, prompt, and option editing

**Files**

`app-modules/ivr-menus/src/Livewire/IvrMenusEdit.php`, `app-modules/ivr-menus/src/Livewire/Validation/IvrMenuValidation.php`, `app-modules/ivr-menus/resources/views/ivr-menus-edit.blade.php`, and module translations.

- [ ] Add `IvrMenusEditorTest.php` and `IvrOptionsEditorTest.php`. Cover legacy hydration and numbering, collisions with extensions, feature codes, call flows, and menus, fallback validation, distinct prompt sources, omitted uploads, duplicate digit strings, absent or foreign targets, row removal and reordering, and which destinations support return. Verify option-only saves trigger the appropriate routing invalidation after commit. Use the repository's `AdminSeeder` setup for panel actors.
- [ ] Run the two files and confirm the new behavior fails.
- [ ] Inspect existing peer forms and use their DaisyUI sizes, semantic colors, tooltip/error treatments and loading feedback. Add the extension, timeout/retry controls, direct-dial toggle, ringback preset, CID prefix, exit/invalid/timeout selectors, and repeatable option rows. Use the design's preset values for both ringback variables. Enforce destination-specific input rules, supported DTMF lengths, valid prompt sources, and digit uniqueness against the persisted owner. An entry greeting must be playable on new saves.
- [ ] Offer an enabled recording from the current tenant when the module is available, a private audio upload, or a host-approved FreeSWITCH sound/path for each prompt. Show its current source and keep it when the replacement is omitted. Add matching `en`, `es`, and `fr` translations with the form.
- [ ] Re-run both tests and `tests/Feature/Architecture/TranslationParityTest.php`. Commit the form and its translations together.

### Task 6 — Show extension and deletion state in the list

**Files**

`app-modules/ivr-menus/src/Livewire/IvrMenusList.php`, `app-modules/ivr-menus/resources/views/ivr-menus-list.blade.php`, and module translations.

- [ ] Add `IvrMenusListTest.php` covering the extension column, a visible label for upgraded menus awaiting a number, option count, and removal of a deleted menu from subsequent XML lookups. Confirm post-commit routing invalidation and scheduling of any required switch reload.
- [ ] Run the test and see the new assertions fail.
- [ ] Add the extension column and translated state using standard badges and loading feedback. Send reload work after commit only where needed for dependent switch configuration. A new IVR lookup must reflect the deletion through database state and routing invalidation.
- [ ] Re-run the list and translation tests. Commit the list UI and its translations together.

### Task 7 — Prove the feature through live SIP calls

**Files**

`app/Console/Commands/PbxLoadTestSeedCommand.php`, `tests/Feature/Pbx/PbxLoadTestValidationTest.php`, new scenarios in `load-testing/tools/sipp/`, `load-testing/scripts/pbx-sipp-validate.sh`, and `docs/load-testing-guide.md`.

- [ ] Follow the [harness guide](../../../load-testing/README.md). Seed two separately owned fixtures, keeping the announcement on `2600` with no options and using `2601` for an interactive menu. Seed repeatably with the existing fixture ownership and reset rules. Assert both fixtures and option mappings in Pest.
- [ ] Deliver negotiated DTMF to the interactive fixture. For the queue option, assert the exact queue-join event correlated to the tested call, or require an expected destination SIP leg to receive and answer its INVITE. Assert that the same scenario fails when the digit-to-target mapping is removed or wrong. Keep the announcement scenario's one-prompt and server hangup check separate.
- [ ] Add a live functional IVR mode to the SIPp harness and document its setup, fixtures, verdicts, and artifacts. Preserve the existing standard comparison matrix as a separate benchmark contract and keep `-nostdin` for SIPp. Exercise shell and XML syntax checks before the lab run.
- [ ] On the target FreeSWITCH build, verify internal and inbound digit routing, a returning answered bridge and busy/no-answer path, literal precedence, direct dial, nested back/top, isolated same-name targets, voicemail, invalid and no-input fallbacks including a multi-word destination, general exit, a stale destination, and a pre-upgrade announcement route. Record destination-specific evidence and verify that a caller hangup during a bridge prevents re-entry.
- [ ] Record the tested SHA, commands, switch version, case verdicts and log artifacts. A SIP response from the IVR itself establishes that the menu answered. A named destination verdict establishes that the option routed. Successful live verdicts are required before a parity claim or release.
- [ ] Re-run `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php`, run `bash -n load-testing/scripts/pbx-sipp-validate.sh`, and run `xmllint --noout` on all new scenarios. Commit the fixtures, harness, and guide.

### Task 8 — Document administrator impact and verify the change

**Files**

`docs/parity-comparison.md`, `CHANGELOG.md`, and the IVR administrator documentation referenced by the parity comparison.

- [ ] Explain prompt reuse, dialable extensions, and the handling of upgraded menus and legacy aliases. Record `tpbx migrate` and any required operator mapping for previously raw IVR or named runtime destinations. Document in the parity row and changelog that a public IVR option can place a billable outbound call subject to the tenant's configured outbound rules. Base the parity row on live results and state the supported return behaviors precisely.
- [ ] Run the project verification gate on the completed implementation. Run the full parallel suite once for the cross-subsystem change and follow the shared XML hot-path smoke check in `AGENTS.md`.

```bash
tpbx optimize:clear
./vendor/bin/pint --dirty
./vendor/bin/pest app-modules/ivr-menus tests/Feature/Pbx tests/Feature/Http/XmlHandlerFixtureTest.php tests/Feature/Observers/RoutingCacheObserverTest.php tests/Feature/Architecture/TranslationParityTest.php
tpbx route:list --name=ivr-menus
tpbx app:test --smoke
tpbx test --compact --parallel
```

- [ ] Verify the three existing authorized IVR panel routes, check the recorded live verdicts from Task 7, and commit the evidence-backed documentation. Use the [release checklist](../../release-checklist.md) when the change ships.
