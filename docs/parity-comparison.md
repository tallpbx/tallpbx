# TallPBX Feature and Function Parity: FusionPBX & FreePBX®

This document provides a detailed feature-by-feature and architectural comparison between **TallPBX**, **FusionPBX**, and **FreePBX®**.

- **FusionPBX Baseline**: [github.com/fusionpbx/fusionpbx](https://github.com/fusionpbx/fusionpbx) (FreeSWITCH-based, multi-tenant)
- **FreePBX Baseline**: [github.com/FreePBX](https://github.com/FreePBX) (Asterisk-based, traditionally single-tenant)
- **TallPBX Baseline**: Core application + 58 first-party modules under `app-modules/` (FreeSWITCH® 1.11, multi-tenant, Laravel 13, Livewire 4)

---

## 1. Executive Parity Summary

| Metric / Dimension | TallPBX | FusionPBX | FreePBX® |
| :--- | :--- | :--- | :--- |
| **Telephony Engine** | **FreeSWITCH 1.11** (`mod_sofia`, `mod_callcenter`) | **FreeSWITCH 1.11** | **Asterisk 20+** (PJSIP, chan_sip) |
| **Core Web Stack** | **Laravel 13, Livewire 4, Tailwind v4** | Custom procedural/OOP PHP (legacy) | BMO (custom procedural PHP framework) |
| **Database Support** | **MariaDB / MySQL** (SQLite in testing) | PostgreSQL / SQLite / MariaDB | MariaDB / MySQL |
| **Configuration Model** | **Dynamic `mod_xml_curl`** (No static XML on disk) | Dynamic `mod_xml_curl` (PHP scripts) | Static `.conf` files written to disk (`#include`) |
| **Multi-Tenancy** | **Native Multi-Tenant** (Isolated contexts, domains, data) | **Native Multi-Tenant** (Domain-based) | **Single-Tenant Core** (Multi-tenant requires commercial PBXact) |
| **User Interface & Layout** | **Dual Layouts**: Collapsible mini-rail sidebar (`w-16` / `w-64`) & horizontal topbar dropdowns with per-user persistence | Fixed top navbar (legacy procedural HTML) | Fixed top navbar (classic FreePBX theme) |
| **Automated Testing** | **1,930 Pest tests + 43 Dusk browser tests** | Minimal / community scripts | Minimal unit tests |
| **Licensing** | **Apache 2.0** (100% open source) | MPL 1.1 (Open source) | GPLv3 (Core) + Commercial closed modules |

| Metric / Feature Category | TallPBX | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Total Analyzed Feature Areas** | **58** | **56** | **52** | High Convergence |
| **Functional / Feature Parity** | **56 (96.6%)** | 56 (100%) | 49 (94.2%) | 🟢 **Core Parity Met** |
| **Architecturally Superior in TallPBX** | **7 modules** (OAuth, Limits, Event Guard, Backups, Local Spooling) | Legacy PHP scripts | Commercial closed modules | 🚀 **Substantial Advantage** |
| **Intentionally Excluded (Security)** | **2 modules** (Web DB client, Web shell) | Exposes `app/database`, `app/exec` | None in core | 🛡️ **Superior Security** |

---

## 2. Domain-by-Domain Parity Breakdown

### 2.1 Extensions, Users & Device Provisioning

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Extensions** | `extensions`, `sip-accounts` | `app/extensions` | `core` (extensions) | 🟢 **Full Parity** |
| **User Management** | `admin` (Users, Groups, Permissions) | `core/users`, `core/groups` | `userman` | 🟢 **Full Parity** |
| **Device Directory** | `devices` (SIP account association) | `app/devices` | `core` (devices) | 🟢 **Full Parity** |
| **Extension Settings** | `extension-settings` (Directory XML overrides) | `app/extension_settings` | `customcontexts` | 🟢 **Full Parity** |
| **Auto-Provisioning** | `provision` (Templates, HTTP/TFTP, CIDR/Basic auth) | `app/provision` | `endpoint` (commercial) / OSS PBX End Point | 🟢 **Functional Parity** |
| **Hot Desking** | `hot-desking` (`*11` login, `*12` logout, dynamic bridge) | `app/hot_desking` (Feature code login/logout) | `hotelstyle` / commercial / Device & User mode | 🟢 **Full Parity** |

---

### 2.2 SIP Connectivity & Call Routing

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **SIP Gateways / Trunks** | `gateways`, `sip-trunks` | `app/gateways` | `core` (Trunks) | 🟢 **Full Parity** |
| **SIP Profiles (Sofia)** | `sip-profiles` (`internal`, `external`, custom) | `app/sip_profiles` | `sipsettings` | 🟢 **Full Parity** |
| **Inbound Routes (DID)** | `inbound-routes` (DID regex, destination targets) | `app/dialplan_inbound` | `core` (Inbound Routes) | 🟢 **Full Parity** |
| **Outbound Routes** | `outbound-routes` (Prefix/length patterns, gateways) | `app/dialplan_outbound` | `core` (Outbound Routes) | 🟢 **Full Parity** |
| **Number Translations** | `number-translations` (Inbound/Outbound digit rewrites) | Dialplan regex actions | `core` (Dial Rules) | 🟢 **Full Parity** |
| **Bridges & Destinations**| `bridges`, `destinations` | `app/bridges`, `app/destinations` | `customappsreg`, `miscapps` | 🟢 **Full Parity** |
| **Access Controls (ACL)** | `access-controls` (Dynamic `acl.conf.xml` generation) | `app/access_controls` | `firewall` (Fail2ban/iptables) | 🟢 **Full Parity** |

---

### 2.3 PBX Call Handling & Feature Suite

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Ring Groups** | `ring-groups` (simultaneous, sequence, enterprise) | `app/ring_groups` | `ringgroups` | 🟢 **Full Parity** |
| **IVR / Auto Attendant** | `ivr-menus` (greetings, direct dial, timeouts) | `app/ivr_menus` | `ivr` | 🟢 **Full Parity** |
| **Voicemail** | `voicemails`, `voicemail-messages` | `app/voicemails`, `app/voicemail_messages` | `voicemail` | 🟢 **Full Parity** |
| **Conferences** | `conferences`, `conference-centers` (PINs, member limits) | `app/conferences`, `app/conference_centers` | `conferences` | 🟢 **Full Parity** |
| **Call Centers / Queues** | `call-centers` (`mod_callcenter`, strategies, tiers) | `app/call_centers` | `queues`, `queuemetrics` | 🟢 **Full Parity** |
| **Time Conditions** | `time-conditions` (schedule, timezone, match/no-match) | `app/time_conditions` | `timeconditions` | 🟢 **Full Parity** |
| **Call Flows (Day/Night)**| `call-flows` (manual toggle via star code) | `app/call_flows` | `daynight` | 🟢 **Full Parity** |
| **Call Forwarding** | `call-forwards` (unconditional, busy, no-answer) | `app/call_forward` | `callforward` | 🟢 **Full Parity** |
| **Follow Me** | `follow-me` (sequential multi-destination ringing) | `app/follow_me` | `findmefollow` | 🟢 **Full Parity** |
| **Call Blocking** | `call-blocks` (caller ID pattern blocking) | `app/call_block` | `blacklist` | 🟢 **Full Parity** |
| **PIN Numbers** | `pin-numbers` (account codes for outbound routes) | `app/pin_numbers` | `pinsets` | 🟢 **Full Parity** |
| **Feature Codes** | `feature-codes` (`*97`, `*732`, customizable star codes) | `app/dialplans` | `featurecodeadmin` | 🟢 **Full Parity** |

---

### 2.4 Media Management, Recordings & Messaging

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Custom Audio** | `recordings` (prompts, greetings, announcements) | `app/recordings` | `recordings` | 🟢 **Full Parity** |
| **Music on Hold (MOH)** | `music-on-hold` (dynamic `local_stream.conf`) | `app/music_on_hold` | `music` | 🟢 **Full Parity** |
| **Call Recordings** | `call-recordings`, `file-stores` | `app/call_recordings` | `callrecording` | 🚀 **Superior in TallPBX** (Local spool + verified remote archive transfer with checksum verification) |
| **Fax Services** | `fax` (T.38 / Spandsp, PDF/TIFF, inbox, send) | `app/fax` | `fax`, `faxpro` (commercial) | 🟢 **Full Parity** |
| **Call Broadcast** | `call-broadcast` (loopback originate, recipient lists) | `app/call_broadcast` | `broadcast` (commercial) | 🟢 **Full Parity** |
| **Click to Call** | `click-to-call` (web originate) | `app/click_to_call` | `phonebook` / AMI click-to-call | 🟢 **Full Parity** |
| **Speech & Transcription**| `speech` (TTS), `transcribe` (STT) | `app/azure` | `tts` / commercial add-ons | 🟢 **Full Parity** |
| **AI Receptionist** | `robo-receptionist` *(planned architecture)* | *None* | *None* | 🚀 **Future Advantage** |

---

### 2.5 Real-Time Operations & Monitoring

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Operator Panel** | `operator-panel` (real-time extension statuses, originate) | `app/operator_panel` | `fop2` (third-party/commercial) | 🟢 **Full Parity** |
| **Active Calls** | `active-calls` (live calls, hangup, transfer) | `app/calls_active` | `asteriskinfo` | 🟢 **Full Parity** |
| **Active Conferences** | `active-conferences` (live participants, mute, kick) | `app/conferences_active` | `conferences` live view | 🟢 **Full Parity** |
| **Active Call Center** | `call-center-active` (agent status, pause/resume, logout)| `app/call_center_active` | `queues` FOP2 view | 🟢 **Full Parity** |
| **SIP Status / Regs** | `sip-status`, `registrations` (real-time registrations) | `app/sip_status`, `app/registrations` | `core` registrations / `sipsettings` | 🟢 **Full Parity** |
| **Call Detail Records** | `xml-cdr` (billsec, caller, destination, hangup cause) | `app/xml_cdr` | `cdr`, `cel` | 🟢 **Full Parity** |
| **Event Rate Limiting** | `event-guard` (FreeSWITCH event flood protection) | *None* | `firewall` (Fail2ban IP only) | 🚀 **Superior in TallPBX** |

---

### 2.6 System Administration & Security

| Feature / Capability | TallPBX Module | FusionPBX Equivalent | FreePBX Equivalent | Parity Assessment |
| :--- | :--- | :--- | :--- | :---: |
| **Backups & Restores** | `backups` (GPG encrypted, SQL, media, config, restore helper) | `app/backup` | `backup` | 🟢 **Full Parity** |
| **SMTP Delivery** | `email-connector` (standard username/password SMTP & OAuth 2.0 via Google, Microsoft, or custom) | Basic PHP mailer / settings | Postfix / `sysadmin` (commercial) | 🚀 **Superior in TallPBX** |
| **Software Updates** | `admin` (Git update flow with preflight & asset rollback) | `app/upgrade` (Git pull script) | `moduleadmin` | 🟢 **Full Parity** |
| **Panel Layout Modes** | `admin` (collapsible mini "icon rail" sidebar + switchable horizontal topbar with per-user persistence) | Fixed top navbar only | Fixed top navbar only | 🚀 **Superior in TallPBX** |
| **Tenant Limits** | `tenant-limits` (soft & hard resource capping per tenant) | Dialplan limits only | *Not applicable* | 🚀 **Superior in TallPBX** |
| **Dangerous Tools** | *Intentionally Omitted* | `app/database` (raw SQL web runner), `app/exec` (web shell) | *None in core* | 🛡️ **Intentionally excluded for security** |

---

## 3. Key Advantages of TallPBX

1. **Modern Codebase & Maintainability**:
   - Built on **Laravel 13**, **Livewire 4**, and **Tailwind CSS v4** with clean architectural boundaries (`App\Support\ModuleServiceProvider`, `BaseListComponent`, `BaseEditComponent`).
   - FusionPBX and FreePBX are 15–20 year-old procedural PHP codebases with deeply nested global state, direct SQL string concatenation, and minimal test coverage.
2. **Quality & Test Automation**:
   - **1,930 automated Pest tests** and **43 Dusk browser tests** run in CI and locally. Any regression in tenant isolation, routing, or XML generation is caught immediately before deployment.
3. **Multi-Tenant Security Model**:
   - Multi-tenant defense-in-depth:
     - `TenantMutationGuard` enforces data boundary checks on model lifecycle events.
     - `EnforcePanelLivewireActionPermissions` verifies permissions on all `/livewire/update` calls.
     - `PrimaryDatabaseSafety` prevents accidental `migrate:fresh` or destructive queries on production databases.
4. **Local-First Media Storage with Verified Remote Archival**:
   - TallPBX keeps audio locally for immediate playback, spooling recordings and faxes until a verified background transfer matches byte count and SHA-256 checksum on remote storage (S3/off-server).
5. **Comprehensive Email Authentication (Standard SMTP & OAuth 2.0)**:
   - FreePBX and FusionPBX rely on basic SMTP username/password authentication. TallPBX supports standard username/password SMTP authentication (including app passwords) as well as native token-based OAuth 2.0 support for Google (Gmail), Microsoft 365, and custom OAuth 2.0 providers without third-party dependencies.
6. **Modern UI with Dual Layout Modes & Collapsible Navigation**:
   - TallPBX gives operators flexible control over their workspace. Administrators can collapse the vertical sidebar into an icon-only mini rail (`64px`) to maximize table width for dense views (CDRs, routing rules, active calls), or switch to a horizontal top-bar header with standard dropdown menus for users accustomed to legacy PBX navigation. Layout preferences and sidebar sizes are saved per-user and rendered with zero layout shift.
