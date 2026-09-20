# IPv6 Dual-Stack Firewall Support — Implementation Plan (Scope)

**Status:** Scoped, not started. The validation gap described in §1 is already fixed — IPv6 input is currently refused with a clear message so unusable entries can no longer brick firewall saves.

## 1. Why This Exists

During the September 2026 firewall-save incident, a single IPv6 whitelist entry (`2001:569:...`) collected through the UI made the **entire** generated ruleset uncompilable: the kernel sets are typed `ipv4_addr`, so `nft -c` rejected the whole file and every save was refused. A malformed manual ban (`344.34.34.34`) failed identically. The UI then kept displaying database state that the kernel could never enforce — the exact desync class the Security Center is now designed to prevent.

Two gaps therefore need closing long-term:

1. **Model gap** — the pipeline has no IPv6 address model even though the UI, the lockout guard (`IpUtils::checkIp`), and the helper script have all always accepted IPv6 input.
2. **Validation gap** — loose regexes allowed octets above 255 and unbounded CIDR prefixes through to the ruleset compiler.

The validation gap is **fixed** (shipped in the interim hardening). This document scopes the model gap.

## 2. Goals / Non-Goals

**Goals**

- Whitelist, blacklist, and banned IPs work for IPv6 exactly as they do for IPv4: UI entry → MariaDB record → compiled nftables rules → kernel drop/accept → Redis incident tracking → audit log.
- The zero-lockout guard protects IPv6-connected administrators the same way it protects IPv4 ones.
- Existing datasets that already contain IPv6 rows (currently uncompilable) begin working automatically once the feature ships.

**Non-Goals**

- FreeSWITCH/Sofia IPv6 SIP configuration (SIP over IPv6 requires separate PBX-side work; this plan only covers the host firewall).
- IPv6-specific intrusion tuning (thresholds stay shared per attack vector).
- Native IPv6 CIDR bans in the manual-ban dialog (bans stay single-address, as today).

## 3. Current State Inventory (verified)

| Layer | Today | File |
|---|---|---|
| Kernel table | Single `inet tallpbx_filter` table; both families traverse `chain input`; policy `drop` applies to both | `SecurityConfigGenerator` |
| IP sets | `blacklist_ips`, `banned_ips` (`flags timeout`), `whitelist_ips` — all `type ipv4_addr`; `127.0.0.1` always injected | `SecurityConfigGenerator` |
| Dual-stack precedent | IPv6 already handled for invariants: ICMPv6 echo rate-limit, Neighbor Discovery / Router Advertisement accepts, `ct state` rules are family-agnostic | `SecurityConfigGenerator` |
| Helper | `apply` / `validate` / `ban` / `unban` / `status`; ban/unban act on `@banned_ips` (v4 set) via `nft add/delete element`; regexes now strictly bounded (v4 ≤ /32, v6 ≤ /128) — the IPv6 regex is already written to production quality | `scripts/resources/tallpbx-security` |
| Ban service | `SecurityBanService::ban()` currently refuses non-IPv4 (interim guard, to be removed here); Redis counters (`tallpbx:security:attempts:{vector}:{ip}`) are family-agnostic | `SecurityBanService` |
| Incident engine | Counts failures per IP + vector in Redis; banned via ban service at threshold — no family assumptions | `SecurityIncidentService` |
| Database | `security_bans.ip_address` and `security_ip_lists.ip_address` are `varchar(100)`; unique key `(type, ip_address)` — IPv6 (max 45 chars incl. CIDR) fits with no migration | migrations `...000003`, `...000005` |
| Lockout guard | `IpUtils::checkIp()` already matches IPv6 and CIDR; the "Protect my IP" auto-whitelist stores whatever address the session reports | `LockoutGuardService` |
| UI | Whitelist / blacklist / legacy list / manual-ban forms; interim IPv6 refusal with a translated hint | `SecurityManager` |

## 4. Design

### 4.1 Shared address model

Introduce one small module-level helper (e.g. `Modules\Security\Support\AddressFamily`) exposing:

- `classify(string $value): 'ipv4' | 'ipv6' | 'invalid'` — handles plain addresses and CIDR notation.
- `isValidForFamily(string $value, string $family): bool` — strict octet/prefix bounds per family.

