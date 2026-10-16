# Implementation Plan: Dedicated PBX Geo-Blocking Subsystem (IPdeny Engine)

**Document Version:** 1.0  
**Target Branch:** `4.x`  
**Target Module:** `app-modules/security/`  
**Status:** Draft — Awaiting User Approval  

---

## 1. Executive Summary & Problem Analysis

### 1.1 Context & Objectives
While dynamic threat intelligence feeds (such as VoIPBL) protect the PBX against known active VoIP scanners, administrators frequently require **policy-based geographic perimeter protection**:
1. **Blacklist Mode**: Block all traffic originating from specific high-risk countries (e.g., notorious scanning origins).
2. **Whitelist / Geofence Mode**: Allow traffic originating only from authorized home countries (e.g., US & Canada) and drop traffic from the rest of the world.

### 1.2 Architectural Decision: Dedicated Tab & Engine
Geo-blocking is not a threat feed; it is an ingress boundary policy:
- **Inverted Firewall Logic**: Whitelist mode requires an inverted match ($\text{IP} \notin \text{@geo\_allowed\_ips} \rightarrow \text{drop}$).
- **Data Scale**: Country allocations encompass 50,000 to 200,000+ CIDRs, requiring aggregated CIDR optimization.
- **Port & Service Scoping**: Blanket country drops break cloud SIP carrier failover (e.g. Twilio/Telnyx international media PoPs), Apple APNs / Google FCM push notifications, and billing/CRM webhooks. Geo-blocking **must** support port scoping (**SIP & Media Only** vs. **Entire Server**).
- **Decision**: Implement a first-class, dedicated **Geo-Blocking** tab in Security Center backed by IPdeny's pre-aggregated zone files.

---

## 2. Technical Architecture & Data Ingestion

### 2.1 Provider: IPdeny Aggregated Zones
1. **Endpoints**:
   - **IPv4**: `https://www.ipdeny.com/ipblocks/data/aggregated/{cc}-aggregated.zone`
   - **IPv6**: `https://www.ipdeny.com/ipv6/ipblocks/data/aggregated/{cc}-aggregated.zone`
2. **Advantages of Aggregated Zones (`-aggregated.zone`)**:
   - Merges contiguous adjacent subnets using CIDR arithmetic.
   - Reduces total kernel set entries and compilation time by ~50% compared to raw zone files.
   - Requires zero user registration, accounts, or API keys (unlike MaxMind).
3. **Fail-Open Local Caching & Fallback**:
   - Downloaded and validated country zones are cached in `/etc/tallpbx/geoip/`.
   - If a scheduled refresh fails or times out, the firewall retains the last good ruleset without interruption.
   - If `ipdeny.com` is unreachable, the service falls back to the automated GitHub/Fastly RIR mirror (`raw.githubusercontent.com/herrbischoff/country-ip-blocks/master/`).
4. **Sync Cadence**:
   - Decoupled from threat feeds. Country allocations change slowly; default sync interval is **Weekly** (configurable to Monthly or On-Demand).

---

## 3. Operational Modes & Lockout Safety

### 3.1 Operating Modes
1. **Mode A: Block Selected Countries (Blacklist)**:
   - Drops traffic matching `@geo_blocked_ips`.
2. **Mode B: Allow Only Selected Countries (Whitelist / Geofence)**:
   - Drops traffic *not* matching `@geo_allowed_ips`.

### 3.2 PBX Traffic / Port Scoping (Anti-Disruption)
1. **SIP Signaling & Media Only (Recommended Default)**:
   - Evaluated strictly against UDP/TCP 5060, 5080, and UDP 16384–32768 (FreeSWITCH RTP audio).
   - Shields the telephony engine while keeping Web Panel, SSH, webhooks (Stripe, CRM), and push notification relays (Apple/Google) operational.
2. **Entire Server (All Inbound Ports)**:
   - Applies globally to all inbound ports.
   - Requires explicit administrative confirmation before activation.

