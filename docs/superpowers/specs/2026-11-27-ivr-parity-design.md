# IVR Parity Design

**Date** 2026-11-27

**Status** Draft revised after implementation-plan review

## Intent

TallPBX administrators can use IVR menus to play announcements today. The panel has no digit-option editor, and the current generated digit loop has no proven interactive call path. This change adds a native FreeSWITCH auto-attendant with typed destinations, retry controls, direct extension dialing, nested menus, and reusable prompts. Existing announcement menus and inbound routes continue to work when a 5.x installation upgrades.

The release scope covers the standard IVR controls in FusionPBX and FreePBX. Text-to-speech, phrase and confirmation macros, PIN-gated menus, per-menu language controls, and changes to the destinations catalog remain outside this work. Publish parity claims for the behavior observed in live calls.

## Chosen approach

Use the native FreeSWITCH `ivr` application for digit collection, retries, and navigation. TallPBX supplies the menu configuration and ordinary dialplan actions. This reuses the switch's digit-handling engine and keeps destination validation, tenant scoping, and media ownership in TallPBX. A custom dialplan digit loop would duplicate the engine and preserve the failure mode in the current implementation. HTTP-based per-prompt call control would introduce more application calls during the menu. [FreeSWITCH IVR documentation](https://developer.signalwire.com/freeswitch/applications/ivr-menus/)

## Menu and option data

Add the following fields to `ivr_menus`. Keep the existing name, description, greeting, timeout, maximum failures, digit length, and enabled state.

| Field | Contract |
| --- | --- |
| `extension` | Nullable string of up to 20 digits. A new panel save requires it. The database enforces uniqueness per tenant. Validation also checks extensions, feature codes, call flows, other menus, and applicable exact routes that would shadow the menu. |
| `legacy_name` | Existing menu name copied at migration and retained as an exact dialplan alias. New rows leave it empty. |
| `greet_short`, `invalid_sound`, `exit_sound` | Nullable FreeSWITCH sound/path values. The existing `greeting` field holds the long greeting and legacy paths. |
| `prompt_recordings` | Nullable map from prompt slot to a tenant recording UUID for shared sources. |
| `inter_digit_timeout` | Integer seconds, default 3. |
| `max_timeouts` | Integer retry limit, default 3. |
| `direct_dial` | Boolean, default false. |
| `ringback` | Optional preset for the ringback and transfer ringback channel variables. |
| `cid_prefix` | Optional caller ID name prefix, up to 20 characters. |
| `exit_kind` and `exit_data` | Optional typed destination when the menu ends normally. |
| `invalid_kind` and `invalid_data` | Optional typed destination when invalid input exhausts retries. |
| `timeout_kind` and `timeout_data` | Optional typed destination when no input exhausts retries. |

Keep the existing provisioned menu UUID. Add `description` and `return_to_menu` to `ivr_menu_options`. Preserve the menu's digit uniqueness constraint. An option contains a digit sequence, destination kind and identifier, description, order, enabled state, and return flag. Support 0–9 and documented navigation keys according to the tested FreeSWITCH terminator setting. Validate multi-digit input against the effective digit collection length.

Ringback presets are `default` (leave unset), `us-ring` (`tone_stream://%(2000,4000,440,480)`), `uk-ring` (`tone_stream://%(400,200,400,450)`), `fr-ring` (`tone_stream://%(1500,3500,440)`), and `de-ring` (`tone_stream://%(1000,4000,425)`). An approved custom tone stream is available through the advanced setting.

Destination kinds include extensions, ring groups, voicemail, conferences, queues, external numbers, announcement playback, same-tenant submenus, and native back/top/exit actions. Save executable destinations only after resolving them for the owning tenant. Resolve them again at render time and omit stale or disabled targets with a warning that identifies the record and kind. Convert existing raw options only where behavior maps exactly. Preserve remaining rows for operator review.

## Prompt library and storage

