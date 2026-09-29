# Threat Feeds, Hardened TFTP Defense, and SIP Bot Filtering Implementation Plan

## Executive Summary
This document specifies the architecture and implementation roadmap for three integrated security enhancements in TallPBX:
1. **Phase 1: VoIPBL Threat Feed & Extensible Threat Intelligence Architecture** — Scheduled ingestion of ~100,000 bad actor subnets from `voipbl.org` using country filtering, HTTP conditional caching (`ETag` / `304 Not Modified`), streaming file processing, and atomic Linux `nftables` interval set swapping (`@threat_feed_ips`). Built on an extensible provider driver pattern (`ThreatFeedProviderInterface`). The feed set is re-applied after every firewall rebuild, and syncs are fail-open to the last good list (invariants 5 and 6).
2. **Phase 2: Hardened TFTP Defense Profile (Native `nftables`)** — Multi-layered TFTP packet filtering on UDP port 69 blocking Write Requests (WRQ), directory traversal (`../` $\rightarrow$ `0x2e2e2f`), known malware probes (`/x` $\rightarrow$ `0x2f78`), and stateful per-IP rate limiting via bounded kernel meters (default 10/min sustained, burst 20, both administrator-configurable). The rules are emitted **before** the TFTP accept rule in the port catalog so they are actually reachable.
3. **Phase 3: SIP Bot String Filtering & Instant Kernel Auto-Ban** — Deep packet inspection in FreeSWITCH (early dialplan regex) for known scanning bots (`sipvicious`, `friendly-scanner`, `VaxSIPUserAgent`, `sipcli`, `Ozeki`, plus custom administrator strings). Matches trigger Event Socket Layer (ESL) security events that invoke the bounded host helper to lock the offending IP in kernel RAM (`@banned_ips`) for a configurable duration (default 24 hours). Signatures are split into **high-confidence** (auto-ban) and **low-confidence** (recorded, never banned) tiers, so a generic string such as `User-Agent: SIP Call` can never cost a legitimate phone system its service. Detection happens when a request reaches the FreeSWITCH dialplan — not literally on the attacker's first packet — and the listener is idempotent so a scanner flood cannot amplify database or process work.

---

## Architectural Principles & Invariants

> [!IMPORTANT]
> **Firewall Precedence & Fast-Path Invariants**:
> The input chain follows the hardened stateful order (see §1.3). Six invariants must never be violated:
> 1. `@whitelist_ips` is evaluated **before every drop rule, before the malformed-packet check, and before the stateful fast path** — trusted IPs are accepted unconditionally and independently of connection-tracking state. No rule, ban, feed false positive, or conntrack flush can ever block a whitelisted IP.
> 2. `ct state invalid drop` follows the whitelist on purpose: the whitelist is an **absolute** safety net, so a trusted source is admitted even when it delivers a malformed or out-of-state packet. **Documented trade-off:** a whitelisted host can therefore send packets that would be dropped anywhere else. That is accepted deliberately — locking out the administrator or the carrier SIP gateway is a far worse failure than admitting a few bad packets from a source the administrator explicitly trusts — and it is exactly why the whitelist must stay short and curated (see the whitelist card helper text in *Documentation Deliverables*).
> 3. The `ct state established,related accept` fast path sits **before the blocklists** so the bulk of SIP/RTP media passes with zero set lookups; the blocklists evaluate only new flows.
> 4. Because the blocklists sit behind the fast path, offender removal is achieved at **ban time, not per packet**: when an IP enters `@blacklist_ips` or `@banned_ips`, the bounded helper flushes the IP's conntrack entries (`conntrack -D -s <ip>`), severing its live sessions instantly. **This flush is the only mechanism that ends an attacker's in-flight sessions** (stated for the default rule order — see *Pre-Filter Stage Reordering*) — so every code path that adds an address to a blocklist must flush, and a flush failure must raise a visible `security_audit_logs` entry (not merely a log line), since without it only the new-flow block is active.
> 5. **A firewall rebuild must never leave the threat-feed sets empty.** The full ruleset loads with `flush ruleset`, so `apply` re-loads `/etc/tallpbx/threat_feed.nft` immediately after the main ruleset (see §1.3). Feed elements live in that separate file, so the ruleset digest and the drift reconciler never see them.
> 6. **Threat-feed syncs are fail-open to the last good list.** A failed, partial, or empty download keeps the previously loaded list; disabling a feed clears its kernel elements immediately.

> [!IMPORTANT]
> **Bounded Host Helper Execution**:
> All Linux firewall mutations run through `/usr/local/sbin/tallpbx-security`, owned by `root:www-data` (mode `0750`, so the web user can execute but never modify it), accessible via `/etc/sudoers.d/tallpbx-security`. The web user (`www-data`) never executes arbitrary shell commands or wildcard sudo binaries.

> [!NOTE]
> **Database & Memory Isolation**:
> Threat feed CIDRs are not stored as individual rows in MariaDB. Instead, they are streamed to disk and loaded into an `nftables` kernel interval set (`flags interval;`). MariaDB stores only feed settings, country filters, and sync metadata.

---

## Plain-Language Explanation for Administrators

*This section is the non-technical explanation of the firewall's rule order. It is written to be reused verbatim in administrator-facing documentation (`docs/operations.md`, panel tooltips, and helper text) — adapt the phrasing to each surface, but keep the analogies and the key terms.*

### Why the order of the firewall rules matters

The firewall inspects every packet that arrives at the server — and during a phone call, there are a *lot* of packets. Most of them are audio. Checking every one of them against every list would waste the server's energy on work it doesn't need to do.

Think of the firewall as the reception desk of a busy building:

- **Known visitors get waved through (established, related).** When a call is already in progress, the server recognizes each audio packet as part of a conversation it already approved. It lets those packets straight through without consulting any list — the receptionist waves through someone who is already signed in. Because ongoing audio is the vast majority of call traffic, skipping the list checks for it saves nearly all of the firewall's work.
- **Trusted addresses always get in (whitelist).** The administrator's own connection and your SIP provider are on a short VIP list that is checked before anything else. The kernel itself enforces this, so no mistake on any other list can ever lock the administrator out.
- **Broken packets are turned away at the door (invalid).** Once the VIP list is out of the way, a packet that doesn't belong to any real conversation is dropped immediately — before anyone bothers to check a watch list.
- **The watch lists are only consulted for new conversations (blacklist, bans, threat feeds).** When a brand-new call or connection arrives, the server checks it against the blocked list, the automatic ban list, and the threat-feed list. Once a conversation is accepted, its remaining packets take the fast lane — the server never re-checks a call it already admitted.
- **A new block takes effect immediately, including calls in progress.** When an attacker is added to a list, the server also erases its memory of that attacker's active conversations (the conntrack flush), so their ongoing calls end right away — not just their next attempt.

### What this means in practice

- **Call quality and capacity win**: the server's CPU spends its time carrying audio, not re-verifying packets that were already approved. On a busy PBX this is the difference between a firewall that scales with call volume and one that slows it down.
- **Safety is not traded away**: the checks that matter — new conversations, broken packets, and immediate bans — all still happen; they simply happen at the moment that matters instead of repeatedly on every single packet.
- **Trusted addresses are protected by design**: the whitelist is enforced by the operating system itself, before every other rule, so an administrator can never be accidentally locked out of their own system.

### Documentation Deliverables

When the chain is implemented, port this explanation into the administrator-facing surfaces:

