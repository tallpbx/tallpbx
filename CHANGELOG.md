# Changelog

All notable changes to TallPBX will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **SQLite-Compatible Security ICMP Migration**: The Security module's ICMP support migration (`2026_09_18_000008`) used a MariaDB-only `ALTER TABLE ... MODIFY COLUMN` statement, which stopped database migrations — and therefore the entire automated test suite — from running on SQLite. The migration now uses Laravel's portable schema builder, producing an identical column definition on MariaDB while allowing SQLite-backed environments and automated tests to migrate cleanly. No action is required for existing MariaDB installations, which have already applied this migration.
- **Standard PBX Service Catalog Test Expectations**: Updated the security catalog test to expect the 8 standard services seeded since 1.1.0; the `ICMP Ping Diagnostics` service added by the ICMP feature had not been reflected in the expected count.
- **Test Suite Could Modify the Live Host Firewall**: Running the Security module's automated tests on a host where the bounded helper is installed (or as the `root` user) could execute real privileged firewall commands — rewriting `/etc/tallpbx` and loading test fixtures into the live kernel ruleset. The tests now use a stubbed executor and an isolated ruleset directory, and `SecurityExecutor` refuses to run privileged operations during automated test runs, so running the test suite can never alter host firewall state again.

### Security
- **Authenticated Real-Time Alert Channel**: Security Command Center alerts (intruder activity, bans, and firewall changes) are now broadcast on a private, permission-checked WebSocket channel. Only signed-in panel users holding the `security.view` permission can subscribe over Laravel Echo/Reverb; unauthenticated clients are rejected by the channel authorization endpoint. No action is required for existing installations.

## [1.1.0] - 2026-09-18

### Changed
- **Collapsible System & Pre-Filters Pipeline Section**:
  - Compacted System Base Invariants, Stage 1 Drops, and Stage 2 Whitelist into a single interactive header row (`SYSTEM + STAGES 1 & 2: BUILT-IN RULES & PRE-FILTERS (6 Rules Active)`), decluttering the **Firewall Rules & Port Access** table and reducing vertical height while preserving full visibility on demand.
  - Replaced technical "Kernel Invariant" jargon with clear, plain-English "Built-in" action labels for non-editable rules (Loopback, Connection Tracking, and Invalid Packet Defense).
  - Streamlined Stage 3 into **Standard Services**, eliminating repetitive and inaccurate "Core PBX" and "System" badges next to individual service names.
  - Clicking the header expands/collapses the full 6 sequential rules in exact evaluation order: unconditional loopback (`iif "lo"`), permanent blacklist IP drops (`@blacklist_ips`), active banned intruder drops (`@banned_ips`), stateful connection tracking (`ct state established,related`), invalid packet defense (`ct state invalid`), and trusted whitelist bypass (`@whitelist_ips`).
- **ICMP Ping Diagnostics Configurable Core Service**:
  - Moved ICMP Ping Diagnostics from static pre-filters into **Stage 3 Core Services** as the first rule, matching its position in sequential rule evaluation.
  - Added full administration controls for ICMP: administrators can toggle echo-request ping diagnostics on/off, restrict echo-requests to specific source IP networks or CIDRs (e.g. monitoring servers), and configure rate-limiting thresholds (custom packet/sec rate limit and burst allowance or completely unlimited).
  - Added 1-click "Restore Factory Defaults" for ICMP diagnostics (defaults: enabled, unrestricted source IP, 5 packets/sec rate limit with burst of 5).
  - Protected critical IPv6 connectivity: echo-request rules control ICMP/ICMPv6 ping, while IPv6 Neighbor Discovery (ND) and Router Advertisements (RA) remain unconditionally accepted at the kernel level so IPv6 routing and layer-2 reachability are never severed.
  - Added database migration `2026_09_18_000008_update_security_services_for_icmp_support.php` extending `protocol` column to `VARCHAR(20)` and adding `rate_limit` and `burst` columns.
- **Kernel Pipeline Invariants & Loopback Step 1 Reordering**:
  - Reordered the Linux `nftables` inbound pipeline in `SecurityConfigGenerator` so unconditional loopback access (`iif "lo" accept`) evaluates at Step 1 before blacklists and dynamic bans, guaranteeing uninterrupted internal localhost IPC between PHP-FPM, MariaDB, Redis, FreeSWITCH ESL, and Laravel Reverb WebSockets.
  - Implemented burstable ICMP rate limiting (`limit rate 5/second burst 5 packets accept` for IPv4 and IPv6) with full support for IPv6 Neighbor Discovery and Router Solicitation, safeguarding against ping floods while preserving essential network diagnostics.
