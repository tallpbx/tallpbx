# Threat Feeds, Hardened TFTP Defense, and SIP Bot Filtering Implementation Plan

## Executive Summary
This document specifies the architecture and implementation roadmap for three integrated security enhancements in TallPBX:
1. **Phase 1: VoIPBL Threat Feed & Extensible Threat Intelligence Architecture** — Scheduled ingestion of ~100,000 bad actor subnets from `voipbl.org` using country filtering, HTTP conditional caching (`ETag` / `304 Not Modified`), streaming file processing, and atomic Linux `nftables` interval set swapping (`@threat_feed_ips`). Built on an extensible provider driver pattern (`ThreatFeedProviderInterface`).
2. **Phase 2: Hardened TFTP Defense Profile (Native `nftables`)** — Multi-layered TFTP packet filtering on UDP port 69 blocking Write Requests (WRQ), directory traversal (`../` $\rightarrow$ `0x2e2e2f`), known malware probes (`/x` $\rightarrow$ `0x2f78`), and stateful per-IP rate limiting via kernel meters (10/min sustained, burst 20).
3. **Phase 3: SIP Bot String Filtering & Instant Kernel Auto-Ban** — Deep packet inspection in FreeSWITCH (Sofia/dialplan regex) for known scanning bots (`sipvicious`, `friendly-scanner`, `VaxSIPUserAgent`, `sipcli`, `SIP Call`, `Ozeki`, plus custom administrator strings). Matches trigger immediate Event Socket Layer (ESL) security events that automatically invoke the bounded host helper to lock the offending IP in kernel RAM (`@banned_ips`) for 24 hours on its very first packet.

---

## Architectural Principles & Invariants

> [!IMPORTANT]
> **Firewall Precedence & Fast-Path Invariants**:
> The input chain follows the hardened stateful order (see §1.3). Four invariants must never be violated:
> 1. `@whitelist_ips` is evaluated **before every drop rule and the stateful fast path** — trusted IPs are accepted unconditionally and remain independent of connection-tracking state, so the administrator and carrier SIP gateways can never be locked out (not even by a feed false positive or a conntrack flush event).
> 2. `ct state invalid drop` precedes the whitelist, so even trusted IPs cannot deliver malformed packets.
> 3. The `ct state established,related accept` fast path sits **before the blocklists** so the bulk of SIP/RTP media passes with zero set lookups; the blocklists evaluate only new flows.
> 4. Offender removal is achieved at **ban time, not per packet**: when an IP enters `@blacklist_ips` or `@banned_ips`, the bounded helper flushes the IP's conntrack entries (`conntrack -D -s <ip>`), severing its live sessions instantly.

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
- **Broken packets are turned away at the door (invalid).** A packet that doesn't belong to any real conversation is dropped immediately — before anyone bothers to check a list.
- **Trusted addresses always get in (whitelist).** The administrator's own connection and your SIP provider are on a short VIP list that is checked first. The kernel itself enforces this, so no mistake on any other list can ever lock the administrator out.
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
- Whitelist card helper text: note that trusted IPs still drop malformed packets ("Trusted addresses always get in — broken packets are still turned away").

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
| 1 | **Block & Allow Lists** | Stage 3 — `@whitelist_ips` (bypass) then Stage 5 — `@blacklist_ips` (permanent drop) | Whitelist card first, blacklist card second — both always visible with their own quick-add forms, no switcher (existing Cards 3 + 1) |
| 2 | **Attackers** | Stage 6 — `@banned_ips` (dynamic drop) | Instant-ban toggle, SIP bot signatures, then the active bans table (existing Card 2) |
| 3 | **Threat Feeds** | Stage 7 — `@threat_feed_ips` (feed drop) | VoIPBL status, country mode, sync interval, metrics, Sync Now |
| 4 | **Firewall Rules** | Full pipeline (last) | Sequential rules table, PBX port catalog, TFTP defense toggle, custom rules, default policy |

Kernel stages 1 (loopback), 2 (invalid packets), and 4 (stateful fast path) are non-configurable invariants with no workbench of their own; they appear only as rows inside the Firewall Rules tab. The whitelist and blacklist share one tab because the reordered chain (see §1.3) makes them adjacent modulo the invariant fast path, and because admins frequently check both lists together.

### Ordering Within Tabs