- `docs/operations.md`: add a short "How the Firewall Decides" subsection (reusing the reception-desk analogy) near the `nftables` row of the services table.
- Panel tooltips: the Firewall Rules tab's pre-filter rows get a one-line tooltip each (e.g. the established/related row: "Ongoing calls and connections are waved through without re-checking the lists, which keeps audio flowing at full speed").
- Whitelist card helper text: note that trusted addresses are admitted before every other check, so the list must stay short and curated ("Trusted addresses always get in first — no other rule, not even the broken-packet check, can turn them away").

---

## Security UI Restructure: Evaluation-Ordered Tabs

The Security Command Center (`/panel/security`) reorganizes from a single long page into a tabbed interface whose tabs follow the kernel evaluation order from left to right. The **Firewall Rules** tab sits last on the right: it renders the complete sequential pipeline, so it summarizes every preceding tab in evaluation order.

### Global Pinned Area (Above the Tab Strip)

Page-global operational state stays pinned above the tabs and remains visible on every tab:

- Zone 1 system status cards (firewall status, attack protection, blocked attackers, administrator connection)
- Lockout warning banner and firewall drift banner
- The protection-settings slide-over drawer and its trigger (thresholds are protection policy, not a pipeline stage)

### Tab Order (Left → Right)

| Position | Tab | Kernel Stage | Content |
|---|---|---|---|
| 1 | **Block & Allow Lists** | Stage 2 — `@whitelist_ips` (bypass) then Stage 5 — `@blacklist_ips` (permanent drop) | Whitelist card first, blacklist card second — both always visible with their own quick-add forms, no switcher (existing Cards 3 + 1) |
| 2 | **Attackers** | Stage 6 — `@banned_ips` (dynamic drop) | Instant-ban toggle, SIP bot signatures, then the active bans table (existing Card 2) |
| 3 | **Threat Feeds** | Stage 7 — `@threat_feed_ips` (feed drop) | VoIPBL status, country mode, sync interval, metrics, Sync Now |
| 4 | **Firewall Rules** | Full pipeline (last) | Sequential rules table, PBX port catalog, TFTP defense toggle, custom rules, default policy |

Kernel stages 1 (loopback), 3 (invalid packets), and 4 (stateful fast path) are non-configurable invariants with no workbench of their own; they appear only as rows inside the Firewall Rules tab. The whitelist and blacklist share one tab because the reordered chain (see §1.3) makes them adjacent modulo the two invariant checks between them, and because admins frequently check both lists together.

### Ordering Within Tabs

- **Block & Allow Lists**: the whitelist card renders first and the blacklist card second, matching their kernel evaluation order (Stage 2 → Stage 5).
- **Attackers**: the auto-ban toggle and SIP bot scanner signatures come first (they are the producers that feed `@banned_ips`), followed by the active bans table.
- **Firewall Rules**: rows already render in evaluation order; the new threat-feed pre-filter row is inserted immediately after the active-attackers row (Stage 6 → Stage 7).
- **Threat Feeds**: enable/status first, then country mode, then sync controls and metrics.

### Design Decisions & Trade-offs

- **The kernel chain was reordered for RTP performance and kernel-enforced trust** (see §1.3 and the invariant list): whitelist first, then the malformed-packet check, then the stateful fast path, then the blocklists. Every established SIP/RTP packet now costs the loopback check, one whitelist lookup, and the stateful match — **zero blocklist lookups**, against three set lookups per packet in the current chain.
- **Blacklist and Whitelist share the first tab**, ordered whitelist-then-blacklist to match evaluation order. Both quick-add forms are always visible with no in-tab switcher — exactly the page's current ergonomics.
- **No Overview tab**: overview content (status cards, banners) is page-global state, not a pipeline stage, so it stays pinned above the tab strip instead of occupying a position that would break the evaluation-order reading.
- **Rules-table "Manage" links become tab switches** (`wire:click="$set('activeTab', ...)"`) rather than same-page anchors, because hidden tab content cannot be targeted by `#section` links.
- **Tab component**: DaisyUI `tabs-lift` with `tabs-sm`, using `<button role="tab">` elements whose `tab-active` state binds to the `$activeTab` Livewire property, exposed via `#[Url(as: 'tab')]` for deep links (`/panel/security?tab=threat-feeds`).
- **Dead code removal**: the vestigial `ipListType` property, `switchIpListType()`, `addIp()`, and the `$ipLists` branch in `render()` are removed — the blade view already uses per-list forms (`addBlacklistIp` / `addWhitelistIp`) exclusively.

### Future Extensibility: Single-Page View Toggle (Deferred)
A "show all sections on one page" mode is **explicitly deferred**. The tabbed layout ships alone, but the structure is designed so the toggle can be added later without rework:

- All sections stay partials rendered from the **single `SecurityManager` Livewire component** — there are no per-tab components. An expanded mode therefore means "render every section partial stacked" behind a `$viewMode`-style property, with no state duplication.
- Section anchors (`#blacklist-section`, `#attackers-section`, `#whitelist-section`) are retained on the cards even though tab mode uses tab-switch buttons, so expanded mode can restore same-page anchor navigation for free.
- `?tab=` deep links are defined to be ignored (or to force tab mode for the visit) when expanded mode exists later.
- The eventual preference persists per administrator in `security_settings`; default remains tabs.
- Tab mode stays the canonical layout; future features design for tabs first and expanded mode inherits them automatically.

---

## Pre-Filter Stage Reordering (Advanced)

Letting administrators reorder the pre-filter pipeline is possible and worthwhile, but it cannot be *unconstrained*: several stages exist solely to enforce the safety invariants above, and moving those would silently break the "you can never be locked out" guarantee while the panel kept reporting a healthy firewall. The design below grants the reordering that is safe, refuses the rest at save time with a plain-language reason, and always offers a one-click reset.

The interaction deliberately mirrors the existing custom-rules reordering (`moveRuleUp` / `moveRuleDown` in `SecurityManager`, sequence swap in a `DB::transaction`, then `autoApplyFirewallRuleset()` and the `security_rule_reordered` toast) so administrators meet one ordering pattern in one place.

### What is reorderable, and what is pinned

| Stage | Key | Freedom | Reason |
|---|---|---|---|
| Loopback | `loopback` | **Pinned first** | Localhost IPC (database, Redis, FreeSWITCH ESL) must never be filtered |
| Whitelist | `whitelist` | **Pinned above every drop stage** | Invariant 1. Dropping it below any drop reintroduces exactly the lockout risk the reordered chain exists to eliminate |
| Invalid packets | `invalid` | Freely placeable after `whitelist` | Matches conntrack states disjoint from the fast path, so its position is policy, not correctness |
| Stateful fast path | `fast_path` | Freely placeable after `whitelist` | The one genuinely meaningful dial — see below |
| Manual blacklist | `blacklist` | Freely orderable within the blocklist group | All three end in `drop`; order decides only which counter and reason is reported |
| Dynamic bans | `banned` | Freely orderable within the blocklist group | as above |
| Threat feeds | `threat_feeds` | Freely orderable within the blocklist group | as above |

Stages 8–12 (ICMP, TFTP defense, port catalog, custom rules, default policy) are **not** part of this feature and keep their fixed order. In particular TFTP defense must remain immediately before the port catalog's `udp dport 69 accept` (§2.1), and custom rules keep their own independent reordering exactly as today.

Two facts make the mental model smaller than it looks:

- `ct state invalid drop` and `ct state established,related accept` match **disjoint** conntrack states (INVALID vs ESTABLISHED/RELATED), so swapping them changes nothing semantically — only where the check sits in the hot path.
- The three blocklist stages all end in `drop`, so their relative order changes only which rule's counter increments and which "reason" the Firewall Rules tab reports. The packet outcome is identical.