- **Base System Invariants Displayed in Security Command Center**:
  - Displayed living Base System Invariants directly in the **Firewall Rules & Port Access** table (`security-manager.blade.php`), exposing loopback interface access, stateful connection tracking (`ct state established,related`), invalid packet defense (`ct state invalid`), and ICMP diagnostics with rate limiting and dual-stack IPv4/IPv6 indicators.
  - Added full multilingual translations across English, Spanish, and French (`lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`) for all invariant tooltips, badges, and rule descriptions.
- **Documentation & UI Tour Showcase**:
  - Added the Security Command Center high-resolution screencapture and architecture overview to `docs/security-architecture.md` and `docs/ui-tour.md`.
  - Updated the inbound ingress Mermaid flowcharts and sequential rule documentation to reflect the 9-step kernel filtering pipeline.
- **Blacklist IP Precedence Clarification & Multilingual Translations**:
  - Updated the Blacklist section description and advisory helper across English, Spanish, and French (`lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`) to explicitly state that Blacklist drop rules take precedence over general traffic and are dropped before the whitelist.
  - Fully synchronized all 50+ new Security Center pipeline, table, and service keys across `es` and `fr` translation catalogs.
- **Security Menu Placement Before PBX**:
  - Positioned the **Security** navigation link directly before the **PBX** section in the main sidebar menu (order 39), grouping core server administration, monitoring, and security together before telephony domains.
- **Security Center Table Typography & Text Size Standardization**:
  - Enlarged table text size across all Security Command Center tables (Blacklist, Blocked Attackers, Whitelist, and Firewall Rules) to match the standard sizing and typography of Users, Administrators, and other core panels.
  - Replaced compact `table-xs` and `table-sm` sizing with standard table sizing (`0.875rem` / 14px body text, 14px monospace ports/protocols/IPs, and `badge-sm` tags), improving legibility and visual consistency across the application.
- **Protection Settings Relocated to Blocked Attackers Header**:
  - Moved the **Attack Protection Settings** button from the top page header directly into the **Blocked Attackers** section header alongside "Block IP Manually", grouping intrusion detection thresholds (`max_retry`, `find_time`, `ban_time`) and attack vector toggles (SIP, Web, SSH) directly with the live threat management workbench.
- **Pipeline-Aligned 3-Deck IP Architecture & Standardized Blacklist/Whitelist Terminology**:
  - Replaced the tab-switched IP management deck with three sequential full-width workbench cards arranged in the exact order traffic is evaluated in the Linux `nftables` packet filtering pipeline:
    1. **Blacklist IPs (Always Dropped)**: Stage 1 permanent kernel drop rules with a Split-Panel layout (Quick-Add Form and Stage 1 advisory helper on left, search filter and scrollable table on right).
    2. **Blocked Attackers**: Stage 1 dynamic fail2ban drop rules with active threat counters, attack vector badges (`SIP`, `Web`, `SSH`), expiry countdowns, and manual ban creation.
    3. **Whitelist IPs (Always Allowed)**: Stage 2 kernel bypass rules with a Split-Panel layout (Quick-Add Form, 1-click admin IP self-protection, and Stage 2 advisory helper on left, search filter and scrollable table on right).
  - Standardized terminology across the UI and translation keys (`lang/en/admin.php`) to strictly use consistent naming everywhere (`Blacklist IPs (Always Dropped)`, `Blocked Attackers`, `Whitelist IPs (Always Allowed)`, `Add to Blacklist`, `Add to Whitelist`, `Blacklist IPs @blacklist_ips`, `Blocked Attackers @banned_ips`, `Whitelist IPs @whitelist_ips`).
  - Added smooth anchor navigation (`↑ View Blacklist`, `↑ View Blocked Attackers`, `↑ View Whitelist`) from the Unified Firewall Rules table directly to the corresponding cards.
  - Eliminated horizontal dead space with a responsive flex layout (`w-full lg:w-80 lg:shrink-0` for form, `w-full lg:flex-1` for table).
  - Removed redundant top **Packet Filtering Pipeline Order** banner since the living firewall table and stacked cards already represent the sequential stages directly.