Used by the Livewire forms, `SecurityBanService`, and mirrored by the helper's bash regexes (already correct today).

### 4.2 Generator: parallel IPv6 sets

- Declare three additional sets alongside the existing ones:

  ```
  set blacklist_ips6 { type ipv6_addr; flags interval; ... }
  set banned_ips6    { type ipv6_addr; flags timeout; ... }
  set whitelist_ips6 { type ipv6_addr; flags interval; ... }
  ```

- Split MariaDB rows by family when compiling elements; mirror the `127.0.0.1` invariant with a guaranteed `::1` whitelist element.
- Add IPv6 rules at the **same pipeline positions** as their IPv4 twins:

  ```
  ip saddr @blacklist_ips  drop      →  ip6 saddr @blacklist_ips6  drop
  ip saddr @banned_ips     drop      →  ip6 saddr @banned_ips6     drop
  ip saddr @whitelist_ips  accept    →  ip6 saddr @whitelist_ips6  accept
  ```

  (Blacklist drop stays ahead of the whitelist bypass on both families — the kernel-evaluation order that protects against whitelist-defeating blacklist entries.)
- Keep all six sets declared even when empty, so ruleset structure stays stable for tooling and tests.

### 4.3 Helper: family-aware ban/unban

- `ban` / `unban`: classify the (already strictly validated) address with `inet_pton` and target `banned_ips` vs `banned_ips6` accordingly. No new actions, no sudoers change (the drop-in already bounds the whole binary).
- `apply` / `validate` / `status` need no changes.

### 4.4 Services

- Remove the interim non-IPv4 guard in `SecurityBanService::ban()`.
- Audit log and reason strings are address-agnostic; nothing else changes.

### 4.5 UI

- Replace the interim "IPv6 not supported" hint with full validation; restore IPv6 acceptance in the whitelist, blacklist, and manual-ban forms.
- Update placeholders/labels where they imply IPv4-only, in English, Spanish, and French.
- Verify the lockout banner and "Protect my IP" button behavior for IPv6-connected admins.

### 4.6 Tests

- **Generator:** dual-set compilation; family split of mixed DB rows (incl. CIDR in v6 sets); `::1` invariant; rule-order parity with the IPv4 pipeline.
- **Helper:** `ban` / `unban` add/remove elements from `@banned_ips6` (isolated `TALLPBX_FIREWALL_CONF_DIR`); strict-regex rejection matrix (already shipped).
- **Service:** IPv6 ban/unban lifecycle; Redis key handling.
- **Livewire:** v6 + CIDR accepted; invalid forms rejected with clear messages.
- **Lockout guard:** v6-connected admin protection paths.
- All tests stay hermetic (stubbed executor, isolated ruleset directory).

## 5. Rollout

1. No database migrations required (verified column widths).
2. Ship generator + helper + services + UI together; a host that already contains IPv6 entries starts compiling valid rulesets immediately.
3. Revert `docs/security-architecture.md` and UI copy references to the interim restriction.
4. CHANGELOG entry under `[Unreleased]` when implemented; no installer changes expected.

## 6. Open Decisions

- **Auto-ban scope for IPv6 attackers:** enable symmetric v6 banning by default (recommended), or gate it behind a setting until validated in the field?
- **Placeholder wording** for the shared IP/CIDR fields across the three locales (example-based, e.g. "203.0.113.50 or 2001:db8::1").
- **Out-of-band review** of FreeSWITCH IPv6 SIP readiness separately from this firewall scope.

## 7. Risks

- **Family mixing** in a set must remain impossible: one stray element of the wrong family invalidates the entire ruleset (this exact failure mode caused the incident). Mitigation: strict classification at every boundary (UI, service, generator split) plus compile tests.
- **Interim guard removal ordering:** the guard must only be removed in the same release that ships generator + helper support, otherwise regressions reappear.
- **Lockout edge:** an IPv6-only-connected administrator under `drop` needs the v6 whitelist accept rule to be live before the guard is satisfied — covered by the lockout guard's existing family awareness and the `::1`/`whitelist_ips6` design above.