### The one dial that matters: blocklists before or after the fast path

This is the choice worth surfacing prominently, because it changes how a ban behaves:

- **Blocklists after the fast path (default, recommended for high call volume)** — established SIP/RTP passes with zero blocklist lookups. A newly blocked IP's *in-flight* sessions survive until the conntrack flush severs them, so that flush is **load-bearing** (invariant 4).
- **Blocklists before the fast path** — every packet from a blocked source is dropped immediately, including packets belonging to calls already in progress, with no conntrack flush needed; the flush becomes belt-and-braces. The cost is one to three extra set lookups on every established media packet.

The UI presents this as a plain-language group toggle rather than expecting anyone to reason about `ct state`: **"Block new connections only *(faster)*"** vs **"Block everything, including calls in progress *(more immediate)*"**. Invariant 4 is written for the default order, so when an administrator moves the blocklist group above the fast path the panel must say so and note that the conntrack flush is no longer the only mechanism ending live sessions.

### Storage and the default order

- Persisted in the `security_settings` key `pre_filter_order` as an ordered JSON array of the stage keys in the table above — a fixed vocabulary, never administrator-supplied identifiers or free text.
- The default order lives in code as `SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER`, mirroring the chain in §1.3. Deriving it from code rather than from a seeded row lets an application update extend or retune the default without a migration, and makes **reset simply "write the default back"**.
- Any order change is a firewall configuration change: it regenerates the pending ruleset, runs through the normal Apply confirmation via `autoApplyFirewallRuleset()`, and is recorded in `security_audit_logs` with the before/after order.

### Validation — fail loudly, never silently repair

`SecurityConfigGenerator::assertValidPreFilterOrder(array $order): void`, called by both the Livewire save path and `generate()`, in the same spirit as the existing `assertCompilableEntries()`. It refuses with a precise plain-language message naming the violated rule when:

1. The array is not exactly the seven known keys (a missing, duplicate, or unknown entry).
2. `loopback` is not first.
3. Any drop stage (`invalid`, `blacklist`, `banned`, `threat_feeds`) appears above `whitelist`.

The generator never emits an unsafe order and never silently reorders one — a silent repair would leave the administrator believing the panel reflects the kernel.

### UI

- The **Firewall Rules** tab renders the seven pre-filter rows as an ordered list with the same up / down chevron buttons used by custom rules (`movePreFilterUp` / `movePreFilterDown`, wired to the same toast key pattern).
- Pinned rows render as **non-movable, with a lock badge** and a one-line tooltip in plain language (for example the loopback row: "Always first — this is how the server talks to its own database and phone service").
- The blocklist group carries the plain-language toggle described above; the individual rows inside it stay freely reorderable for diagnostic clarity.
- A **"Reset to recommended order"** button sits at the foot of the list, always visible, with a typed confirmation. It writes `DEFAULT_PRE_FILTER_ORDER` back, regenerates, and applies — the same escape hatch shape as the threat feed's "Remove all feed blocks" (§1.6).

### Testing

- `SecurityConfigGeneratorPreFilterOrderTest`:
  - A property-style test that enumerates (or samples) permutations and asserts the validator accepts **exactly** those satisfying the constraints above. This is the test that stops a future refactor from quietly relaxing invariant 1.
  - `generate()` throws a plain-language `RuntimeException` for an order placing a drop stage above the whitelist, rather than emitting an unsafe ruleset.
  - A build with no `pre_filter_order` key is byte-identical to one carrying `DEFAULT_PRE_FILTER_ORDER`, so the fallback and the default cannot drift apart.
  - The reset action restores that byte-identical ruleset and writes an audit entry.
- `SecurityManagerPreFilterOrderTest`: the move actions update the order, pinned rows cannot be moved, an invalid order is rejected at save time with the explanatory message, and reset restores the default.

---

## Firewall Disable & Troubleshooting Bypass Modes

"Turn the firewall off" is really **two different intentions** that need two different affordances, and conflating them is what produces outages:

| | Persistent off | Temporary troubleshooting bypass |
|---|---|---|
| Intent | "I don't want a firewall on this server" | "Is the firewall what is breaking this call?" |
| Duration | Until someone changes it | Bounded, automatically reverting |
| Surface | A settings toggle | A **Pause protection** button |
| Banner | Persistent red, on every Security page | Amber with a live countdown |
| Failure mode | Long-term exposure nobody notices | A forgotten open window |

### What already exists — reuse it, do not rebuild it

The security module already carries most of the vocabulary needed. Any new work must extend these rather than introduce parallel switches:

| Capability | Existing setting / class | Notes |
|---|---|---|
| Disable the whole firewall | `firewall_enabled` | Already emits an open ruleset (`policy accept`) and has a generator test |
| Default inbound policy | `firewall_default_policy` | Guarded by `LockoutGuardService` |
| Zero-lockout protection | `LockoutGuardService` | `assertSafe()`, `whitelistIp()` auto-whitelist, plus the try-and-rollback policy change pattern in `SecurityManager` |
| Global intrusion detection | `intrusion_detection_enabled` | |
| Per-protocol intrusion protection | `protect_web`, `protect_sip`, `protect_ssh` | Read through `SecurityIncidentService::isVectorProtected()` — this is already per-section disabling |
| Attack protection | `attack_protection_enabled` | |
| Per-rule / per-service / per-protocol toggles | `SecurityRule.enabled`, `SecurityService.enabled`, ICMP enabled | Existing `toggleRule()` and friends |
| TFTP defense toggle | `tftp_defense_enabled` (§2.2) | Planned |
| Per-threat-feed toggle | `SecurityThreatFeed.enabled` (§1.5) | Planned |

So "individual sections" is **largely already solved** at the intrusion-detection and service layer. What is missing is the *temporary, self-reverting* dimension and a couple of targeted escape hatches.

### What is genuinely missing

1. **A bounded bypass that restores itself.** Today, turning the firewall off is a sticky state. If an administrator disables it at 22:00 to debug a registration and forgets, the PBX is open to the internet all night — SIP scanners, toll-fraud dialing, the lot. This is the single most valuable addition.
2. **Observe-only (shadow) mode.** A blunt disable answers "does it work without the firewall?" but not *why*. Evaluating every check and **recording without dropping** answers the real question while traffic flows normally.
3. **An escape hatch for `ct state invalid drop`.** It is the one hard-coded pipeline stage with no switch anywhere, and it is a genuine real-world troubleshooting target — SIP ALGs and asymmetric routing routinely produce packets conntrack classifies as invalid, showing up as one-way audio or failed registrations.
4. **Ban accumulation during a bypass.** If intrusion detection keeps auto-banning while the firewall is bypassed, restoring it suddenly applies a backlog of bans the administrator never saw. Experienced as "the firewall randomly broke my phones".

### The design: one control, two dimensions

A single **Pause protection** button pinned beside the Zone 1 status cards opens a small dialog with exactly two choices — a mode and a scope — behind an "Advanced" disclosure so the office manager sees one button and the engineer sees the dial:

**Mode**
- **Observe only (recommended)** — every check evaluates and logs; nothing is dropped. Traffic flows normally and the panel shows exactly what *would* have been blocked, by which stage.
- **Enforce off** — the selected stages stop dropping entirely.

**Scope**
- **Everything** (all blocking: blacklist, bans, threat feeds, TFTP defense, invalid-packet drop)
- **Blocking lists only** (blacklist + bans + threat feeds)
- **Malformed packets only** (`ct state invalid drop`)
- **Auto-ban only** — maps onto the existing `intrusion_detection_enabled`, not a new mechanism