### Added
- **Unified Firewall Rules Table & Editable Core PBX Services**:
  - Transformed the **Firewall Rules & Port Access** table into a single, unified view representing the complete 5-stage packet filtering pipeline:
    - **Stage 1 & 2 Summary Rows**: Permanent Blacklist (`@blacklist_ips`), Active Intrusion Bans (`@banned_ips`), and Trusted Whitelist (`@whitelist_ips`) with live counts and 1-click management shortcuts (`Manage Blacklist`, `Manage Whitelist`).
    - **Stage 3 Core PBX Services**: Fully integrated all 7 telephony and management services (SIP Signaling, RTP Media, Web Admin, SSH, FreeSWITCH ESL, Reverb WebSockets, and WebRTC) into the table with individual status toggles and customization controls.
    - **Stage 4 Custom Sequential Rules**: Administrator-defined sequential rules with priority Up/Down reordering and quick creation controls.
    - **Stage 5 Default Inbound Fallback Policy**: Clean fallback summary row showing default action (`Drop` or `Accept`) with direct configuration shortcut.
  - **Core PBX Service Customization & Zero-Lockout Safety**:
    - Added customization for core PBX services, allowing administrators to modify port ranges, network protocols (`TCP`, `UDP`, `Both`), and apply source IP / CIDR network restrictions (e.g. restricting SSH or Web Admin access to an administrative VPN).
    - Safety warning modal with plain-English guidance on preventing call disruption.
    - Zero-lockout protection (`LockoutGuardService`) preventing administrators from restricting or disabling Web Admin or SSH access away from their active connection IP.
    - 1-click "Restore Factory Defaults" action to instantly revert core PBX services to factory ports and unrestricted access.
  - Database schema update: added `enabled` and `source_ip` columns to `security_services` via migration `2026_09_18_000007_add_enabled_and_source_ip_to_security_services_table.php`.
- **Security Module**: Integrated native host security and attack protection command center (`app-modules/security`).
  - Single-screen management for host firewall rules, standard PBX port access, trusted and blocked IP lists, and real-time intruder monitoring.
  - Native Linux `nftables` packet filtering with zero-lockout protection ensuring active administrator sessions are never blocked.
  - High-performance in-process authentication failure tracking with automatic IP banning across SIP, Web, and SSH access vectors.
  - Bounded root execution helper (`/usr/local/sbin/tallpbx-security`) for safe, atomic kernel firewall updates.
  - Unified admin panel route (`/panel/security`) and dedicated top-level navigation on the main menu, protected by `security.view` and `security.edit` permissions.
  - Host security database schema and Eloquent models (`SecurityRule`, `SecurityService`, `SecurityIpList`, `SecuritySetting`, `SecurityBan`, `SecurityAuditLog`).
  - Standard PBX Port Catalog seeder (`SecurityServiceSeeder`) pre-populating SIP (5060/5061/5080), RTP (16384-32768), Web Admin (80/443), SSH (22), ESL (8021), Reverb (8080), and WebRTC (7443).
  - In-process web authentication failure listener (`LogFailedLoginListener`) capturing failed logins in real time with Redis sliding-window counters (`SecurityIncidentService`).
  - Real-time FreeSWITCH SIP authentication failure listener (`LogFailedSipAuthListener`) and dedicated `SofiaFailedAuth` event dispatching via ESL for automated SIP attack detection and banning.
  - Host IP ban management service (`SecurityBanService`) and executor (`SecurityExecutor`) coordinating active/historical bans in MariaDB and Linux kernel `nftables` sets.
  - Enterprise security audit logging (`SecurityAuditLog`) automatically recording ban and unban operations with administrator attribution.
  - Bounded root execution helper (`scripts/resources/tallpbx-security`) and sudoers configuration (`scripts/resources/tallpbx-security.sudoers`) enforcing strict parameter regex validation and atomic kernel ruleset compilation.
  - Native Linux `nftables` configuration compiler (`SecurityConfigGenerator`) generating atomic rulesets, kernel interval sets for IPs and CIDRs, and dynamic timeout-backed bans.
  - Zero-lockout protection service (`LockoutGuardService`) evaluating administrator session reachability before applying restrictive firewall policies, complete with 1-click rescue whitelisting.
  - Artisan management CLI commands: `security:apply` (atomic firewall ruleset application with lockout check), `security:status` (active engine and ban overview), and `security:unban <ip>` (instant cross-layer unbanning).
  - Declarative event listener and console command registration support (`listeners()`, `consoleCommands()`) in the base `ModuleServiceProvider`.
  - **Unified Single-Screen Security Command Center (`SecurityManager`)**: Complete interactive administrative interface (`/panel/security`) unifying all 5 security zones on a single responsive screen:
    - Real-time status cards for Kernel Firewall, Attack Protection, Active Banned Attackers, and Administrator Connection with dynamic Lockout Guard.
    - Whitelist and Blacklist IP address management supporting individual IPv4 addresses and CIDR subnets with live search, instant deletion, and validation.
    - Active Threat Monitor displaying banned attacker IPs, attack vectors (SIP, Web, SSH), failure attempt counts, expiration countdowns, 1-click manual unbanning, permanent blocking, and safe-whitelisting.
    - Sequential Firewall Rule builder with live Up/Down priority reordering, enable/disable toggles, PBX Port Catalog quick-add dropdown, custom rule creation modal, and pulsing `[ Save & Apply Changes ]` action.
    - Attack Protection configuration slide-over drawer for tuning failure thresholds (`max_retry`, `find_time`, `ban_time`) and toggling protection per vector (SIP, Web, SSH).
  - **Installer Integration (`scripts/resources/security.sh`)**: Automated installer step installing `nftables`, establishing the `/etc/tallpbx` configuration directory (mode 2775 `root:www-data`), installing `/usr/local/sbin/tallpbx-security` (mode 0750 `root:www-data`), configuring sudoers drop-in `/etc/sudoers.d/tallpbx-security` (mode 0440), registering `SecurityServiceSeeder` in `DatabaseSeeder`, and generating the initial baseline firewall ruleset.
  - **Browser & Smoke Tests**: Dusk browser test coverage and Pest feature test suite (`SecurityManagerLivewireTest.php`) validating Livewire component reactivity, lockout warning display, tab switching, rule reordering, port catalog rule addition, and threat table actions.
