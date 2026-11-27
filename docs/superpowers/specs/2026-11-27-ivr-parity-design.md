# IVR Parity Design — Interactive IVR Menus (Approach A: FreeSWITCH Native Engine)

Date: 2026-11-27
Status: Draft (builder-checked and owner-review fixes applied 2026-11-27; pending owner approval)

## Intent & Why

TallPBX ships an `ivr-menus` module that can only announce: a greeting plays and the
call ends. FusionPBX and FreePBX both ship full auto-attendants — digit menus with
destinations, retry counters, fallback destinations, direct dial, nested menus. The
project's day-one mandate is feature and function parity with FusionPBX; this design
closes the IVR gap so an administrator can build the same IVR in TallPBX as in either
product. Copying FusionPBX's implementation is not required — accomplishing the same
things is.

Today's state (evidence):
- `ivr_menus` / `ivr_menu_options` tables exist; options are never writable from the
  panel (only tests/factories create rows).
- The dialplan contributor emits a `bind_digit_action` + `read` digit path whose prompt
  (`'touchexec/'`) resolves to nothing — the interactive path never worked at runtime.
  Only announcement-only menus are proven by tests and SIPp media-flow runs.
- `SofiaConfigXmlBuilder` already renders `ivr.conf` `<menu>` entries that nothing
  invokes (`menu-exec-app` entries; no `application="ivr"` anywhere).
- `docs/parity-comparison.md` claims "Full Parity" for IVR — false today; must be
  corrected regardless of this work.

## Goals

An administrator can build, from the panel, an IVR that does everything FusionPBX's
and FreePBX's IVR editors do:

1. **Menu**: name, description, dialable extension number, enabled toggle.
2. **Prompts**: entry greeting (greet-long), re-entry/short greeting (greet-short),
   invalid-input prompt, exit prompt — each uploadable audio (wav/mp3/ogg) or a raw
   FreeSWITCH path for advanced users.