Scope is expressed as stage keys from `pre_filter_order` (the previous section), so ordering and bypassing share one vocabulary and neither needs a second schema.

### Safety rails (all modes)

- **Bounded by default.** The duration picker offers 10 min *(recommended)* / 30 min / 1 hour / *Until I turn it back on*. Only the last requires a typed confirmation. Automatic restore runs on the scheduler (see the deployment note below).
- **A reason field is required** and stored in `security_audit_logs` alongside who, what, when, and the auto-revert deadline. A bypass is an operational decision worth a sentence.
- **Loopback and the whitelist are never affected.** They are the floor of the system and remain outside every bypass scope, consistent with invariant 1 and the STAGE 1 loopback rule in §1.3. No bypass mode can sever the administrator's access.
- **The dangerous moment is re-enabling, not disabling.** Entering a bypass is permissive and cannot lock anyone out; *restoring* a `DROP` policy can, especially if the administrator's current IP was never whitelisted. The restore path therefore runs `LockoutGuardService::assertSafe()` and offers the existing `whitelistIp()` one-click auto-whitelist before applying. This must be tested explicitly — it is the failure this whole section exists to prevent.
- **Ban accumulation is suspended while bypassed.** Auto-bans that *would* have been issued are recorded as incidents flagged `bypassed`, and are presented in the restore summary for an approve-or-discard decision rather than landing silently on restore.
- **Restore summary.** On restore the panel reports what the window let through: count by stage, top source addresses, and the pending bans awaiting a decision. This is the diagnostic payload that makes observe-only mode worth choosing over a blunt disable.
- **Overdue detection.** Auto-revert depends on the scheduler being healthy. If the deadline passes without a restore, the banner escalates to red and says plainly that protection is still off and the automatic restore did not run — never let a silent scheduler failure hide an open firewall.

### Persistent "no firewall" deployments

Some servers legitimately sit behind a hardware firewall and want no host filtering at all. That stays the existing `firewall_enabled = false`, but the surface needs to be unmissable rather than a quiet toggle:

- Turning it off requires a typed confirmation that names the consequence in plain language: *"This server will accept all inbound traffic from the internet, including SIP registration attempts and call setup from unknown callers."*
- A persistent red banner sits at the top of **every** Security page (not only the Firewall Rules tab) while it is off, with a one-click **Turn firewall back on**.
- The Zone 1 firewall status card reflects it as `Off`, not `Idle`, so a screenshot or a casual glance is never ambiguous.
- The same ban-accumulation rule applies: intrusion detection may keep *recording*, but nothing accumulates into a backlog to be applied later.

### Testing

- `SecurityBypassModeTest`:
  - Observe-only generates rules that count and log but do not drop, for every stage in scope.
  - Each scope option touches exactly its own stages — "Malformed packets only" leaves the blocklist drops intact, and vice versa.
  - Loopback and whitelist rules are byte-identical across every bypass mode and scope.
  - An expired bypass restores automatically; an overdue one is reported as such.
  - Bans are not accumulated during a bypass and appear as `bypassed` incidents in the restore summary.
- `LockoutGuardServiceRestoreTest` (the important one):
  - Restoring enforcement from an unwhitelisted administrator IP throws `LockoutException` rather than silently locking them out.
  - The offered `whitelistIp()` path makes the same restore succeed.
  - Restoring with `firewall_default_policy = accept` never blocks the restore.
- `SecurityManagerBypassTest`: the required-reason validation, the typed confirmation for *Until I turn it back on*, banner state per mode, and the persistent-off banner rendering on every Security page.

---

## Phase 1: VoIPBL Threat Feed & Extensible Provider Architecture

### 1.1 Provider Interface & Extensibility
To allow future threat intelligence sources (such as APIBAN or Spamhaus DROP) to be added without modifying the firewall compiler:
- `ThreatFeedProviderInterface`:
  - `identifier(): string` (e.g. `'voipbl'`)
  - `name(): string`
  - `fetchUrl(SecurityThreatFeed $feed): string`
  - `sync(SecurityThreatFeed $feed, bool $force = false): ThreatFeedSyncResult`
- `ThreatFeedManager`:
  - Dispatches sync requests to registered providers.
- `VoipblFeedProvider`:
  - Implements `ThreatFeedProviderInterface`.
  - Formats VoIPBL query parameters based on configured country mode:
    - *All Countries*: `https://www.voipbl.org/update/`
    - *Threat Origin Filter (`bc`)*: `https://www.voipbl.org/update/?bc=CN,RU,KR`
    - *Home Country Protection (`wc`)*: `https://www.voipbl.org/update/?wc=US,CA,GB`

### 1.2 Streaming Ingestion & Bandwidth Optimization
`ThreatFeedIngestionService` handles network communication and file writing:
- **HTTP `Accept-Encoding: gzip`**: Reduces raw 1.7 MB list down to ~382 KB.
- **Conditional HTTP Caching**: Sends `If-None-Match: <etag>` and `If-Modified-Since: <header>`. On `304 Not Modified`, updates check timestamp with zero parsing and 0 KB payload.
- **Direct-to-Disk Stream**: Streams the download body into a temporary file and parses it line-by-line via `fgets()` to keep PHP memory flat (<8 MB). The temporary file is created with `tempnam()` inside `storage/app/threat-feeds/` — never a fixed path under `/tmp`, which is world-writable (a local symlink/DoS vector) and is often a RAM-backed `tmpfs` that would defeat the flat-memory goal.
- **Strict CIDR Validation**: Validates each line with `AddressFamily::isValidAddressOrCidr()` — the security module's existing dual-stack validator, which accepts both bare addresses and CIDR ranges. `filter_var(..., FILTER_VALIDATE_IP)` is *not* sufficient on its own because it rejects the `10.0.0.0/8`-style ranges that make up the entire feed. Invalid lines are counted and skipped, never applied, and the rejected-line count is surfaced in the UI.
- **Fail-Open Semantics (invariant 6)**: a non-2xx response, a truncated body, a parse yielding fewer than `threat_feed_min_entries` (default 1,000) valid ranges, or any transport error aborts the sync and **leaves the previously loaded list untouched**. The status is recorded as `failed` with the error text; a bad download can never empty the kernel set.
- **Output Compilation**: Generates `/etc/tallpbx/threat_feed.nft.pending` as a **set-element file, not a table definition** — a `flush set inet tallpbx_filter threat_feed_ips` statement followed by `add element` statements **chunked to 256 addresses per statement**, so no single line grows to a length the `nft` lexer can reject. `threat_feed_ips6` is written the same way. The `flush set` and all `add element` statements travel in one `nft` transaction, so the set is never observable in a half-updated state.
- **IPv4 Coverage**: VoIPBL publishes IPv4 ranges only. `threat_feed_ips6` is declared and populated by the same pipeline so future providers can supply IPv6, but the UI must not imply IPv6 coverage from VoIPBL.

### 1.3 Kernel Integration & Bounded Helper
- **Bounded Helper Action (`scripts/resources/tallpbx-security`)**:
  - Add action `update-threat-feed`. It takes **no path argument** — the web user must never be able to point a root-owned helper at an arbitrary file. It reads only the canonical `/etc/tallpbx/threat_feed.nft.pending` written by the application, mirroring the existing `validate` action's no-argument contract. Do not weaken that contract with a `<file>` parameter or with an extension check standing in for a whitelist.
  - Ensures `table inet tallpbx_filter` and both feed sets exist first (restoring `/etc/tallpbx/firewall.nft` if needed, exactly as the `ban` action already does), so `flush set` / `add element` can never fail against a missing set.
  - Preflights syntax: `$NFT_BIN -c -f "$FIREWALL_CONF_DIR/threat_feed.nft.pending"`.
  - Atomically promotes the pending file to `/etc/tallpbx/threat_feed.nft` (`mv`, `chown root:www-data`, `chmod 0640`) and loads it with `$NFT_BIN -f /etc/tallpbx/threat_feed.nft`.
