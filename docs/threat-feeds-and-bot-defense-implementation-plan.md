# Threat Feeds, Hardened TFTP Defense, and SIP Bot Filtering Implementation Plan

## Executive Summary
This document specifies the architecture and implementation roadmap for three integrated security enhancements in TallPBX:
1. **Phase 1: VoIPBL Threat Feed & Extensible Threat Intelligence Architecture** — Scheduled ingestion of ~100,000 bad actor subnets from `voipbl.org` using country filtering, HTTP conditional caching (`ETag` / `304 Not Modified`), streaming file processing, and atomic Linux `nftables` interval set swapping (`@threat_feed_ips`). Built on an extensible provider driver pattern (`ThreatFeedProviderInterface`).
2. **Phase 2: Hardened TFTP Defense Profile (Native `nftables`)** — Multi-layered TFTP packet filtering on UDP port 69 blocking Write Requests (WRQ), directory traversal (`../` $\rightarrow$ `0x2e2e2f`), known malware probes (`/x` $\rightarrow$ `0x2f78`), and stateful per-IP rate limiting via kernel meters (10/min sustained, burst 20).
3. **Phase 3: SIP Bot String Filtering & Instant Kernel Auto-Ban** — Deep packet inspection in FreeSWITCH (Sofia/dialplan regex) for known scanning bots (`sipvicious`, `friendly-scanner`, `VaxSIPUserAgent`, `sipcli`, `SIP Call`, `Ozeki`, plus custom administrator strings). Matches trigger immediate Event Socket Layer (ESL) security events that automatically invoke the bounded host helper to lock the offending IP in kernel RAM (`@banned_ips`) for 24 hours on its very first packet.

---

## Architectural Principles & Invariants

> [!IMPORTANT]
> **Firewall Precedence Invariant**:
> In `nftables`, `@whitelist_ips` will unconditionally be accepted *before* `@threat_feed_ips` is evaluated. This guarantees that administrator IPs and carrier SIP gateways can never be locked out if a third-party feed produces a false positive.

> [!IMPORTANT]
> **Bounded Host Helper Execution**:
> All Linux firewall mutations run through `/usr/local/sbin/tallpbx-security`, owned by `root:www-data` (mode `0750`), accessible via `/etc/sudoers.d/tallpbx-security`. The web user (`www-data`) never executes arbitrary shell commands or wildcard sudo binaries.

> [!NOTE]
> **Database & Memory Isolation**:
> Threat feed CIDRs are not stored as individual rows in MariaDB. Instead, they are streamed to disk and loaded into an `nftables` kernel interval set (`flags interval;`). MariaDB stores only feed settings, country filters, and sync metadata.

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
  - Input chain evaluation order:
    ```nftables
    # STEP 1: Loopback
    iif "lo" accept

    # STEP 2: Manual Blacklists
    ip saddr @blacklist_ips drop
    ip6 saddr @blacklist_ips6 drop

    # STEP 3: Dynamic Intrusion Bans
    ip saddr @banned_ips drop
    ip6 saddr @banned_ips6 drop

    # STEP 4: Stateful Connection Tracking
    ct state established,related accept
    ct state invalid drop

    # STEP 5: Manual Whitelist (Immune to threat feeds)
    ip saddr @whitelist_ips accept
    ip6 saddr @whitelist_ips6 accept

    # STEP 6: Automated Public Threat Feeds
    ip saddr @threat_feed_ips drop
    ip6 saddr @threat_feed_ips6 drop
    ```

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
In `/panel/security`:
- Add **Public Threat Feeds / Blocklists** workbench card.
- Shows VoIPBL status badge (Active, Idle, Syncing, Error, Disabled).
- Radio toggles for Country Mode: *All Countries*, *Filter by Threat Origin (`bc`)*, *Exclude Home Countries (`wc`)*.
- Country code selector input.
- Sync Interval dropdown.
- Metrics display: Active CIDRs, last sync timestamp, and "Sync Now" button.

---

## Phase 2: Hardened TFTP Defense Profile (Native `nftables`)

### 2.1 Defense Ruleset
When TFTP protection is enabled, `SecurityConfigGenerator` inserts the following native `nftables` rules into the input chain:
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
- In the Security Command Center's **PBX Port Catalog & Services** table, add a shield badge and toggle for the **TFTP Provisioning (UDP 69)** row:
  - *"Hardened TFTP Defense Profile: Blocks WRQ uploads, path traversal (`../`), `/x` probes, and applies flood rate limiting."*

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
  4. Calls `SecurityExecutor::ban($attackerIp, 86400)` (24-hour instant kernel drop in `@banned_ips`).

### 3.4 Custom Strings UI
In `/panel/security` under **Intrusion Protection**:
- **SIP Bot & Scanner Signatures** card:
  - Toggle: *Instant 24-Hour Kernel Ban on Scanner Detection*.
  - Default signatures tag list (read-only base).
  - Custom signatures input list (add/remove custom user-agents or from-usernames).
  - Persisted in `security_settings` key `sip_scanner_signatures`.

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
3. **SIP Bot Filtering**:
   - `LogSipScannerListenerTest`: Verifies ESL scanner events immediately trigger `SecurityExecutor::ban` and audit logs.
   - `SecurityManagerScannerUiTest`: Verifies custom bot strings can be added, validated, and saved.
4. **Mandatory Full Verification**:
   ```bash
   php artisan optimize:clear
   php artisan test --compact
   ```

### Manual Verification
- Run `php artisan security:sync-threat-feeds --force` and inspect `/etc/tallpbx/threat_feed.nft` and live `nft` set count.
- Send simulated TFTP packets (`RRQ /x` and `WRQ`) using netcat/tftp client and verify packet drop counters increment in `nft list table inet tallpbx_filter`.
- Verify the Security Manager UI renders the new Threat Feeds card and TFTP protection toggles cleanly.