FusionPBX uploads recordings under the configured FreeSWITCH recordings directory for the current domain and offers those recordings alongside built-in sounds in its IVR editor. Several menus in that domain can select one recording. Built-in sounds serve as shared system assets. FusionPBX also has an optional Base64 recording storage setting. TallPBX continues to store audio as managed local files. [FusionPBX Recordings](https://github.com/fusionpbx/fusionpbx/blob/master/app/recordings/recordings.php#L189-L258) · [FusionPBX IVR prompt picker](https://github.com/fusionpbx/fusionpbx/blob/master/app/ivr_menus/ivr_menu_edit.php#L1015-L1065)

TallPBX applies that reuse pattern at the **tenant** boundary. An enabled recording in the current tenant can serve any of that tenant's menus and prompt slots, including when multiple tenants share a SIP domain. Other tenants cannot select or resolve it. Built-in host sounds are the shared source across tenants. When the optional Recordings module is enabled, the IVR stores recording UUIDs and resolves them from the tenant's library. Deleting an IVR leaves referenced recordings available. A disabled, deleted, or unavailable recording fails resolution.

Each menu has four prompt slots. Administrators can select a tenant recording, upload a private WAV/MP3/OGG file, or supply an approved FreeSWITCH sound/path for a slot. A new save needs a playable long greeting. An unchanged slot keeps its current source. The playback destination uses the same distinction between a recording UUID and a host-approved sound, with a separate selection for each source type.

The existing `media_assets` schema and service allow one asset per owner. Keep this storage contract and give each privately uploaded prompt revision its own owner UUID and category. Keep the original greeting asset readable during rollout. Stage the file under a pending owner, then activate that revision with the menu and options in a transaction under the menu lock. A rolled-back save leaves the earlier database pointers and files usable. Retire replaced and deleted uploads with durable cleanup state and dispatch file removal after commit. Retry cleanup using the existing media deletion behavior and allow for the serving cache lifetime. Finished local deletion sets the asset status to `Missing`. The four IVR categories use the existing `ivr-menus.view` media permission.

A menu update takes nullable option rows. A null or omitted payload keeps the stored rows. An empty list clears them. Apply option changes transactionally and preserve model events so the routing cache sees option-only edits.

## Call routing and upgrades

Render a tenant-scoped dialplan entry identified by `ivr_entry_<menu UUID>`. Match the stable entry token, the assigned numeric extension, and any pre-upgrade name alias exactly. Escape existing names for literal matching and escape every XML value. Look up stored typed route identifiers by UUID, then legacy name, then current name. Resolve them to the stable entry token and transfer to the owning tenant's internal context. The first number assignment or a later renumbering keeps stored typed inbound routes valid. Renaming an upgraded menu preserves its legacy alias. New menus use numeric dialing.

The normal ingress paths establish the tenant context before invoking `ivr`. On entry, answer the call, apply ringback and caller ID presentation, and then either play a one-shot announcement or start the native menu by UUID. Keep the caller ID prefix idempotent when the call returns. An affected call-flow transfer must use the intended tenant context.

Once per invocation, FreeSWITCH looks up `ivr.conf` with the requested menu name and call parameters, then builds the menu tree it executes. Confirm the installed build's authenticated XML-curl request shape on an internal and an inbound call. The XML Handler uses the verified call context to resolve the tenant and requires the requested enabled root to belong to it. Return just that root and its reachable enabled submenus. Unknown, disabled, mismatched, and unresolved requests get an empty configuration. A shared SIP realm cannot substitute for a verified tenant. Use existing request handling and routing invalidation for the fast PHP-FPM handler, the optional [Laravel Octane](https://laravel.com/docs/octane) XML worker, and the full-app fallback. [FreeSWITCH `ivr` implementation](https://github.com/signalwire/freeswitch/blob/v1.11.0/src/mod/applications/mod_dptools/mod_dptools.c#L2156-L2189) · [TallPBX operations guide](../../operations.md#octane-xml-worker-optional-performance-mode)

Build concrete menu entry arguments and move the action itself into tenant-scoped dialplan when it needs per-call state or a continuation. Revalidate target availability and ownership before rendering. Use canonical FreeSWITCH identifiers shared by each producer and consumer, including queue registration and lookup. Tenant-scoped database queries alone cannot distinguish names in the switch's shared queue and conference namespaces. A validated external number uses the owning tenant's normal outbound route and gateway policy.

## Call outcomes

| Trigger | Behavior |
| --- | --- |
| Menu with no effective entries | Play the long greeting once, then run the general exit destination or hang up. Missing or unreadable greeting goes directly to exit or hangup. |
| Exact option or eligible direct-dial number | Each collected sequence matches at most once. Build direct dial from enabled, eligible tenant extension numbers, subtract explicit option sequences, and transfer into that tenant's internal extension route. Set digit length long enough for every effective sequence. Shorter input may wait for the inter-digit timeout. |
| Terminal option | Transfer once to an IVR action extension scoped to the owning tenant and reachable from the IVR call. Execute the destination there and end the call cleanly if the terminal application returns. |
| Returning playback | Play the recording through the native menu action and continue listening. |
| Returning extension or ring-group bridge | In the action extension, keep the caller leg alive after the bridge, handle busy or no answer, and return once to the option's source menu if the caller leg remains connected. Re-enter through the stable menu entry and play the long greeting. A caller hangup ends the flow. |
| Interactive submenu | Use native submenu navigation. Back and top follow the switch's menu stack and submenu presentation inherits the entry call's channel settings. Enter an announcement-only submenu through its one-shot entry. |
| Invalid input or no input exhausts retries | Transfer to the action extension for that menu and retry outcome. Use its configured fallback once, or use that menu's general exit or hangup if the fallback is missing or no longer resolves. The action extension owns the path after the transfer. |
| General exit | Run only when the native menu returns on the owning call leg or a one-shot announcement finishes. Use the configured exit prompt where applicable. |

FreeSWITCH walks matching entries, so literal precedence requires disjoint sequences. A transfer changes the channel's routing state. Returning from a bridge uses a bridge followed by a transfer back. A successful application can leave the native menu listening, so terminal options use a transfer. Prove these behaviors on the installed build, including which prompt is played on native back/top, how the configured terminator behaves, and the differences between invalid input and no input. [FreeSWITCH IVR loop](https://github.com/signalwire/freeswitch/blob/v1.11.0/src/switch_ivr_menu.c#L503-L673) · [FreeSWITCH transfer implementation](https://github.com/signalwire/freeswitch/blob/v1.11.0/src/switch_ivr.c#L2253-L2297)

## Panel and permissions

Use the existing IVR permissions and Livewire conventions. The editor exposes menu identity, the four prompt slots, timing, direct dial, ringback, caller ID prefix, typed exit/invalid/timeout destinations, and editable digit options. The option editor hides return for unsupported destinations. The list shows the dialable extension, an explicit state for pre-upgrade rows awaiting a number, and option count. Use module translations in English, Spanish, and French.

## Verification and release

Test upgrades, write rollback and retry, configuration and dialplan requests, destination isolation, and panel validation. Use separate synthetic announcement and interactive menus in SIPp. The interactive scenario must observe a call-specific result from the selected destination and fail when the mapping is removed. Exercise internal and inbound calls, return behavior, direct dial, nesting, stale targets, voicemail, and all exits on the target FreeSWITCH build before claiming parity. Keep the new functional evidence alongside the standard load benchmark and document the tested commit. Record the migration, legacy name handling, supported return destinations, and outbound behavior in the changelog and administrator documentation.

The [implementation plan](../plans/ivr-parity-implementation-plan.md) assigns each contract to a task and names its verification gate.