- **Re-apply on every firewall rebuild (invariant 5)**: the `apply` action gains a final step that loads `/etc/tallpbx/threat_feed.nft` after the main ruleset, when that file exists and passes `$NFT_BIN -c`. Without this, the main ruleset's `flush ruleset` would leave the feed sets empty until the next scheduled sync — up to a full day of lost protection after any single administrator change.
- **NFTables Ruleset Structure (`SecurityConfigGenerator.php`)**:
  - Declares sets in `table inet tallpbx_filter`. They are declared **empty** in the main ruleset and populated exclusively by `threat_feed.nft`, so feed churn never changes the ruleset digest computed by `canonicalForm()` and `FirewallBanReconciler` — which reconciles only `@banned_ips{,6}` against the database — never reports feed elements as drift:
    ```nftables
    set threat_feed_ips {
        type ipv4_addr
        flags interval
    }
    set threat_feed_ips6 {
        type ipv6_addr
        flags interval
    }
    ```
  - Input chain evaluation order (final, RTP-optimized). STAGE 1–7 are the new pre-filter pipeline; STAGE 8–12 carry the existing generator steps across unchanged apart from renumbering:
    ```nftables
    # STAGE 1: Loopback invariant
    iif "lo" accept

    # STAGE 2: Whitelist override (admin safety net, conntrack-independent —
    # evaluated before every drop rule and before the malformed-packet check)
    ip saddr @whitelist_ips accept
    ip6 saddr @whitelist_ips6 accept

    # STAGE 3: Invalid packet defense (after the whitelist on purpose, so a
    # trusted source is never turned away; see invariant 2)
    ct state invalid drop

    # STAGE 4: STATEFUL FAST PATH — passes the bulk of ongoing SIP/RTP
    # instantly with zero set lookups
    ct state established,related accept

    # STAGE 5: Manual Blacklists (only new flows reach this point)
    ip saddr @blacklist_ips drop
    ip6 saddr @blacklist_ips6 drop

    # STAGE 6: Dynamic Intrusion Bans
    ip saddr @banned_ips drop
    ip6 saddr @banned_ips6 drop

    # STAGE 7: Automated Public Threat Feeds
    ip saddr @threat_feed_ips drop
    ip6 saddr @threat_feed_ips6 drop

    # STAGE 8: ICMP Ping Diagnostics + ICMPv6 essentials
    #   (content unchanged from the current generator; the packet-too-big / MLD /
    #   Neighbor Discovery accept must stay exactly where it is)

    # STAGE 9: TFTP Defense Profile
    #   (STAGE 9 precedes the TFTP accept in STAGE 10 on purpose — a drop rule
    #   placed after an accept rule never fires; see §2.1)

    # STAGE 10: Core PBX Telephony Ports
    #   (port catalog, including `udp dport 69 accept` for TFTP provisioning)

    # STAGE 11: Custom Sequential Rules

    # STAGE 12: Default Inbound Policy
    ```

  - **Renumbering map.** The current generator's step comments and `SecurityConfigGeneratorTest` both assert these positions, so they must be updated together with this chain:

    | New stage | Previous `SecurityConfigGenerator` step | Nature of change |
    |---|---|---|
    | 1 | STEP 1 loopback | unchanged |
    | 2 | STEP 5 whitelist | moved above the invalid drop and the fast path |
    | 3 | STEP 4 (the `invalid` half) | moved below the whitelist |
    | 4 | STEP 4 (the `established,related` half) | moved above the blocklists |
    | 5 | STEP 2 blacklist | moved below the fast path |
    | 6 | STEP 3 banned | moved below the fast path |
    | 7 | — | **new** threat feeds |
    | 8 | STEP 6 ICMP | content unchanged |
    | 9 | — | **new** TFTP defense |
    | 10 | STEP 7 port catalog | content unchanged |
    | 11 | STEP 8 custom rules | content unchanged |
    | 12 | STEP 9 default policy | content unchanged |

### 1.3b Conntrack Flush for Instant Offender Removal
Because the blocklists evaluate only new flows (they sit behind the fast path), a newly blocked or banned IP's *live* sessions must be severed explicitly at ban time:

- **Linux package**: `apt install conntrack` (provides the `/usr/sbin/conntrack` CLI). Added to the security resource script so fresh installs include it; existing installs need the upgrade step described in *Deployment & Upgrade Requirements* below.
- **Bounded Helper Action**: add `flush-conntrack <ip>` to `/usr/local/sbin/tallpbx-security`, following the existing pattern: strict dual-stack IP validation (same validator as `ban`), hardcoded binary path, `set -euo pipefail`, and family-aware invocation — `conntrack -D -s <ip>` for IPv4, `conntrack -D -f ipv6 -s <ip>` for IPv6.
- **Call sites**: `SecurityBanService::ban()` is the single entry point for bans — it writes the `security_bans` row and the audit entry and then delegates to `SecurityExecutor` for the kernel mutation. The SIP-scanner listener (§3.3) **must call `SecurityBanService::ban()`, not `SecurityExecutor::ban()`**; invoking the executor directly would leave the database and `FirewallBanReconciler` disagreeing with the kernel set. The blacklist add paths (`addBlacklistIp`, `promoteToBlacklist`) also invoke the flush. Threat-feed sync does **not** flush (flushing per-CIDR for ~100k entries is pointless; feeds gate new flows only).
- **Order & failure tolerance**: set mutation first, flush second. If the flush fails, the operation is not rolled back — the new-flow block is still active. But because invariant 4 makes the flush the only thing that ends in-flight sessions, the failure **must** be raised as a `security_audit_logs` entry with a visible reason so an operator can finish the job by hand (`conntrack -D -s <ip>`), rather than being buried in `laravel.log`.

### 1.4 Database Schema (`security_threat_feeds`)
Migration `2026_09_22_000010_create_security_threat_feeds_table.php`:
- `id`
- `provider`: unique string (e.g. `'voipbl'`)
- `name`: string
- `enabled`: boolean (default `false`)
- `country_mode`: enum (`'all'`, `'blacklist'`, `'whitelist'`)
- `countries`: text/json array of ISO 3166-1 alpha-2 codes
- `sync_interval`: enum (`'hourly'`, `'4_hours'`, `'12_hours'`, `'daily'`)
- `last_sync_at`: timestamp nullable
- `last_status`: string nullable (`'success'`, `'not_modified'`, `'failed'`)
- `last_error`: text nullable
- `entries_count`: unsigned integer
- `etag`: string nullable
- `last_modified_header`: string nullable
- `last_rejected_lines`: unsigned integer (default `0`) — how many feed lines failed CIDR validation on the last sync, surfaced in the UI so a provider format change is visible instead of silent
- `timestamps`

**Schema decisions:** `provider` is `unique`, meaning **one feed configuration per provider driver in this release**. The interface is deliberately instance-oriented (`sync(SecurityThreatFeed $feed)`), so relaxing this later to a `provider` + `slug` pair requires no driver changes — record that intent in the migration comment rather than leaving the constraint unexplained.