- **Block & Allow Lists**: the whitelist card renders first and the blacklist card second, matching their kernel evaluation order (Stage 3 → Stage 5).
- **Attackers**: the auto-ban toggle and SIP bot scanner signatures come first (they are the producers that feed `@banned_ips`), followed by the active bans table.
- **Firewall Rules**: rows already render in evaluation order; the new threat-feed pre-filter row is inserted immediately after the active-attackers row (Stage 6 → Stage 7).
- **Threat Feeds**: enable/status first, then country mode, then sync controls and metrics.

### Design Decisions & Trade-offs

- **The kernel chain was reordered for RTP performance and kernel-enforced trust** (see §1.3 and the invariant list): whitelist first, then the stateful fast path, then the blocklists. Every established SIP/RTP packet now costs three cheap checks and zero set lookups.
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
- **Direct-to-Disk Stream**: Streams download body into a temporary file (`/tmp/voipbl_feed.tmp`), parsing line-by-line via `fgets()` to keep PHP memory flat (<8 MB).
- **Strict CIDR Validation**: Validates each line with `filter_var(..., FILTER_VALIDATE_IP)` before generating the ruleset include.
- **Output Compilation**: Generates `/etc/tallpbx/threat_feed.nft.pending`.

### 1.3 Kernel Integration & Bounded Helper
- **Bounded Helper Action (`scripts/resources/tallpbx-security`)**:
  - Add action `update-threat-feed <file>`.
  - Validates file path and `.nft` extension.
  - Preflights syntax: `$NFT_BIN -c -f "$FILE"`.
  - Atomically moves to `/etc/tallpbx/threat_feed.nft` and loads via `$NFT_BIN -f /etc/tallpbx/threat_feed.nft`.
- **NFTables Ruleset Structure (`SecurityConfigGenerator.php`)**:
  - Declares sets in `table inet tallpbx_filter`:
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
  - Input chain evaluation order (final, RTP-optimized):
    ```nftables
    # STEP 1: Loopback invariant
    iif "lo" accept

    # STEP 2: Invalid packet defense (before the whitelist on purpose —
    # even trusted IPs may not deliver broken packets)
    ct state invalid drop

    # STEP 3: Whitelist override (admin safety net, conntrack-independent)
    ip saddr @whitelist_ips accept
    ip6 saddr @whitelist_ips6 accept

    # STEP 4: STATEFUL FAST PATH — passes the bulk of ongoing SIP/RTP
    # instantly with zero set lookups
    ct state established,related accept

    # STEP 5: Manual Blacklists (only new flows reach this point)
    ip saddr @blacklist_ips drop
    ip6 saddr @blacklist_ips6 drop

    # STEP 6: Dynamic Intrusion Bans
    ip saddr @banned_ips drop
    ip6 saddr @banned_ips6 drop

    # STEP 7: Automated Public Threat Feeds
    ip saddr @threat_feed_ips drop
    ip6 saddr @threat_feed_ips6 drop
    ```

### 1.3b Conntrack Flush for Instant Offender Removal
Because the blocklists evaluate only new flows (they sit behind the fast path), a newly blocked or banned IP's *live* sessions must be severed explicitly at ban time:

- **Linux package**: `apt install conntrack` (provides the `/usr/sbin/conntrack` CLI). Added to the security resource script so fresh installs include it.
- **Bounded Helper Action**: add `flush-conntrack <ip>` to `/usr/local/sbin/tallpbx-security`, following the existing pattern: strict dual-stack IP validation (same validator as `ban`), hardcoded binary path, `set -euo pipefail`, and family-aware invocation — `conntrack -D -s <ip>` for IPv4, `conntrack -D -f ipv6 -s <ip>` for IPv6.
- **Call sites**: `SecurityBanService::ban()` and the blacklist add paths (`addBlacklistIp`, `promoteToBlacklist`) invoke the flush **after** the kernel set mutation succeeds. Threat-feed sync does **not** flush (flushing per-CIDR for ~100k entries is pointless; feeds gate new flows only).
- **Order & failure tolerance**: set mutation first, flush second. If the flush fails, log and continue — the new-flow block is already active, so only live-session severing is degraded.

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
- `timestamps`

### 1.5 Scheduled CLI Command
- Artisan Command: `php artisan security:sync-threat-feeds {--feed=} {--force}`
- Registered in `routes/console.php` with an hourly schedule.