3. **Option rows**: digit (literal or multi-digit) → typed destination, with
   description, display order, enabled flag, and a "return to menu after action"
   flag (FreePBX's *Return*). Option destinations cover the standard kinds
   (extension, ring group, voicemail, conference, queue, external number, IVR
   submenu) plus **announcement playback** ("press 3 to hear our hours" — the
   canonical IVR example; FusionPBX `playback:file`, FreePBX *Announcement*
   destination).
4. **Digit handling**: timeout (first digit), inter-digit timeout, invalid-input
   retries, timeout retries — each with its own fallback destination when retries
   run out (FreePBX's *invalid destination* / *timeout destination*; FusionPBX's
   *exit action* covers the general exit case).
5. **Direct dial**: when enabled, callers can dial tenant extension numbers directly
   from the menu (regex entry with capture groups, FreeSWITCH-native).
6. **Nested menus**: an option may enter another IVR menu (`menu-sub`), with
   `menu-back`/`menu-top` navigation available as option actions.
7. **Call presentation**: ringback preset and caller-ID name prefix applied while in
   the menu (FusionPBX fields, cheap to implement as channel variables).

## Non-Goals

- TTS prompts (`say:`), phrase-macro prompts, PIN-gated menus, confirm-macro
  read-back. The FreeSWITCH engine supports them; a later release can expose them.
  Schema and renderers will not block adding them.
- `mod_httapi`-style per-call HTTP call control (explicitly rejected in the approach
  discussion).
- Multi-language per menu (FusionPBX language/dialect/voice fields). TallPBX's
  prompt uploads carry their own audio; TTS language belongs with the TTS non-goal.
- Replacing the existing `destinations` catalog module or changing `CallFlowService`
  behavior beyond what is needed for IVR routing.

## Anti-Goals

- No interactive digit path may be emitted that has not been proven end-to-end in a
  live call test (SIPp). The `touchexec`-style stub must never ship again; tests must
  assert runtime artifacts AND behavior, not XML shape alone.
- No global-name collisions between tenants in FreeSWITCH's shared menu namespace.
- No raw user strings interpolated into FreeSWITCH XML without `ENT_XML1|ENT_QUOTES`
  escaping and (for identifiers) regex/context safety.
- IVR options may target external PSTN numbers (`sofia/external/...`). This is
  FusionPBX/FreePBX parity and an **accepted, documented risk** — outbound control
  remains the tenant's gateway/outbound-route domain, and the surface is no broader
  than an inbound route to the same number. It must be named in the parity row and
  the CHANGELOG, not silently accepted.

## Constraints

- House rules: `declare(strict_types=1)`, Pint on dirty PHP, `tpbx optimize:clear`
  after changes, permissions via module `permissions()`, translations with en/es/fr
  parity, UI conventions (DaisyUI tokens, `toggle-sm`, `badge-xs font-mono`,
  present-continuous toggle labels, pointer-events locks).
- FreeSWITCH integration rules: no static XML files on disk; all XML via the XML
  Handler (`mod_xml_curl`); media stored as files with paths in DB; tenant isolation
  in every query (`withoutGlobalScope('tenant')` + `tenant_id` filter); routing-cache
  invalidation via `RoutingCacheObserver` / `RoutingCacheVersion`.
- FreeSWITCH engine facts (verified against the FreeSWITCH manual, Chapter 21 — IVR
  Menus): menus live in one global namespace keyed by `name`; `<entry>` `digits`
  accepts literal strings or PCRE regex with `$1..$n` capture expansion in `param`;
  entries are evaluated in document order, first match wins; `menu-exec-app` runs an
  app and **leaves** the menu; `menu-sub` pushes a submenu; `menu-top`/`menu-back`
  replay `greet-short`; `exec-on-max-failures` / `exec-on-max-timeouts` accept an
  `application argument` string; the call must be answered before `ivr` runs.

## Approaches Considered

| # | Approach | Trade-off | Verdict |
|---|----------|-----------|---------|
| A | **FreeSWITCH native IVR engine** (`ivr` app + `ivr.conf` menus) | Battle-tested digit collection, retry counters, nesting, regex direct dial — all free from `mod_dptools`. Menu registry is global (needs UUID keying). `ivr.conf` is loaded at `reloadxml`, so config fetches must stay consistent. | **Chosen** |
| B | Hand-built dialplan digit loop (fix `bind_digit_action`/`read`, build retry loops in XML) | Everything stays in per-call cached contributor XML; but re-implements timeout/failure counting in a stateless XML dialplan, no confirm/regex capture support, high bug surface. This is the path that already stalled. | Rejected |
| C | `mod_httapi` per-step call control | Maximum flexibility (future AI receptionist), but web app on the live-call path per prompt; contradicts compile-to-dialplan architecture. | Rejected |

FusionPBX uses exactly the Approach-A skeleton (verified in
`app/ivr_menus/ivr_menu_edit.php`: it compiles a dialplan extension that sets env vars
and calls `application="ivr" data="<ivr_menu_uuid>"`, with the menu rendered into
`ivr.conf`). Our differences: no materialized dialplan rows (contributor renders at
lookup time), typed `DestinationResolver` destinations instead of raw `app:param`
strings, UUID-keyed menus (same trick FusionPBX uses), and `RoutingCacheObserver`
invalidation instead of manual per-UUID cache deletes.

## Design

### 1. Data model

Migration on `ivr_menus` (all nullable or defaulted, safe on existing rows):

| Column | Type | Purpose |
|--------|------|---------|
| `extension` | string(20), nullable | Dialable number reaching the menu's stub extension. Validated on save: digits only, unique per tenant, must not collide with an existing `extensions.extension_number` or another menu. Nullable only to tolerate pre-existing rows; the form requires it. |
| `greet_short` | text, nullable | Prompt on menu re-entry (`greet-short`). Legacy/path column mirroring `greeting`. |
| `invalid_sound` | text, nullable | Invalid-input prompt (`invalid-sound`). |
| `exit_sound` | text, nullable | Exit prompt (`exit-sound`). |
| `inter_digit_timeout` | integer, default 3 (seconds) | Rendered as ms. |
| `max_timeouts` | integer, default 3 | Timeout retries before `exec-on-max-timeouts`. |
| `direct_dial` | boolean, default false | Enables the extension-catchall regex entry. |
| `ringback` | string, nullable | Preset name from the fixed list settled below (`default`, `us-ring`, `uk-ring`, `fr-ring`, `de-ring`, `custom`). Rendered as `ringback`/`transfer_ringback` channel vars. |
| `cid_prefix` | string(20), nullable | Rendered as `caller_id_name=<prefix>#${caller_id_name}` + `effective_caller_id_name`. |
| `exit_kind` / `exit_data` | string, nullable | Typed destination run after the menu exits (FusionPBX exit action). |
| `invalid_kind` / `invalid_data` | string, nullable | Typed destination for `exec-on-max-failures`. |
| `timeout_kind` / `timeout_data` | string, nullable | Typed destination for `exec-on-max-timeouts`. |

Existing columns keep their meaning: `name` (display + uniqueness per tenant),
`greeting` (entry prompt), `timeout` (first-digit wait, seconds → ms at render),
`max_failures` (invalid-input retries), `digit_length` (0 = engine auto-grows to the
longest literal entry), `description`, `enabled`.

`ivr_menu_options` changes:

| Column | Change |
|--------|--------|
| `action` | Reinterpreted as **destination kind** (`extension`, `ring_group`, `voicemail`, `conference`, `queue`, `external`, `playback`, `ivr` for submenu, `menu_action` for `top`/`back`/`exit` navigation). Same vocabulary as `DestinationResolver::routeSelectableKinds()` plus `playback`/`ivr`/`menu_action`; `playback` stays IVR-option-only and does not enter `routeSelectableKinds()`. |
| `action_data` | Reinterpreted as the **destination identifier** (menu UUID for `ivr`, `top`/`back`/`exit` for `menu_action`). |
| `return_to_menu` | New boolean, default false (FreePBX *Return*). |

Typed destination triplets (`*_kind`/`*_data`) follow the `DestinationResolver`
contract; each is validated at save time by resolving it (`resolve()` must succeed
for the tenant) and at render time again (target may have been deleted).

Media: prompts use the managed-media pattern already used for `greeting`
(`MediaAsset` morph, `MediaCategory::IvrGreeting`, resolved local path written to the
path column). Add `MediaCategory` cases `IvrGreetingShort`, `IvrInvalidPrompt`,
`IvrExitPrompt`; change `IvrMenu::mediaAsset()` (morphOne) to `mediaAssets()`
(morphMany) filtered by category, since one menu now owns several assets. Register
the new categories in `MediaAssetAuthorizationService::$CategoryPermissions` under
`ivr-menus.view`. Deleting a menu requests deletion of all owned assets (existing
pattern in `IvrMenuService::delete`). Playback options reference `recordings`
entries; `ivr-menus` keeps only its hard dependency on `file-stores` in
`module.json` — recording references are validated at save time when the
`recordings` module is enabled, and the raw FreeSWITCH path remains the advanced
fallback, so the module stays installable without `recordings`.

### 2. FreeSWITCH artifacts

**a. Stub extension** (replaces the current `ivr_<name>` extension; emitted by
`IvrMenuService::generateDialplanXml`, still a `ContextWideDialplanXmlContributor`
at priority 70). One per enabled menu, matched by extension number; the name is
escaped from the UUID, never from user input:

```xml
<extension name="ivr_<uuid>">
  <condition field="destination_number" expression="^<extension>$">
    <action application="answer"/>
    <action application="sleep" data="1000"/>
    <action application="set" data="hangup_after_bridge=true"/>
    <!-- optional, only when configured: -->
    <action application="set" data="ringback=<preset>"/>
    <action application="set" data="transfer_ringback=<preset>"/>
    <action application="set" data="caller_id_name=<prefix>#${caller_id_name}"/>
    <action application="set" data="effective_caller_id_name=${caller_id_name}"/>
    <action application="ivr" data="<uuid>"/>
    <!-- optional exit action (typed destination resolved to "app data"): -->
    <action application="<exit_app>" data="<exit_data>"/>
  </condition>
</extension>
```

The stub is emitted in every tenant context the contributor serves, so an in-context
`transfer <extension>` always finds it. The old `^<name>$` match is removed (menu
names may contain regex metacharacters and spaces; the CHANGELOG will note the
change: dial the extension, not the name).

**b. `ivr.conf` menus** (expand `SofiaConfigXmlBuilder::buildIvrConfigurationXml`).
Menu `name` is the **UUID** (global namespace safe — the FusionPBX trick). For
`key_value = ivr.conf` the generator serves **every tenant's enabled menus
regardless of the supplied domain**, deliberately overriding the builder's usual
fail-closed domain scoping for this one key: the registry is loaded at `reloadxml`
(often without a domain), UUIDs make cross-tenant entries unreachable, and a
domain-filtered response would leave the switch's registry incomplete until the
next reload. The tenant gate remains the per-tenant stub extension and typed
resolver. Verification step in the plan: log the actual `configuration` request
shape FreeSWITCH sends during `reloadxml`. Attributes and entries:

```xml
<menu name="<uuid>"
      greet-long="<path>" greet-short="<path>"
      invalid-sound="<path>" exit-sound="<path>"
      timeout="<ms>" inter-digit-timeout="<ms>"
      max-failures="3" max-timeouts="3" digit-len="0"
      exec-on-max-failures="<app data>|omit"
      exec-on-max-timeouts="<app data>|omit">
  <!-- option rows, in `order`: -->
  <entry action="menu-exec-app" digits="1" param="transfer 2000"/>          <!-- kind=extension, no return -->
  <entry action="menu-sub" digits="2" param="<target menu uuid>"/>           <!-- kind=ivr (submenu) -->
  <entry action="menu-back" digits="*"/>                                     <!-- kind=menu_action -->
  <entry action="menu-play-sound" digits="3" param="<path>"/>                <!-- kind=playback + return_to_menu -->
  <entry action="menu-exec-app" digits="4" param="transfer ivr_return_<opt uuid>"/>  <!-- kind=extension + return_to_menu (helper extension) -->
  <!-- direct dial, appended AFTER literal entries (first match wins): -->
  <entry action="menu-exec-app" digits="/^(\d{2,10})$/" param="transfer $1"/>
</menu>
```

Key render rules:
- **Destination rendering**: each option/exit/invalid/timeout destination resolves
  through `DestinationResolver` at render time → `param="<application> <data>"`.
  `KIND_IVR` options map to `menu-sub` instead (submenu push). Transfers omit the
  `XML <context>` argument so FreeSWITCH re-enters the call's current dialplan
  context — the stub and local targets are context-wide, so this keeps public and
  internal calls correct without baking a context into the global registry.
- **Direct dial**: one regex entry `digits="/^(\d{2,10})$/" param="transfer $1"`,
  emitted only when `direct_dial` is on, always after literal option entries.
  Literal digits therefore win over the catchall (document order = match order).
- **`digit-len` vs direct dial** (builder-check fix): `digit-len="0"` auto-grows
  only to the longest **literal** entry, so with a multi-digit extension catchall
  the engine would match too early (caller dials `2000`, engine stops after the
  longest literal and matches `20`). When `direct_dial` is on and `digit_length`
  is 0 (auto), render `digit-len` as the longest extension number length in the
  tenant (clamped 2–10). Single-digit option entries still match correctly: the
  engine stops on the inter-digit timeout after the first digit (the FreeSWITCH
  demo IVR uses exactly this shape — `digit-len="4"` with single-digit literals).
  Known trade-off (same as FusionPBX/FreePBX): single-digit options can incur up
  to one inter-digit timeout of delay while direct dial is enabled.
- **`return_to_menu`**: `menu-exec-app` leaves the menu by design, so behavior
  splits by destination kind. `playback` options map to `menu-play-sound` (stays
  in the menu — exactly FreePBX *Return* for announcements) when the flag is set,
  or `menu-exec-app "playback <path>"` otherwise. Transfer-style options with the
  flag set instead target a generated helper extension
  (`ivr_return_<option uuid>`, emitted by the same contributor) that executes the
  resolved destination actions and ends with `transfer <menu extension>` back
  into the stub (re-entry plays greet-long, matching FreePBX *Return* semantics).
  Submenu (`ivr`) options ignore the flag — `menu-sub`/`menu-back` already provide
  navigation, and the panel hides the toggle for that kind.
- **`menu_action`** options map to `menu-top` / `menu-back` / `menu-exit` entries.
- All values escaped with `ENT_XML1|ENT_QUOTES`; extension/digit values additionally
  validated as digits at save time.
- **No channel variables in `ivr.conf` values**: dialplan XML expands `${...}` per
  call, but `ivr.conf` is loaded at `reloadxml` and `menu-exec-app` passes `param`
  through verbatim — there is no per-call expansion step. Every destination is
  therefore rendered **concretely** at config-render time: voicemail becomes
  `voicemail default <tenant domain> <mailbox>` (domain resolved then, never
  `${domain}`), and any kind that cannot be rendered concretely is skipped with a
  log warning. Tests assert no `${` appears in generated `ivr.conf` entry params.
- **`exec-on-max-*` parsing**: the engine receives an `application argument`
  string (split at the first space), so multi-word arguments such as voicemail are
  plausible but unproven. A live-call verification covers a multi-word fallback;
  if the engine mis-parses it, v1 constrains fallback destinations to
  transfer-family kinds (extension, ring group, conference, queue, ivr) and logs
  the restriction.

### 3. Routing integration

- `DestinationResolver::resolveIvr` changes from `transfer <name> XML <name>` to
  `transfer <extension>` (in-context). Lookup by name or UUID finds the menu as
  today; if the menu has no `extension` (legacy row), resolution throws with a
  clear message (menus are unreachable until an extension is assigned — form
  validation prevents new rows in that state).
- Inbound routes and anything using typed destinations keep working unchanged
  (they call the resolver). `CallFlowService`'s `transfer ... XML default` quirk is
  fixed alongside as a plain in-context `transfer` (one line, noted in the
  CHANGELOG).
- Load-test fixtures: `PbxLoadTestSeedCommand` seeds an announcement menu; it gains
  an `extension` value (e.g. `2600`) and the SIPp media-flow scenario dials that
  number instead of the menu name. A new SIPp scenario presses a digit and expects
  the option destination (this is the anti-goal enforcement: interactive path proven
  end-to-end).

### 4. Caching & invalidation (mostly existing)

- `RoutingCacheObserver` already covers `IvrMenu` and `IvrMenuOption` (option →
  parent tenant walk). New columns ride along.
- Fix: `IvrMenusList::deleteMenu` currently does not dispatch `ReloadFreeSwitchXml`;
  deletion must dispatch it (the `ivr.conf` registry is loaded at `reloadxml`).
  Saves already dispatch it. Same fix for option-only edits.
- The context-wide contributor cache key already embeds the routing revision.

### 5. Panel UI (Livewire, house conventions)

**Edit form** (`IvrMenusEdit` + Blade): existing fields, plus a Prompts section
(four upload fields with "leave blank to keep" behavior and current-path hints),
a Behavior grid (inter-digit timeout, max timeouts, direct dial toggle, ringback
preset select, CID prefix), and a Fallbacks section (three typed-destination
pickers: exit, invalid, timeout — each a kind `<select>` + target input). All form
labels/tooltips via module translation keys.

**Option editor** (in the same form): a repeatable row list (digit, destination
kind select, target select/input per kind, description, "Return to menu" toggle,
enabled toggle, order drag or up/down, delete with row-dim + spinner pattern).
Option rows are saved together with the menu (single `DB::transaction()`), per the
house multi-write rule. The list page's options-count badge now reflects rows
administrators can actually create from the panel.

Validation additions: `extension` required, digits, per-tenant unique, and must not
shadow any existing tenant dialable number — `extensions.extension_number`,
`feature_codes.code`, `call_flows.extension`, or another IVR menu. Option digits
unique per menu, digits/regex-safe; destinations resolvable via
`DestinationResolver` for the tenant.

### 6. Permissions & translations

No new permissions (reuse `ivr-menus.*`). New translation keys in
`app-modules/ivr-menus/lang/{en,es,fr}/admin.php` with full parity.

### 7. Tests (the verification gate)

- **Unit/XML**: rewrite `PbxXmlValidationTest` and `XmlHandlerFixtureTest` IVR
  cases to assert the stub (`application="ivr" data="<uuid>"`), `ivr.conf`
  attribute set, `menu-sub` nesting, regex direct-dial entry ordering,
  `exec-on-max-*` rendering, and destination resolution — including
  cross-tenant denial, disabled-target skipping, and a no-`${` guard over every
  generated `ivr.conf` entry param.
- **Component**: Livewire tests for the option editor (add/remove/validate/save
  transaction), extension/dialable-collision validation, and prompt upload.
- **Runtime (anti-goal enforcement)**: extend the SIPp media-flow scenario to
  press a digit and assert the destination answers; add a voicemail-option press
  and a multi-word fallback destination (the `exec-on-max-*` parsing proof);
  announcement scenario stays.
- **Update**: `DialplanDestinationIntegrationTest` (resolver transfer string),
  `RoutingCacheObserverTest`, `PbxLoadTestSeedCommand` fixtures, docs.

### 8. Documentation & release hygiene

- `docs/parity-comparison.md`: IVR row → parity claim backed by the above tests
  (greet-long/short, options, retries+destinations, direct dial, nesting, exit
  action). Also correct the pre-existing "Full Parity" overclaim history in the
  same edit.
- `CHANGELOG.md` `[Unreleased]`: Added (interactive IVR options, direct dial,
  nesting, fallback destinations, prompts), Changed (`ivr` engine adoption, dial
  by extension number instead of menu name — note the routing change), Fixed
  (delete now triggers `reloadxml`; parity doc correction). Note: requires
  `tpbx migrate`.

## Decisions Settled at Builder Check (implementer's calls, flagged for review)

- **`ringback` presets**: fixed list `default` (leave unset), `us-ring`
  `tone_stream://%(2000,4000,440,480)`, `uk-ring` `%(400,200,400,450)`,
  `fr-ring` `%(1500,3500,440)`, `de-ring` `%(1000,4000,425)`, `custom` (raw
  value, advanced). Rendered into both `ringback` and `transfer_ringback`.
- **`menu_action` (`top`/`back`/`exit`) options ship in the first cut** — three
  static entry mappings, no schema cost.
- **Legacy option rows** written directly to the DB before this change keep their
  rows but their `action` values don't match the new kind vocabulary; the
  renderer skips unresolvable rows with a log warning (the panel never wrote
  options, so no data migration is warranted).
- **Playback options source**: identifier references a `recordings` module entry
  (announcement/greeting) resolved to its stored path at render time; falls back
  to treating the identifier as a raw FreeSWITCH path (advanced).
- **Entry greeting (`greet-long`) is required** on save (FusionPBX parity: the
  field is mandatory there).
- **`CallFlowService` one-line fix taken along**: its `transfer ... XML default`
  becomes a plain in-context `transfer`, noted in the CHANGELOG.
- **Helper extensions** (`ivr_return_<option uuid>`) are emitted by the same
  `IvrMenuService` contributor, after the stubs.
- **`ivr.conf` serves all tenants regardless of domain** (see §2b) — completeness
  of the `reloadxml` registry outranks domain filtering for this key because UUID
  menu names make cross-tenant reach impossible.
- **No channel variables in `ivr.conf` values** (see §2) — destinations render
  concretely at config time; voicemail uses the tenant's resolved domain.
- **Fallback constraint if multi-word `exec-on-*` parsing fails live**: prove it
  first; only if it fails does v1 restrict fallback destinations to
  transfer-family kinds and log the restriction.
- **Dialable-collision scope**: extensions, feature codes, call-flow extensions,
  and other IVR menus are all checked before a menu extension is accepted.

## What's Left to the Builder

- SIPp scenario file details and load-test fixture numbers (announcement scenario
  retargets to the seeded menu's extension; new digit-press scenario).
- Exact upload-component wiring for the three new prompt fields (reuse the
  existing greeting upload pattern).

## Owner Decisions (already taken in conversation)

- Approach A (FreeSWITCH native engine) is chosen over a hand-built dialplan loop
  and over `mod_httapi`.
- Functional parity with FusionPBX + FreePBX standard IVR fields is the target;
  implementation may be better than FusionPBX's (typed destinations, contributor
  rendering, cache invalidation).
- Engine-supported extras (TTS, PIN, confirm macros) are explicitly deferred.