### 1.5 Scheduled CLI Command
- Artisan Command: `php artisan security:sync-threat-feeds {--feed=} {--force}`
- Registered in `routes/console.php` with an hourly schedule; the command itself honours each feed's `sync_interval`, so the hourly tick is a cheap no-op for feeds configured as `daily`.
- `--force` bypasses the `ETag` / `If-Modified-Since` cache but does **not** bypass the fail-open checks — a forced sync of a broken feed must still keep the last good list.
- **Staleness alerting**: when `last_sync_at` is older than `2 × sync_interval`, the status badge shows `Stale` and an audit entry is recorded. A feed silently rotting is more dangerous than one that fails loudly.
- **Disable semantics (invariant 6)**: disabling a feed clears its kernel elements immediately and records `last_status = 'disabled'`. Re-enabling triggers an immediate sync before the set is repopulated.

### 1.6 Security Manager UI Integration
In the **Threat Feeds** tab of `/panel/security`:
- Add **Public Threat Feeds / Blocklists** tab panel.
- Shows VoIPBL status badge (Active, Idle, Syncing, Error, Stale, Disabled).
- Radio toggles for Country Mode: *All Countries*, *Filter by Threat Origin (`bc`)*, *Exclude Home Countries (`wc`)*. The friendly labels are what administrators see; the enum values stay `all` / `blacklist` / `whitelist` in code and translations.
- Country code selector input: a multi-select of ISO 3166-1 alpha-2 codes, validated server-side as uppercase two-letter codes with a maximum of 50 entries, and a helper note that VoIPBL coverage is IPv4-only.
- Sync Interval dropdown.
- Metrics display: Active CIDRs, rejected lines from the last sync, last sync timestamp, packets dropped by the feed (from the `counter` on the STAGE 7 rules), staleness state, and a **Sync Now** button.
- A prominent **"Remove all feed blocks"** escape hatch beside the enable toggle: it clears the kernel elements without disabling the feed, for the day a feed ships a false positive that blocks a real provider. This belongs on the surface, not buried in the CLI.

---

## Phase 2: Hardened TFTP Defense Profile (Native `nftables`)

### 2.1 Defense Ruleset
When TFTP protection is enabled, `SecurityConfigGenerator` emits the following native `nftables` rules at **STAGE 9 of the input chain — immediately before the port catalog's `udp dport 69 accept` at STAGE 10**. Emitting them "after the services section" would make every one of them dead code, because the accept rule short-circuits the chain first; the drop rules must come first for port 69.

Deep packet inspection inherently only sees new flows — established TFTP transfers already passed the stateful fast path — which is the correct and cheapest placement. (TFTP data and ACK packets travel on ephemeral transfer IDs, not port 69, so these rules only shape *new* file sessions, which is exactly what provisioning abuse looks like.)
```nftables
# 1. Block TFTP Write Requests (Opcode 2) - Provisioning is strictly read-only
udp dport 69 @th,64,16 0x0002 counter drop

# 2. Block Directory Traversal (Requests starting with "../" -> 0x2e2e2f)
udp dport 69 @th,64,16 0x0001 @th,80,24 0x2e2e2f counter drop

# 3. Block Malicious Scan Probes (Requests starting with "/x" -> 0x2f78)
udp dport 69 @th,64,16 0x0001 @th,80,16 0x2f78 counter drop

# 4. Per-IP rate limiting, bounded so a spoofed-source flood cannot exhaust kernel memory
udp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter drop
udp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter drop
```

**Rate-limit meters must be memory-bounded.** A bare `meter` statement creates a dynamic set holding one element per source address with **no eviction**, so a spoofed-source flood grows kernel memory without limit. Emit named dynamic sets instead, declared with the other sets in `table inet tallpbx_filter`:

```nftables
set tftp_flood4 {
    type ipv4_addr
    flags dynamic,timeout
    timeout 1m
    size 65535
}
set tftp_flood6 {
    type ipv6_addr
    flags dynamic,timeout
    timeout 1m
    size 65535
}
```

`timeout 1m` ages out idle source entries and `size 65535` caps total memory. `SecurityConfigGeneratorTftpTest` must assert both attributes are present, so a later refactor cannot silently regress to an unbounded meter. Confirm the exact `update @set { ... limit rate over ... }` spelling against the deployed `nft` version (Debian 13 ships nft 1.x) at implementation time — the bounded helper's `nft -c` preflight is the authority on what the kernel accepts.

**Tuning note:** 10/minute with burst 20 is the *recommended default*, not a law. A single office NAT doing a power-cut reboot storm can legitimately exceed it and leave phones unprovisioned, which is why the rate and burst are administrator-configurable (§2.2) and why whitelisted sources already bypass these rules entirely at STAGE 2.

### 2.2 Security Configuration & UI
- Add setting `tftp_defense_enabled` (boolean, default `true`).
- Add settings `tftp_defense_rate_limit` (default `10` requests per minute) and `tftp_defense_burst` (default `20` packets), both positive integers with sensible upper caps, so an administrator under heavy provisioning load can raise them without editing rules.
- In the **Firewall Rules** tab's **PBX Port Catalog & Services** table, add a shield badge and toggle for the **TFTP Provisioning (UDP 69)** row:
  - *"Hardened TFTP Defense Profile: Blocks WRQ uploads, path traversal (`../`), `/x` probes, and applies flood rate limiting."*
  - Expanding the badge reveals the current rate limit and the per-rule counters (uploads blocked, traversal attempts, scan probes, flood drops) — progressive depth for the engineer, one badge for the office manager.

### 2.3 Future Extensibility: Custom Patterns (Deferred)
Custom TFTP patterns are explicitly **out of scope for this release**, but the code is structured so they can be added later without rework:

- `SecurityConfigGenerator` emits the defense rules by iterating over a **merged pattern list** (`base patterns` + `custom patterns`) instead of hardcoding the three rules individually. The custom list is empty by default, so the generated output is byte-identical to the fixed rules today.
- Reserve the settings key `tftp_defense_custom_patterns` (default `[]`). No UI, no validation path, and no custom-pattern drop rules are exposed yet — only the merge point exists.
- Documented design for the eventual feature: entries are plain-text strings (e.g. `../`, `/x`, `busybox`) converted to `@th` hex matches by the generator, with a maximum length limit and validation at input time; the same `nft -c` bounded-helper preflight applies.

---

## Phase 3: SIP Bot String Filtering & Instant Kernel Auto-Ban

### 3.1 Default Scanner Signatures (Two Confidence Tiers)
A false positive here costs a real phone system its service for the whole ban duration, so the curated base is split by how unmistakable each signature actually is.

**High-confidence — auto-ban on match:**

| Field | Pattern | Why it is unambiguous |
|---|---|---|
| `From` | `sipvicious` | Named penetration-testing tool |
| `User-Agent` | `friendly-scanner` | Named scanner |
| `User-Agent` | `VaxSIPUserAgent` | Named scanner |
| `User-Agent` | `sipcli` | Named CLI fuzzer — this one substring also covers `sipcli/v1.8`, so do not ship a separate versioned entry |
| `User-Agent` | `Ozeki` | Named commercial IVR/bot toolkit |

**Low-confidence — recorded, never banned on this signal alone:**

| Field | Pattern | Why it must not ban |
|---|---|---|
| `User-Agent` | `SIP Call` | A generic phrase that legitimate softphones and gateway stacks emit. It stays in the signature set so administrators *see* the hits in the Attackers tab, but a match by itself never triggers a kernel ban. |