### 1.6 Security Manager UI Integration
In the **Threat Feeds** tab of `/panel/security`:
- Add **Public Threat Feeds / Blocklists** tab panel.
- Shows VoIPBL status badge (Active, Idle, Syncing, Error, Disabled).
- Radio toggles for Country Mode: *All Countries*, *Filter by Threat Origin (`bc`)*, *Exclude Home Countries (`wc`)*.
- Country code selector input.
- Sync Interval dropdown.
- Metrics display: Active CIDRs, last sync timestamp, and "Sync Now" button.

---

## Phase 2: Hardened TFTP Defense Profile (Native `nftables`)

### 2.1 Defense Ruleset
When TFTP protection is enabled, `SecurityConfigGenerator` inserts the following native `nftables` rules into the **services section of the input chain (after STEP 7)**. Deep packet inspection inherently only sees new flows there — established TFTP transfers already passed the stateful fast path — which is the correct and cheapest placement:
```nftables
# 1. Block TFTP Write Requests (Opcode 2) - Provisioning is strictly read-only
udp dport 69 @th,64,16 0x0002 counter drop

# 2. Block Directory Traversal (Requests starting with "../" -> 0x2e2e2f)
udp dport 69 @th,64,16 0x0001 @th,80,24 0x2e2e2f counter drop

# 3. Block Malicious Scan Probes (Requests starting with "/x" -> 0x2f78)
udp dport 69 @th,64,16 0x0001 @th,80,16 0x2f78 counter drop

# 4. Stateful Per-IP Rate Limiting (10/min sustained, burst 20 packets)
udp dport 69 meter tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter drop
udp dport 69 meter tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter drop
```

### 2.2 Security Configuration & UI
- Add setting `tftp_defense_enabled` (boolean, default `true`).
- In the **Firewall Rules** tab's **PBX Port Catalog & Services** table, add a shield badge and toggle for the **TFTP Provisioning (UDP 69)** row:
  - *"Hardened TFTP Defense Profile: Blocks WRQ uploads, path traversal (`../`), `/x` probes, and applies flood rate limiting."*

### 2.3 Future Extensibility: Custom Patterns (Deferred)
Custom TFTP patterns are explicitly **out of scope for this release**, but the code is structured so they can be added later without rework:

- `SecurityConfigGenerator` emits the defense rules by iterating over a **merged pattern list** (`base patterns` + `custom patterns`) instead of hardcoding the three rules individually. The custom list is empty by default, so the generated output is byte-identical to the fixed rules today.
- Reserve the settings key `tftp_defense_custom_patterns` (default `[]`). No UI, no validation path, and no custom-pattern drop rules are exposed yet — only the merge point exists.
- Documented design for the eventual feature: entries are plain-text strings (e.g. `../`, `/x`, `busybox`) converted to `@th` hex matches by the generator, with a maximum length limit and validation at input time; the same `nft -c` bounded-helper preflight applies.

---

## Phase 3: SIP Bot String Filtering & Instant Kernel Auto-Ban

### 3.1 Default Scanner Signatures
The baseline list of bad bot signatures:
- `From: "sipvicious"`
- `User-Agent: friendly-scanner`
- `User-Agent: VaxSIPUserAgent`
- `User-Agent: sipcli`
- `User-Agent: sipcli/v1.8`
- `User-Agent: SIP Call`
- `User-Agent: Ozeki`

### 3.2 FreeSWITCH Detection & ESL Event Emission
- In FreeSWITCH Sofia SIP profiles or early dialplan routing (`public.xml`), match incoming requests against the scanner regex:
  ```xml
  <condition field="${sip_user_agent}" expression="friendly-scanner|VaxSIPUserAgent|sipcli|SIP Call|Ozeki">
      <action application="set" data="proto_security_violation=1"/>
      <action application="event" data="Event-Name=CUSTOM,Event-Subclass=tallpbx::sip_scanner_detected,Scanner-Type=User-Agent,Scanner-Value=${sip_user_agent},Attacker-IP=${network_addr}"/>
      <action application="respond" data="403 Forbidden"/>
      <action application="hangup"/>
  </condition>
  <condition field="${sip_from_user}" expression="sipvicious">
      <action application="event" data="Event-Name=CUSTOM,Event-Subclass=tallpbx::sip_scanner_detected,Scanner-Type=From-User,Scanner-Value=${sip_from_user},Attacker-IP=${network_addr}"/>
      <action application="respond" data="403 Forbidden"/>
      <action application="hangup"/>
  </condition>
  ```