### 3.3 Lockout Prevention & Invariant Guards
1. **Strict Pipeline Order**:
   - `ct state established,related accept` is evaluated **before** Geo-Blocking (active sessions are never severed mid-action).
   - Loopback (`iif "lo" accept`) and Private LAN / RFC1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`) **always bypass** Geo-Blocking.
   - Administrator Whitelist (`@whitelist_ips`) is evaluated **before** Geo-Blocking.
2. **Self-IP Warning Guard**:
   - Detects and displays the administrator's current public IP and origin country.
   - If a Geofence excludes the administrator's detected country without their IP being in the Whitelist, saving is blocked with an explanatory warning.

---

## 4. UI Design & Cockpit Integration

### 4.1 New "Geo-Blocking" Tab
- Tab button in `security-manager.blade.php`: `<x-heroicon-o-globe-americas class="w-4 h-4" /> Geo-Blocking`.
- Top card header with status badge (`Active` / `Disabled`), `Enable/Disable` toggle, `Sync Now` button, and `Clear Rules` button.
- Mode Selector: Radio cards for **"Block selected countries"** vs. **"Allow only selected countries"**.
- Port Scope Selector: Radio cards for **"SIP Signaling & Media Only (Safe Default)"** vs. **"Entire Server"**.
- Country Multi-Select: Searchable dropdown / tag selector supporting ISO 3166-1 alpha-2 country codes with full country names.
- Metrics Bar: Active CIDRs compiled (IPv4 / IPv6), Last synced timestamp, Packets/bytes dropped by Geo-filter.

### 4.2 Firewall Rules Master Cockpit Parity
- Add a distinct row in `tabs/firewall-rules.blade.php` for **Geographic Filtering** with its own toggle and packet drop counters.

---

## 5. File Layout & Deliverables

```
app-modules/security/
├── database/migrations/
│   └── 2026_10_16_000002_create_security_geo_rules_table.php
├── src/
│   ├── Contracts/
│   │   └── GeoBlockingProviderInterface.php
│   ├── Models/
│   │   └── SecurityGeoRule.php
│   ├── Services/
│   │   ├── GeoBlockingService.php
│   │   ├── IpdenyProvider.php
│   │   └── SecurityConfigGenerator.php (updated for geo sets/rules)
│   ├── Livewire/
│   │   ├── Concerns/
│   │   │   └── ManagesGeoBlocking.php
│   │   └── SecurityManager.php (registered active tab & concern)
│   └── Console/Commands/
│       └── SecuritySyncGeoIpCommand.php
├── resources/views/
│   ├── security-manager.blade.php (add tab button & include)
│   ├── tabs/
│   │   ├── geo-blocking.blade.php (new)
│   │   └── firewall-rules.blade.php (add geo row)
│   └── lang/
│       ├── en/admin.php
│       ├── es/admin.php
│       └── fr/admin.php
└── tests/
    ├── Unit/
    │   ├── IpdenyProviderTest.php
    │   └── GeoBlockingServiceTest.php
    └── Feature/
        ├── SecurityManagerGeoBlockingTest.php
        └── SecurityConfigGeneratorGeoTest.php
```

---

## 6. Verification Checklist

1. `IpdenyProviderTest`: Tests IPv4 and IPv6 aggregated endpoints, streaming line parser, and fallback mirror.
2. `SecurityConfigGeneratorGeoTest`: Tests `nftables` syntax for both modes (blacklist vs. whitelist) and scopes (SIP-only vs. all-traffic), validating that loopback and `@whitelist_ips` always precede geo-rules.
3. `SecurityManagerGeoBlockingTest`: Tests tab state, mode switching, country selection, self-lockout guards, and sync triggers.
4. Pre-commit checklist: `php artisan optimize:clear`, `./vendor/bin/pint --dirty`, `php artisan test --compact --parallel`.