**Tier rules (decision-complete):**
- High-confidence match → incident recorded **and** banned for `sip_scanner_ban_seconds` (default `86400`).
- Low-confidence match → incident recorded and shown in the Attackers tab with an **"Add to auto-ban list"** action, so the administrator makes the enforcement call with the evidence in front of them.
- Two or more *distinct* low-confidence signatures from the same IP within `sip_scanner_window_seconds` (default `300`) escalate to a ban — several generic markers together stop being generic.
- Administrator-added custom signatures are high-confidence **by definition** (an explicit human decision), so they ban on match.

### 3.2 FreeSWITCH Detection & ESL Event Emission
- In FreeSWITCH early dialplan routing (`public.xml`), match incoming requests against the scanner regex:
  ```xml
  <condition field="${sip_user_agent}" expression="friendly-scanner|VaxSIPUserAgent|sipcli|Ozeki">
      <action application="set" data="proto_security_violation=1"/>
      <action application="event" data="Event-Name=CUSTOM,Event-Subclass=tallpbx::sip_scanner_detected,Scanner-Type=User-Agent,Scanner-Value=${sip_user_agent},Attacker-IP=${sip_network_ip}"/>
      <action application="respond" data="403 Forbidden"/>
      <action application="hangup"/>
  </condition>
  <condition field="${sip_from_user}" expression="sipvicious">
      <action application="event" data="Event-Name=CUSTOM,Event-Subclass=tallpbx::sip_scanner_detected,Scanner-Type=From-User,Scanner-Value=${sip_from_user},Attacker-IP=${sip_network_ip}"/>
      <action application="respond" data="403 Forbidden"/>
      <action application="hangup"/>
  </condition>
  ```

  Emitting one common event for every signature match keeps the tiering decision (§3.1) in PHP rather than in the XML: the dialplan reports what it saw, the listener decides whether a ban follows. Low-confidence strings are matched by a third condition that emits the same event subclass with `Scanner-Confidence=low`, so no ban logic is duplicated in the dialplan.

- **Attacker-IP must be the true socket peer address, never a header-derived value.** `sip_from_host` and `sip_via_host` are attacker-controlled and must never be used here. `${sip_network_ip}` is the intended source; confirm it reflects the message source on the deployed FreeSWITCH build during Phase 3 (candidates are `sip_network_ip` and `sip_received_ip`, which mean *remote peer* and *local receiving address* respectively — only the former is correct). Ship a fixture test asserting the emitted event carries the peer address rather than the Via/From host, and have the PHP listener drop any event whose `Attacker-IP` fails `AddressFamily::isValidAddress()`.

- **Detection coverage is narrower than "the first packet".** These conditions run in early dialplan, so only requests that reach the `public` context produce events. Packets rejected by Sofia before dialplan (malformed frames, unknown profile, failed transport) never fire, and a UDP scan carrying no well-formed SIP request at all is invisible here. REGISTER traffic never reaches dialplan at all — Sofia's registrar handles it — so registration floods stay covered by the existing Sofia auth-failure ban path in §3.3, not by these signatures. Kernel rate limits and the threat-feed sets cover what the dialplane cannot see. The panel copy must not claim detection on an attacker's very first packet.

### 3.3 Event Listener & Instant Kernel Ban
- Event Listener: `Modules\Security\Listeners\LogSipScannerListener`
- Subscribed to `tallpbx::sip_scanner_detected` ESL events and Sofia auth failures.
- On trigger:
  1. Validates the attacker IP with `AddressFamily::isValidAddress()` and **discards the event if it fails** — the value arrives from a FreeSWITCH channel variable and must be treated as untrusted input.
  2. Confirms the attacker IP is not in `@whitelist_ips` and is not a known registered device address. A whitelisted or provisioned device is never auto-banned, even on a high-confidence signature.
  3. **Idempotency guard:** if an active ban already exists for this IP, return immediately — no second `security_bans` row, no second audit entry, no second `sudo` spawn. Under a scanner flood this single check is what keeps the listener from amplifying database and process work.
  4. Applies the tier rules from §3.1 (ban now, record only, or escalate).
  5. Records the incident in `security_bans` — with `vector` / `reason` carrying `Scanner-Type`, `Scanner-Value`, and `Scanner-Confidence` — and in `security_audit_logs`.
  6. Calls `SecurityBanService::ban($attackerIp, 'sip_scanner', $reason, $durationSeconds)` — **not** `SecurityExecutor::ban()` (see §1.3b) — so the database row, the audit entry, and the kernel set stay consistent and `FirewallBanReconciler` agrees with reality. `SecurityBanService` delegates to `SecurityExecutor`, which adds the element to `@banned_ips` with a kernel timeout and flushes the attacker's conntrack entries (see §1.3b) so its live SIP/RTP sessions die immediately.
- **Ban duration** comes from `sip_scanner_ban_seconds` (default `86400`; allowed `3600`–`604800`, plus `0` for permanent), not a hard-coded literal.
- **NAT awareness:** the ban is per source IP. A legitimate office behind one NAT shares a public address, so a single banned device can cut off every phone in the building. The ban reason must therefore be visible in the bans table (§3.4), and the panel copy must state plainly that blocking is by network address.

### 3.4 Custom Strings UI
In the **Attackers** tab of `/panel/security`:
- **SIP Bot & Scanner Signatures** card:
  - Toggle: *Instant Kernel Ban on Scanner Detection* — **ships off by default**; detection and recording are always on, only the automatic ban is opt-in (see the rollout note below).
  - Ban duration selector: *1 hour* / *24 hours (recommended)* / *7 days* / *Permanent*, persisted as `sip_scanner_ban_seconds`.
  - Default signatures tag list (read-only base, curated and versioned with the app), rendered as two visibly distinct groups — **Auto-ban** and **Observe only** — so an administrator can see at a glance which strings can cost someone their phone service.
  - Custom signatures input list (add/remove custom user-agents or from-usernames), each explicitly labelled as auto-banning on match.
  - Persisted in `security_settings` key `sip_scanner_signatures`, with the curated base kept in code (`Modules\Security\Support\SipScannerSignatures::defaults()`, returning a versioned array) so an application update can ship new defaults without touching administrator data.
- **Bans table enhancement:** every auto-ban row shows its `vector` and `reason` (for example `sip_scanner · User-Agent: friendly-scanner`) alongside an **Unban** action, so a false positive is diagnosable and reversible in seconds. A day-long network-wide ban with no visible cause is an unrecoverable support call.

**Rollout note (why the enforcement toggle ships off):** ship detection in observe-only mode for the first release — every match is recorded and displayed, nothing is banned — and let administrators switch enforcement on once they have watched a week of their own traffic. A security feature that is demonstrably safe gets left enabled; one that bans a customer on day one gets disabled permanently.

**Custom signature semantics (decision-complete):**
- Custom entries are treated as **literal substrings**, not regex. The renderer escapes each entry (`preg_quote`) before splicing it into the FreeSWITCH dialplan condition, so an admin typing `.` or `|` can never change the matching semantics.
- Input validation: non-empty, printable ASCII only, maximum 64 characters, case-insensitive deduplication against both the custom list and the curated base.
- **Additive-only**: the curated base is read-only; custom entries append to it and are individually removable. The base list can never be edited or deleted from the panel.
- The merge happens at XML render time, so signature changes inherit the existing dialplan cache TTL and invalidation behavior — no new cache plumbing.
- Modeled for future growth: custom TFTP patterns (see §2.3) will reuse the same "read-only base + custom additions" pattern.

---

## Deployment & Upgrade Requirements

This feature set changes code that lives **outside the git working tree**, so a `git pull` or the web updater alone will not deliver it to a server that is already installed. Every item below is release-blocking.