### 3.3 Event Listener & Instant Kernel Ban
- Event Listener: `Modules\Security\Listeners\LogSipScannerListener`
- Subscribed to `tallpbx::sip_scanner_detected` ESL events and Sofia auth failures.
- On trigger:
  1. Validates attacker IP format.
  2. Confirms attacker IP is not in `@whitelist_ips`.
  3. Records security incident in `security_bans` and `security_audit_logs`.
  4. Calls `SecurityExecutor::ban($attackerIp, 86400)` (24-hour instant kernel drop in `@banned_ips`), which also flushes the attacker's conntrack entries (see §1.3b) so its live SIP/RTP sessions die immediately.

### 3.4 Custom Strings UI
In the **Attackers** tab of `/panel/security`:
- **SIP Bot & Scanner Signatures** card:
  - Toggle: *Instant 24-Hour Kernel Ban on Scanner Detection*.
  - Default signatures tag list (read-only base, curated and versioned with the app).
  - Custom signatures input list (add/remove custom user-agents or from-usernames).
  - Persisted in `security_settings` key `sip_scanner_signatures`.

**Custom signature semantics (decision-complete):**
- Custom entries are treated as **literal substrings**, not regex. The renderer escapes each entry (`preg_quote`) before splicing it into the FreeSWITCH dialplan condition, so an admin typing `.` or `|` can never change the matching semantics.
- Input validation: non-empty, printable ASCII only, maximum 64 characters, case-insensitive deduplication against both the custom list and the curated base.
- **Additive-only**: the curated base is read-only; custom entries append to it and are individually removable. The base list can never be edited or deleted from the panel.
- The merge happens at XML render time, so signature changes inherit the existing dialplan cache TTL and invalidation behavior — no new cache plumbing.
- Modeled for future growth: custom TFTP patterns (see §2.3) will reuse the same "read-only base + custom additions" pattern.

---

## Verification Plan

### Automated Feature & Unit Tests
1. **VoIPBL & Threat Feeds**:
   - `VoipblFeedProviderTest`: URL formatting with `?bc=` and `?wc=`; HTTP 200, 304, 429, 500 responses.
   - `ThreatFeedIngestionServiceTest`: Streaming parser validates CIDRs, rejects invalid text, preserves flat memory (<8 MB).
   - `SecuritySyncThreatFeedsCommandTest`: Verifies scheduled execution and `--force` flag.
   - `SecurityExecutorThreatFeedTest`: Tests bounded helper `update-threat-feed` invocation.
2. **TFTP Defense**:
   - `SecurityConfigGeneratorTftpTest`: Verifies generated ruleset contains WRQ drop, traversal drop, `/x` drop, and dual-family rate limit meters when enabled.
   - Asserts the defense rules are emitted by iterating the merged base+`custom` pattern list (custom list empty by default), proving the future custom-pattern merge point (see §2.3) without exposing a feature.
3. **SIP Bot Filtering**:
   - `LogSipScannerListenerTest`: Verifies ESL scanner events immediately trigger `SecurityExecutor::ban` and audit logs.
   - `SecurityManagerScannerUiTest`: Verifies custom bot strings can be added, validated, and saved.
   - Custom-signature escaping test: proves custom entries are spliced as literal substrings (`preg_quote`), that regex metacharacters (`.` `|` `(`) match only themselves, and that oversized/empty/non-ASCII entries are rejected.
4. **Conntrack Flush & Fast Path**:
   - `SecurityConfigGeneratorTest`: Asserts the new chain order — loopback, invalid drop, whitelist, fast path, blacklist, bans, feeds.
   - Helper test: `flush-conntrack` rejects malformed addresses and dual-stack mismatches, and invokes `/usr/sbin/conntrack` with the correct family flag (`-f ipv6` for v6).
   - `SecurityBanServiceTest` and blacklist add tests: assert the flush is invoked after ban/blacklist add; feed-sync tests assert no flush occurs.
5. **Mandatory Full Verification**:
   ```bash
   php artisan optimize:clear
   php artisan test --compact
   ```

### Manual Verification
- Run `php artisan security:sync-threat-feeds --force` and inspect `/etc/tallpbx/threat_feed.nft` and live `nft` set count.
- Send simulated TFTP packets (`RRQ /x` and `WRQ`) using netcat/tftp client and verify packet drop counters increment in `nft list table inet tallpbx_filter`.
- Ban a live test IP and verify `conntrack -L -s <ip>` returns no entries and its established session dies immediately.
- Verify the Security Manager UI renders the new Threat Feeds tab, the threat-feed pre-filter row inside the Firewall Rules tab, and the TFTP protection toggles cleanly.