- **User & Administrator Security Documentation**: Comprehensive guides and parity analysis added to `README.md`, `INSTALL.md`, and `docs/parity-comparison.md` detailing the native Linux kernel `nftables` firewall, multi-vector intrusion prevention across SIP, Web, and SSH, zero-lockout protection, and the hardened Bounded Sudoers host command execution architecture.
- **Security Architecture & Packet Flow Guide**: Added [docs/security-architecture.md](docs/security-architecture.md) featuring visual Mermaid flowcharts detailing inbound ingress packet traversal, outbound egress routing, real-time WebSocket attack mitigation, sequential top-to-bottom rule ordering, and two-tier reboot persistence.

### Changed
- **Security Command Center Real-Time Responsiveness**: Removed the manual "Refresh Status" button and periodic polling in favor of real-time event-driven push updates (`#[On('echo:security.alerts,...')]` and `#[On('refresh-security')]`), standardizing on the project-wide WebSocket/Echo pattern established by the monitoring dashboard.
- **Instant Auto-Application (Zero-Staging Workflow)**: Eliminated manual change staging and the requirement to click a separate "Save & Apply Changes" button. Firewall rule toggling, reordering, IP whitelisting/blacklisting, service catalog additions, custom rule creations, and security settings updates now atomically and automatically compile and apply directly to the Linux kernel (`nftables`) with automated preflight lockout verification.
- **Access Control Lists (ACL)**: Renamed "Access Controls" to "Access Control Lists" across English, Spanish, and French translations for consistency with FreeSWITCH naming, and added plain-language explanatory tooltips differentiating internal phone system ACLs from the OS-level kernel firewall.
- **Rate Limits (formerly Event Guard)**: Renamed "Event Guard" to "Rate Limits" across the navigation menu, page headers, descriptions, and translations (English, Spanish, French) to align with standard FreeSWITCH telephony terminology and eliminate confusion with legacy FusionPBX naming. Updated tooltips to clearly differentiate telephony event rate limiting from the OS-level packet firewall.

### Fixed
- **Tailwind v4 Modular View Scanning**: Added `@source "../../app-modules/**/*.blade.php"` to `resources/css/app.css` so utility classes across all modular Blade views are scanned and compiled into production CSS bundles.
- **Empty Threat Monitor Table Sizing**: Adjusted the "No active threats" empty state icon in the Security Command Center from an oversized, unconstrained element to a compact `w-6 h-6` (24px) bounded icon with reduced padding (`py-6`), eliminating unintended vertical scrolling.

### Upgrade Notes
- Requires running database migrations: `php artisan migrate`.
- Requires installing Linux package: `apt install nftables`.
- Requires installing bounded security helper and sudoers rule (`scripts/resources/security.sh`).

## [1.0.0] - 2026-09-17

### Added
- Initial production release of TallPBX.
- Unified single-panel architecture for system administrators and tenant users with permission-gated access.
- Core PBX management: Extensions, SIP Trunks, Inbound & Outbound Routes, Ring Groups, IVR Menus, Call Centers, Time Conditions, Dialplans, and Feature Codes.
- Dynamic FreeSWITCH telephony integration powered by `mod_xml_curl` through the application XML Handler API.
- Livewire 4, Alpine 5, and Tailwind CSS v4 / DaisyUI 5 reactive interface.
- Multi-tenant isolation, automated device provisioning, and automated installer with dependency and permission management.