- **Bounded helper redeploy.** `scripts/resources/tallpbx-security` is installed to `/usr/local/sbin/tallpbx-security` (owned `root:www-data`, mode `0750`) by the installer. The new `update-threat-feed` action and the `apply`-time feed reload must reach **existing** installs, not just fresh ones. Add a helper version marker (a `# tallpbx-helper-version: N` comment the script can self-report) and have `SecurityExecutor` refuse the threat-feed action with a plain-language error — *"the security helper on this server is out of date; re-run the installer's security step"* — instead of the helper's opaque `Usage:` message. The installer's security resource script remains the redeploy mechanism.
- **`conntrack` package.** Existing servers do not have `/usr/sbin/conntrack`. Document `apt install conntrack` in `CHANGELOG.md` under `### Security`, and make the `flush-conntrack` action detect a missing binary and report it clearly rather than crashing — live-session severing degrades gracefully until the package is installed (invariant 4 then applies only to new flows).
- **Translations.** Every new string ships in `lang/en/admin.php`, `lang/es/admin.php`, and `lang/fr/admin.php` in the same change. Tab labels, tier labels, tooltip text, and validation errors are all administrator-facing copy.
- **Permissions.** Threat-feed settings, ban management, and signature management are **system-wide administrator features with no tenant-user exposure**. Reuse the existing `security.*` keys where they fit and add `security.threat-feeds.manage` for the feed controls; verify with the `module:sync --only-local` + `AdminSeeder` convention in `AGENTS.md`.
- **CHANGELOG.md.** `### Added` for the three features, `### Security` for the chain reorder, the bounded helper changes, and the new Linux package. Note the FreeSWITCH dialplan change explicitly — it invalidates the dialplan XML cache, which the render path already handles through its TTL.
- **Cache clearing.** `php artisan optimize:clear` after every change per `AGENTS.md`: the dialplan XML, routes, Blade, and config are all cached.
- **Scheduler required for bypass auto-revert.** The bounded bypass restores itself on the Laravel scheduler (`tallpbx-scheduler.service`). Note in `CHANGELOG.md` that a bypass cannot be relied upon to expire if the scheduler is stopped, and keep the overdue-banner behaviour (see *Firewall Disable & Troubleshooting Bypass Modes*) as the compensating control.

---

## Verification Plan

### Automated Feature & Unit Tests
1. **VoIPBL & Threat Feeds**:
   - `VoipblFeedProviderTest`: URL formatting with `?bc=` and `?wc=`; HTTP 200, 304, 429, 500 responses.
   - `ThreatFeedIngestionServiceTest`: streaming parser validates CIDRs with `AddressFamily::isValidAddressOrCidr()`, rejects invalid text, counts rejected lines, and preserves flat memory (<8 MB peak across a 100k-line parse).
   - **Fail-open tests**: a 500 response, a truncated body, and a parse yielding fewer than `threat_feed_min_entries` ranges each leave the previously loaded list untouched and record `last_status = 'failed'`.
   - `SecuritySyncThreatFeedsCommandTest`: scheduled execution, `--force`, staleness marking, and disable-clears-the-kernel-set.
   - `SecurityExecutorThreatFeedTest`: bounded helper `update-threat-feed` invocation — including that it is invoked with **no path argument** and rejects any attempt to pass one.
   - **Feed survival test**: after a full `apply`, `threat_feed.nft` is re-loaded and the feed set is not left empty (invariant 5).
2. **TFTP Defense**:
   - `SecurityConfigGeneratorTftpTest`: generated ruleset contains the WRQ drop, traversal drop, `/x` drop, and dual-family rate limiting when enabled; asserts `size` and `timeout` are present on the flood sets; asserts the rules are emitted **before** the port catalog's `udp dport 69 accept` (a regression here silently disables the feature); asserts the merged base + `custom` pattern list is what is rendered (custom empty by default), proving the §2.3 merge point without exposing a feature.
3. **SIP Bot Filtering**:
   - `LogSipScannerListenerTest`: ESL scanner events trigger `SecurityBanService::ban()` and audit logs; **low-confidence signatures record without banning**; two distinct low-confidence matches inside the window escalate; an existing active ban short-circuits with no second row or `sudo` spawn.
   - `SecurityManagerScannerUiTest`: custom bot strings can be added, validated, and saved; the tier groups render distinctly.
   - Custom-signature escaping test: custom entries are spliced as literal substrings (`preg_quote`), regex metacharacters (`.` `|` `(`) match only themselves, and oversized/empty/non-ASCII entries are rejected.
   - Attacker-IP fixture test: the emitted ESL event carries the socket peer address, and an event whose `Attacker-IP` is not a valid address is discarded.
4. **Conntrack Flush & Fast Path**:
   - `SecurityConfigGeneratorTest`: asserts the complete chain order — loopback, whitelist, invalid, fast path, blacklist, bans, feeds, ICMP, TFTP defense, port catalog, custom rules, default policy — matching the renumbering map in §1.3.
   - Helper test: `flush-conntrack` rejects malformed addresses and dual-stack mismatches, invokes `/usr/sbin/conntrack` with the correct family flag (`-f ipv6` for v6), and reports a missing `conntrack` binary clearly.
   - `SecurityBanServiceTest` and blacklist add tests: the flush is invoked after ban/blacklist add; feed-sync tests assert no flush occurs; a flush failure raises a `security_audit_logs` entry.
   - Pre-filter ordering (`SecurityConfigGeneratorPreFilterOrderTest`, `SecurityManagerPreFilterOrderTest`) — see *Pre-Filter Stage Reordering*. The permutation/validator test is the one that protects invariant 1 from being quietly relaxed.
   - Bypass modes (`SecurityBypassModeTest`, `LockoutGuardServiceRestoreTest`, `SecurityManagerBypassTest`) — see *Firewall Disable & Troubleshooting Bypass Modes*. The restore-side lockout test is the one that matters most.
5. **Mandatory Full Verification** (per `AGENTS.md`, in this order):
   ```bash
   php artisan optimize:clear
   php artisan test --compact --parallel
   ./vendor/bin/pest tests/Browser
   php artisan route:list --name=security
   ```

### Manual Verification
- Run `php artisan security:sync-threat-feeds --force` and inspect `/etc/tallpbx/threat_feed.nft` and the live `nft` set count.
- Toggle any unrelated firewall setting, re-apply, and confirm the feed set count is **unchanged** — this is the invariant 5 check, and it is the one most likely to regress.
- Disable the feed and confirm the kernel set empties; re-enable and confirm it repopulates before the next scheduled tick.
- Point the sync at an unreachable host (or serve an empty file) and confirm the previously loaded list survives untouched.
- Send simulated TFTP packets (`RRQ /x` and `WRQ`) using netcat/tftp client and verify the packet drop counters increment in `nft list table inet tallpbx_filter`; then issue a burst of legitimate RRQs from one address and confirm the configurable rate limit behaves as configured.
- Ban a live test IP and verify `conntrack -L -s <ip>` returns no entries and its established session dies immediately.
- Trigger a low-confidence signature (e.g. `User-Agent: SIP Call`) from a test endpoint and confirm it is **recorded but not banned**; then trigger a high-confidence signature with enforcement off and confirm the same; then enable enforcement and confirm the ban, its visible reason, and that Unban clears both the kernel set and the conntrack entries.
- Verify the Security Manager UI renders the new Threat Feeds tab, the threat-feed pre-filter row inside the Firewall Rules tab, and the TFTP protection toggles cleanly, and that every new string renders in `en`, `es`, and `fr`.
