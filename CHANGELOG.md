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
  - Bounded root execution helper (`/usr/local/bin/tallpbx-security`) for safe, atomic kernel firewall updates.
  - Unified admin panel route (`/panel/security`) and navigation under PBX → Advanced with `security.view` and `security.edit` permissions.
  - Host security database schema and Eloquent models (`SecurityRule`, `SecurityService`, `SecurityIpList`, `SecuritySetting`, `SecurityBan`, `SecurityAuditLog`).
  - Standard PBX Port Catalog seeder (`SecurityServiceSeeder`) pre-populating SIP (5060/5061/5080), RTP (16384-32768), Web Admin (80/443), SSH (22), ESL (8021), Reverb (8080), and WebRTC (7443).
  - In-process web authentication failure listener (`LogFailedLoginListener`) capturing failed logins in real time with Redis sliding-window counters (`SecurityIncidentService`).
  - Real-time FreeSWITCH SIP authentication failure listener (`LogFailedSipAuthListener`) and dedicated `SofiaFailedAuth` event dispatching via ESL for automated SIP attack detection and banning.
  - Declarative event listener registration support in the base `ModuleServiceProvider`.

### Upgrade Notes
- Requires running database migrations: `php artisan migrate`.

## [1.0.0] - 2026-09-17

### Added
- Initial production release of TallPBX.
- Unified single-panel architecture for system administrators and tenant users with permission-gated access.
- Core PBX management: Extensions, SIP Trunks, Inbound & Outbound Routes, Ring Groups, IVR Menus, Call Centers, Time Conditions, Dialplans, and Feature Codes.
- Dynamic FreeSWITCH telephony integration powered by `mod_xml_curl` through the application XML Handler API.
- Livewire 4, Alpine 5, and Tailwind CSS v4 / DaisyUI 5 reactive interface.
- Multi-tenant isolation, automated device provisioning, and automated installer with dependency and permission management.
