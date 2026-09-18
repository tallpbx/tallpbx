# Changelog

All notable changes to TallPBX will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - Unreleased

### Added
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
