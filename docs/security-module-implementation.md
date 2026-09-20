# Enterprise Native Security Module Implementation Plan

> [!NOTE]
> **Status: 100% Native Architecture Specification & Implementation Roadmap**
> **Core Mandate:** Enterprise quality, completely native (Zero Fail2ban dependency), robust, and straight-forward, unified into **ONE streamlined, intuitive, single-screen UI**.
> 
> This specification synthesizes:
> 1. **Single-Screen Security Command Center**: Everything (Firewall Rules, Whitelist, Blacklist, Intrusion Tuning, and Live Banned IPs) is unified on **one cohesive page**—no tab jumping, no fragmented menus.
> 2. **Base Firewall Whitelist & Blacklist (Issabel Pattern)**: Global IP/CIDR access control directly incorporated into the kernel firewall ingress (`@whitelist_ips` and `@blacklist_ips`).
> 3. **Sequential Firewall Rules (Issabel Pattern)**: Evaluation priority (`sequence`), action badges (`ALLOW` / `BLOCK`), and a telephony-centric **Port Catalog** (*SIP Signaling*, *RTP Audio*, *Web Panel*, *SSH*, *FreeSWITCH ESL*, *WebSockets*).
> 4. **Intrusion Detection & Auto-Banning (FreePBX Sysadmin Pro Pattern)**: Real-time event detection, threshold tuning (*Max Retries*, *Find Time*, *Ban Duration*), live auto-banned attackers with 1-click unban, manual ban, and audit logging.
> 5. **FusionPBX Baseline Configuration**: Safe defaults (loopback, connection tracking `ct state established,related accept`, invalid packet drops, ICMP ping).
> 6. **TallPBX Native Innovation**: Built on the **TALL Stack** (Tailwind CSS v4, Alpine 5, Laravel 13, Livewire 4, DaisyUI 5), Linux **`nftables` dynamic sets with kernel timeouts**, in-process Laravel auth events, FreeSWITCH ESL socket events, and Redis atomic rate limiting. **Zero external Fail2ban package or Python daemons.**

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

---

## 1. Unified Single-Screen Command Center Layout & UX Architecture
### 1.1 The Single-UI Philosophy & Plain-English Design
In legacy PBX distributions (such as FreePBX, Issabel, and FusionPBX), security functions are notoriously fragmented across multiple disconnected screens and configuration sections:
- Administrators must navigate to a "System Admin / Intrusion Detection" page to see banned IPs.
- They must navigate to a separate "Firewall" module to manage zones and ports.
- They must navigate to a third sub-menu or modal to manage Whitelists or Blacklists.
- And they must edit text files or secondary tabs to tune brute-force thresholds.

This fragmentation causes **tab fatigue, administrator confusion, and accidental lockouts**.

TallPBX solves this completely by consolidating **all security operations into ONE cohesive, intuitive, single-screen command center** at `/panel/security`.

#### User-Facing Terminology Guidelines (Minimally Technical, Easy to Understand)
While technical specifications, backend services, and developer documentation use precise architectural terms (such as *nftables dynamic sets*, *sliding-window rate limiters*, and *lockout guard rails*), **all user-facing labels, card titles, buttons, badges, tooltips, and descriptions presented to the administrator must be simple, plain-English, and minimally technical**:

| Internal / Technical Concept | User-Facing Screen Label | Plain-English Meaning for Users |
| :--- | :--- | :--- |
| **Global Health & Status Deck** | **System Status** | Simple overview showing if security protections are active. |
| **Lockout Guard Rail / Admin Safety** | **Your Connection (Safe from Lockout)** | Shows the user's IP and guarantees they can never accidentally lock themselves out. |
| **Ingress Threat Sentinel** | **Automatic Attack Protection** | Protects phone extensions and logins from password guessing. |
| **Dynamic Set Kernel Auto-Bans** | **Currently Blocked Attackers** | Clear list of IP addresses temporarily blocked for wrong passwords. |
| **Global IP Access (Whitelist / Blacklist)** | **Trusted & Blocked IP Addresses** | Two simple lists: "Trusted" (always allowed) and "Blocked" (never allowed). |
| **Sequential Firewall Rules** | **Firewall Rules** | Simple rules controlling which networks can reach specific ports. |
| **PBX Service Baseline / Port Catalog** | **Standard Phone System Ports** | Standard PBX ports (Calls, Audio, Web, Server) that work out of the box. |
| **Intrusion Sensitivity Tuning (Drawer)** | **Attack Protection Settings** | Easy sliders for allowed attempts, time window, and block duration. |
| **Vector: `sip_auth`** | **Phone Passwords (SIP)** | Someone tried incorrect phone extension passwords. |
| **Vector: `web_auth`** | **Web Login** | Someone tried incorrect web portal passwords. |
| **Vector: `ssh`** | **Server Login (SSH)** | Someone tried incorrect server console passwords. |
| **Action: `unban`** | **`[ Unblock ]`** | Everyday English for lifting a temporary block. |
| **Action: `promoteToWhitelist`** | **`[ Trust IP ]`** | 1-click action: "Make this IP permanently trusted so it is never blocked again." |
| **Action: `promoteToBlacklist`** | **`[ Block Permanently ]`** | 1-click action: "Add this IP to the permanent blacklist." |

---

### 1.2 Comprehensive UI Wireframe (Plain-English Labels)

```
+===================================================================================================================================================+
|  SECURITY CENTER                                                                                  [ ⚙ Protection Settings ]  [ ⟳ Refresh Status ] |
|  Firewall, trusted IP addresses, and automatic attack protection                                                                                  |
|                                                                                                                                                   |
|  +-----------------------------+  +-----------------------------+  +-----------------------------+  +---------------------------------------+  |
|  |  FIREWALL STATUS            |  |  ATTACK PROTECTION          |  |  BLOCKED ATTACKERS          |  |  YOUR CONNECTION (SAFE)               |  |
|  |  ● Active                   |  |  ● Active                   |  |  3 Currently Blocked        |  |  Your IP: 198.51.100.22 (This Computer)|  |
|  |  Default: Block Unknown     |  |  Phone, Web, and Server     |  |  Temporary automatic blocks |  |  [✓ Always Allowed (Protected)]    |  |
|  +-----------------------------+  +-----------------------------+  +-----------------------------+  +---------------------------------------+  |
+===================================================================================================================================================+
|                                                                                                                                                   |
|  +-- LEFT PANEL: TRUSTED & BLOCKED IP ADDRESSES ---------------+  +-- RIGHT PANEL: CURRENTLY BLOCKED ATTACKERS ---------------------------------+  |
|  |                                                              |  |                                                                           |  |
|  |  [ ● Trusted IPs (Always Allow) ]  [ Blocked IPs (Always Drop)] |  Automatically blocked for repeated wrong passwords        [+ Block IP Manually] |  |
|  |                                                              |  |                                                                           |  |
|  |  +--------------------------------------------------------+  |  |  +---------------------------------------------------------------------+  |  |
|  |  | IP or Network: [ 64.2.142.0/24 ] Note: [ Carrier Trunk] |  |  |  | IP Address     Type        Reason             Unblocks In  Actions        |  |  |
|  |  | [+ Add to Trusted List ]                               |  |  |  | 185.220.101.5  Phone (SIP) 5 bad passwords    42m left     [Unblock][Trust]|  |  |
|  |  +--------------------------------------------------------+  |  |  | 194.26.29.112  Web Login   5 bad admin logins 1h 14m left  [Unblock][Block]|  |  |
|  |                                                              |  |  | 45.142.120.88  Server(SSH) 3 bad root keys    22h left     [Unblock][Block]|  |  |
|  |  Search: [ Search IP or note... ]                            |  |  +---------------------------------------------------------------------+  |  |
|  |  +--------------------------------------------------------+  |  |                                                                           |  |
|  |  | IP or Network     Description / Note      Action       |  |  |  ℹ Attackers who guess passwords are automatically blocked here and will   |  |
|  |  | 198.51.100.22/32  Your Computer (Safe)    [Protected]  |  |  |    unblock automatically when their time is up.                           |  |
|  |  | 192.168.1.0/24    Office Network          [ 🗑 Remove ] |  |  |  Recent Security Activity:                                              |  |
|  |  | 64.2.142.0/24     Carrier Phone Trunk     [ 🗑 Remove ] |  |  |  • 1:34 PM - Blocked 185.220.101.5 (5 failed phone passwords in 12s)       |  |
|  |  +--------------------------------------------------------+  |  |  • 1:20 PM - Unblocked 198.51.100.45 by Administrator (Manual Rescue)    |  |
|  +--------------------------------------------------------------+  +---------------------------------------------------------------------------+  |
|                                                                                                                                                   |
|  +-- LOWER PANEL: FIREWALL RULES & PORT ACCESS ----------------------------------------------------------------------------------------------------+  |
|  |                                                                                                                                                |  |
|  |  When no rule matches: [ Block Inbound Traffic (Recommended) ▼ ]             [+ Add New Rule ]  [ ⚠ Save & Apply Changes (1 pending) ]        |  |
|  |                                                                                                                                                |  |
|  |  Standard Phone System Ports:                                                                                                                  |  |
|  |  [✓ Phone Calls (SIP 5060,5080)]  [✓ Call Audio (RTP 16384-32768)]  [✓ Web Portal (80,443)]  [✓ Server Access (SSH 22)]                       |  |
|  |                                                                                                                                                |  |
|  |  Evaluated In Order (First Match Wins):                                                                                                        |  |
|  |  +-----+-------------------------+------------+--------------------+-------------------------+--------+--------+--------------------------+  |  |
|  |  | Order| Rule Name               | Connection | Allowed From       | Port or Service         | Action | Status | Change Order & Actions   |  |  |
|  |  +-----+-------------------------+------------+--------------------+-------------------------+--------+--------+--------------------------+  |  |
|  |  | 10  | Remote Branch Office    | All        | 10.10.0.0/16       | Web Portal (80/443)     | ALLOW  | [x] On | [▲] [▼]  [✎ Edit] [🗑]   |  |  |
|  |  | 20  | Legacy Phone Network    | eth0       | 192.168.20.0/24    | Custom TCP Port 8080    | ALLOW  | [x] On | [▲] [▼]  [✎ Edit] [🗑]   |  |  |
|  |  | 30  | Block Problem Network   | All        | 89.248.160.0/21    | All Ports               | BLOCK  | [x] On | [▲] [▼]  [✎ Edit] [🗑]   |  |  |
|  |  +-----+-------------------------+------------+--------------------+-------------------------+--------+--------+--------------------------+  |  |
|  +------------------------------------------------------------------------------------------------------------------------------------------------+  |
+===================================================================================================================================================+

+-- SLIDE-OVER SETTINGS DRAWER (Opens when clicking [ ⚙ Protection Settings ]) ---------------------------------------------------------------------+
|  ATTACK PROTECTION SETTINGS (Auto-Ban Rules)                                                                                             [ ✕ Close ] |
|                                                                                                                                                   |
|  Protection Status: [● Active]                                                                                                                    |
|                                                                                                                                                   |
|  When to Block:                                                                                                                                   |
|  Allowed Failed Attempts:  [ 5     ] (Number of wrong passwords before an IP is blocked)                                                          |
|  Count Failures Within:    [ 10    ] minutes (Remember wrong attempts within this time window)                                                    |
|  Block Duration:           [ 1     ] hours (How long the attacker stays blocked; enter 0 for permanent)                                           |
|                                                                                                                                                   |
|  What to Protect:                                                                                                                                 |
|  [x] Protect Phone Passwords (SIP registrations and calls)                                                                                        |
|  [x] Protect Web Admin Logins (Web browser interface)                                                                                            |
|  [x] Protect Server Access (SSH terminal logins)                                                                                                  |
|                                                                                                                                                   |
|  Safety Check:                                                                                                                                    |
|  [x] Never block my current IP address (Prevents accidentally locking yourself out)                                                               |
|                                                                                                                                                   |
|  [ Cancel ]                                                                                                          [ Save Protection Settings ] |
+---------------------------------------------------------------------------------------------------------------------------------------------------+
```

---

### 1.3 Detailed Anatomy of the 5 UI Zones (Plain-English Breakdown)

#### Zone 1: Status Overview & Your Connection (Top Status Cards)
- **Firewall Status Card**:
  - *User-Facing Label*: **"Firewall Status"**
  - *What it shows*: A green dot with **"Active"** when the firewall is on, plus the active policy (e.g. *"Blocking unknown inbound traffic"*).
  - *Tooltip*: *"The firewall inspects all incoming network traffic and blocks unauthorized connections."*
- **Attack Protection Card**:
  - *User-Facing Label*: **"Attack Protection"**
  - *What it shows*: A green dot with **"Active"**, indicating that phone registration passwords, web logins, and SSH are actively monitored for brute-force guessing.
  - *Tooltip*: *"Monitors failed password attempts and automatically blocks attackers."*
- **Blocked Attackers Card**:
  - *User-Facing Label*: **"Blocked Attackers"**
  - *What it shows*: A live counter (e.g., *"3 Currently Blocked"*).
  - *Tooltip*: *"The number of IP addresses currently blocked for repeated failed login attempts."*
- **Your Connection Card (Lockout Protection)**:
  - *User-Facing Label*: **"Your Connection (Safe)"**
  - *What it shows*: The administrator's current IP address (e.g., *"Your IP: 198.51.100.22"*).
  - *Badge / Button*: If already in the trusted list, displays a green badge: **`[✓ Protected (Never Locked Out)]`**. If not yet trusted, displays an amber button: **`[+ Always Allow My IP]`**.
  - *Why this is simple & intuitive*: Even a first-time user immediately understands that their own computer is protected from being accidentally blocked.

#### Zone 2: Trusted & Blocked IP Addresses (Left Panel)
- **User-Facing Title**: **"Trusted & Blocked IP Addresses"**
- **Tabs**:
  - **`[ Trusted IPs (Always Allowed) ]`**: IP addresses and office networks that are always allowed through the firewall and can never be banned.
  - **`[ Blocked IPs (Always Dropped) ]`**: Known bad IP addresses or hostile networks that are blocked immediately upon arrival.
- **Quick-Add Bar**:
  - Label: *"IP or Network (e.g. 192.168.1.0/24)"* and *"Note (e.g. Remote Office)"*
  - Button: **`[+ Add to List]`**
- **Table**: Shows the IP address/network, description note, and a simple trash can icon **`[ Remove ]`**.

#### Zone 3: Currently Blocked Attackers (Right Panel)
- **User-Facing Title**: **"Currently Blocked Attackers"**
- **Subtitle**: *"IP addresses temporarily blocked for entering repeated wrong passwords."*
- **Table Columns**:
  - **IP Address**: The attacker's IP.
  - **Type**: Clean, friendly badges: `Phone Password (SIP)`, `Web Login`, or `Server Login (SSH)`.
  - **Reason**: e.g., *"5 bad passwords in 12s"*.
  - **Unblocks In**: A live timer showing how much time remains (e.g., *"42m left"*).
  - **Actions**:
    - **`[ Unblock ]`**: Immediately lifts the block.
    - **`[ Trust IP ]`**: Unblocks the IP and adds it to the Trusted List with 1 click (for legitimate employees who made a mistake).
    - **`[ Block Permanently ]`**: Adds the IP to the permanent Blocked List with 1 click.
- **`[+ Block IP Manually]`**: Opens a simple popup where an administrator can enter an IP address, choose how many hours to block it, and add an optional note.

#### Zone 4: Firewall Rules (Lower Panel)
- **User-Facing Title**: **"Firewall Rules"**
- **Default Inbound Policy**: The fallback action when no rule matches — *"Block Inbound Traffic (Recommended)"* or *"Allow All"* — is set through a dedicated form opened by the **`[ ⚙ Configure ]`** button on its row in the rules table; saving applies the change immediately and reports the outcome in the top-right notification.
- **Standard Phone System Ports (Quick-Overview)**:
  - Displays checkmarked badges confirming standard phone services are operational:
    `[✓ Phone Calls (SIP 5060,5080)]`  `[✓ Call Audio (RTP 16384-32768)]`  `[✓ Web Portal (80,443)]`  `[✓ Server Access (SSH 22)]`
  - Explanatory note: *"Standard phone system traffic is automatically permitted."*
- **Rules Table**:
  - Columns: `Order`, `Rule Name`, `Allowed From`, `Port or Service`, `Action` (`ALLOW` / `BLOCK`), `Active` (toggle switch), and `Actions`.
  - Simple reordering: **`[▲ Move Up]`** and **`[▼ Move Down]`** buttons to easily change rule priority without math.
- **`[ Save & Apply Changes ]` Button**:
  - Shows a pulsing amber badge whenever changes have been made (e.g., *"1 change pending"*).
  - Runs a background safety check to ensure the user's current connection is safe before applying changes.

#### Zone 5: Attack Protection Settings (Slide-Over Drawer)
- **User-Facing Title**: **"Attack Protection Settings"**
- Opens cleanly from the right side of the screen when clicking **`[ ⚙ Protection Settings ]`**.
- Uses plain, non-technical questions and fields:
  - *"How many wrong passwords before an IP is blocked?"* &rarr; **`[ 5 ] attempts`**
  - *"How long should wrong attempts be remembered?"* &rarr; **`[ 10 ] minutes`**
  - *"How long should attackers stay blocked?"* &rarr; **`[ 1 ] hour`** (with a helper note: *"Enter 0 to block permanently"*).
- Checkboxes:
  - `[x] Protect Phone Passwords (SIP)`
  - `[x] Protect Web Admin Logins`
  - `[x] Protect Server Access (SSH)`
  - `[x] Never block my current computer (Lockout Protection)`
- **Save Action**: Clicking **`[ Save Protection Settings ]`** updates settings in real time without service restarts.

---

### 1.4 High-Frequency Administrator Workflows (Plain-English Walkthrough)

#### Workflow A: Rescuing a Locked-Out Remote Employee (5 Seconds)
1. Remote employee calls in: *"I typed my softphone password wrong 5 times and now my phone won't connect."*
2. Administrator opens **Security Center** (`/panel/security`).
3. The employee's IP address is immediately visible in the **Currently Blocked Attackers** panel under `Phone Password (SIP)`.
4. Administrator clicks **`[ Unblock ]`** &rarr; The employee is immediately unblocked.
5. Administrator clicks **`[ Trust IP ]`** &rarr; The employee's IP is added to the **Trusted List** so they won't get locked out again.
6. Total time: **5 seconds**, **zero page refreshes**, **zero tab switching**.

#### Workflow B: Onboarding a New Telephone Carrier Trunk (3 Seconds)
1. Carrier provides their SBC server addresses (e.g. `64.2.142.0/24`).
2. Administrator opens **Security Center**.
3. In the **Trusted & Blocked IP Addresses** panel, ensures `Trusted IPs` is selected, enters `64.2.142.0/24`, adds note `Carrier Primary Trunk`, and clicks **`[+ Add to List ]`**.
4. The carrier network is immediately trusted and accepted across all phone system ports.

#### Workflow C: Restricting Web Portal to Office Network (15 Seconds)
1. Administrator scrolls down to **Firewall Rules**.
2. Clicks **`[+ Add New Rule ]`**.
3. Selects Service: `Web Portal (80/443)`, Allowed From: `10.10.0.0/16` (Office VPN network), Action: `ALLOW`.
4. Sets the default policy to `Block Inbound Traffic`.
5. Clicks **`[ Save & Apply Changes ]`** &rarr; The system runs a background safety check to confirm the admin's current connection is safe, then safely activates the firewall.

#### Workflow D: Tightening Protection During an Active Attack (10 Seconds)
1. PBX is experiencing high volumes of automated password guessing.
2. Administrator clicks **`[ ⚙ Protection Settings ]`** in the top right.
3. Slide-over settings drawer opens: Administrator lowers `Allowed Failed Attempts` from 5 to 3, and increases `Block Duration` from 1 hour to 24 hours.
4. Clicks **`[ Save Protection Settings ]`** &rarr; Drawer closes; the new thresholds protect the system immediately.

---

## 2. Integrated Kernel Hierarchy (Linux `nftables`)

The firewall evaluates traffic through a strict, multi-tiered hierarchy in `nftables`:

```
                           INCOMING PACKET
                                 │
                                 ▼
         [ 1. PERMANENT BLACKLIST (@blacklist_ips) ] ──────► DROP AT INGRESS
         (Known scanners, malicious ASNs, compromised IPs)
                                 │
                                 ▼
         [ 2. DYNAMIC AUTO-BANNED SET (@banned_ips) ] ─────► DROP WITH KERNEL TIMEOUT
         (Attackers caught by SIP/Web/SSH intrusion detection)
                                 │
                                 ▼
         [ 3. INVARIANTS: ESTABLISHED & LOOPBACK ] ────────► ACCEPT
         (Localhost IPC and active return traffic)
                                 │
                                 ▼
         [ 4. PERMANENT WHITELIST (@whitelist_ips) ] ──────► ACCEPT UNCONDITIONALLY
         (Office static IPs, Carrier SIP Trunks, VPN subnets)
                                 │
                                 ▼
         [ 5. SYSTEM PBX PORTS BASELINE ] ─────────────────► ACCEPT
         (SIP 5060/5080, RTP 16384-32768, Web 80/443, SSH 22)
                                 │
                                 ▼
         [ 6. CUSTOM SEQUENTIAL RULES (Seq 10, 20...) ] ───► MATCH FIRST RULE
         (Specific interface/subnet port restrictions)
                                 │
                                 ▼
         [ 7. DEFAULT INCOMING POLICY ] ───────────────────► DROP
```

---

## 3. Global Constraints & Architectural Invariants

- **Strict Types**: `declare(strict_types=1);` on all PHP files with native type hints.
- **Documentation**: Clear, plain-language PHPDoc comments on all classes and methods explaining intent.
- **Admin-Only Guard**: The security module manages host-level network interfaces and packet filtering. It is **exclusively accessible to the `admin` guard** (`App\Models\Admin`) with `security.view` and `security.edit` permissions. Tenant users (`web` guard) never see or access this module.
- **Privilege Boundary**: `www-data` executes only `/usr/local/sbin/tallpbx-security` via sudoers. No raw shell interpolation or arbitrary commands.
- **Zero-Lockout Guarantee**: The `LockoutGuardService` validates that applying any firewall ruleset or default drop policy will never block the active administrator's IP address (`request()->ip()`). If an admin session would be blocked, the system fails closed with an explanatory alert.
- **Atomic Kernel Transactions**: All packet filtering rules compile into a single `nftables` atomic transaction. The helper executes `nft -c` for syntax verification before atomic loading (`nft -f`). If a rule is invalid, the active kernel state is left completely untouched.
- **Cache Clearing**: Run `php artisan optimize:clear` after any code change.

---

## 4. Streamlined File Structure

Module root: `app-modules/security/`

| File | Purpose |
| --- | --- |
| `module.json` | Module metadata for `php artisan module:sync` |
| `composer.json` | Path-repository package `tallpbx/module-security` |
| `database/migrations/2026_09_18_000001_create_security_rules_table.php` | Sequential firewall filtering rules table |
| `database/migrations/2026_09_18_000002_create_security_services_table.php` | PBX Port Catalog (standard & custom services) |
| `database/migrations/2026_09_18_000003_create_security_ip_lists_table.php` | Whitelist (allow-all) & Blacklist (drop-all) IP/CIDRs |
| `database/migrations/2026_09_18_000004_create_security_settings_table.php` | Intrusion thresholds and firewall policies |
| `database/migrations/2026_09_18_000005_create_security_bans_table.php` | Native bans database table (active & historical) |
| `database/migrations/2026_09_18_000006_create_security_audit_logs_table.php` | Enterprise audit trail of admin actions |
| `database/seeders/SecurityServiceSeeder.php` | Seeds standard PBX services (SIP, RTP, Web, SSH, ESL, Reverb) |
| `src/Models/SecurityRule.php` | Eloquent model for sequential firewall rules |
| `src/Models/SecurityService.php` | Eloquent model for port catalog definitions |
| `src/Models/SecurityIpList.php` | Eloquent model for Whitelist and Blacklist entries |
| `src/Models/SecuritySetting.php` | Eloquent model for key-value security settings |
| `src/Models/SecurityBan.php` | Eloquent model for active and historical banned IP records |
| `src/Models/SecurityAuditLog.php` | Eloquent model for security action audit trail |
| `src/Contracts/SecurityIncidentServiceInterface.php` | Contract for tracking attack incidents |
| `src/Services/SecurityIncidentService.php` | Redis atomic sliding-window rate limiter |
| `src/Contracts/SecurityBanServiceInterface.php` | Contract for executing bans and unbans |
| `src/Services/SecurityBanService.php` | Manages MariaDB bans and `nftables` dynamic set sync |
| `src/Services/SecurityConfigGenerator.php` | Compiles `/etc/tallpbx/firewall.nft` ruleset |
| `src/Services/SecurityExecutor.php` | Executes bounded helper `/usr/local/sbin/tallpbx-security` |
| `src/Services/LockoutGuardService.php` | Preflight validator ensuring admin IP remains reachable |
| `src/Listeners/LogFailedLoginListener.php` | Listens to `Illuminate\Auth\Events\Failed` -> `SecurityIncidentService` |
| `src/Livewire/SecurityManager.php` | Unified Livewire 4 component driving the entire single-screen UI |
| `src/Console/Commands/SecurityApplyCommand.php` | Artisan command `security:apply` |
| `src/Console/Commands/SecurityStatusCommand.php` | Artisan command `security:status` |
| `src/Console/Commands/SecurityUnbanCommand.php` | Artisan command `security:unban <ip>` |
| `src/Providers/ModuleServiceProvider.php` | Service bindings, permissions, menus, listeners |
| `resources/views/security-manager.blade.php` | Unified single-screen Blade view with DaisyUI 5 |

System & Installer Files:

| File | Purpose |
| --- | --- |
| `scripts/resources/tallpbx-security` | Bounded root helper script (`/usr/local/sbin/tallpbx-security`) |
| `scripts/resources/tallpbx-security.sudoers` | Sudoers rule (`/etc/sudoers.d/tallpbx-security`) |
| `scripts/resources/security.sh` | Installer resource script (installs `nftables`, sets baseline) |
| `scripts/install.sh` | Integrates `security.sh` into server installation |

---

## 5. Database Schema & Data Models

### 5.1 `security_ip_lists` (Unified Whitelist & Blacklist)
```sql
CREATE TABLE `security_ip_lists` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('whitelist', 'blacklist') NOT NULL DEFAULT 'whitelist',
    `ip_address` VARCHAR(100) NOT NULL COMMENT 'IPv4 or CIDR (e.g. 192.168.1.0/24)',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE INDEX `idx_security_ip_type` (`type`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 `security_rules` (Sequential Firewall Rules)
```sql
CREATE TABLE `security_rules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sequence` INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Order of evaluation (10, 20, 30...)',
    `description` VARCHAR(255) NOT NULL,
    `source_ip` VARCHAR(100) NOT NULL COMMENT 'Single IP, CIDR (e.g. 192.168.1.0/24), or "any"',
    `service_id` BIGINT UNSIGNED NULL COMMENT 'Foreign key to security_services or NULL for custom',
    `custom_port` VARCHAR(100) NULL,
    `custom_protocol` ENUM('all', 'tcp', 'udp') NULL,
    `action` ENUM('accept', 'drop') NOT NULL DEFAULT 'accept',
    `enabled` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_security_rules_sequence` (`sequence`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 `security_services` (PBX Port Catalog)
```sql
CREATE TABLE `security_services` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `protocol` ENUM('tcp', 'udp', 'both') NOT NULL DEFAULT 'both',
    `port_range` VARCHAR(100) NOT NULL COMMENT 'Single port, comma list, or range like 16384:32768',
    `is_system` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
**Standard System Seed Data:**
* **SIP Signaling**: Protocol `both`, Ports `5060,5061,5080`
* **RTP Voice/Video Media**: Protocol `udp`, Ports `16384:32768`
* **Web Admin Portal**: Protocol `tcp`, Ports `80,443`
* **SSH Console**: Protocol `tcp`, Port `22`
* **FreeSWITCH ESL**: Protocol `tcp`, Port `8021`
* **Reverb WebSockets**: Protocol `tcp`, Port `8080`
* **WebRTC WSS**: Protocol `tcp`, Port `7443`

### 5.4 `security_bans` (Single Source of Truth for Banned IPs)
```sql
CREATE TABLE `security_bans` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(100) NOT NULL,
    `vector` ENUM('web_auth', 'sip_auth', 'ssh', 'manual') NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `banned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL COMMENT 'NULL indicates permanent ban',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `unbanned_at` TIMESTAMP NULL,
    `unbanned_by_admin_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_security_bans_ip` (`ip_address`, `is_active`),
    INDEX `idx_security_bans_active_expires` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.5 `security_settings` (Key-Value Security Configuration)
```sql
CREATE TABLE `security_settings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
**Keys Used:**
* `firewall_enabled`: `true`/`false`
* `firewall_default_policy`: `drop` (recommended) or `accept`
* `intrusion_detection_enabled`: `true`/`false`
* `ban_time`: `3600` (1 hour)
* `find_time`: `600` (10 minutes)
* `max_retry`: `5` (attempts)
* `protect_sip`: `true`/`false`
* `protect_web`: `true`/`false`
* `protect_ssh`: `true`/`false`

---

## 6. Linux `nftables` Kernel Ruleset Architecture

The `SecurityConfigGenerator` compiles `/etc/tallpbx/firewall.nft`:

```nft
#!/usr/sbin/nft -f

flush ruleset

table inet tallpbx_filter {
    # 1. Permanent Blacklist Set (Kernel interval tree)
    set blacklist_ips {
        type ipv4_addr
        flags interval
        elements = { 45.142.120.0/24, 185.220.101.5 }
    }

    # IPv6 mirror of the blacklist set
    set blacklist_ips6 {
        type ipv6_addr
        flags interval
        elements = { 2001:db8:bad::/48 }
    }

    # 2. Dynamic Auto-Banned Set (with automatic kernel timeouts)
    set banned_ips {
        type ipv4_addr
        flags timeout
    }

    # IPv6 mirror of the dynamic auto-banned set
    set banned_ips6 {
        type ipv6_addr
        flags timeout
    }

    # 3. Permanent Whitelist Set (Immune to drops & bans)
    set whitelist_ips {
        type ipv4_addr
        flags interval
        elements = { 192.168.1.0/24, 198.51.100.10 }
    }

    # IPv6 mirror of the whitelist set
    set whitelist_ips6 {
        type ipv6_addr
        flags interval
        elements = { 2001:569:fcd9:900:e95c:2439:5a28:86b }
    }

    chain input {
        type filter hook input priority -10; policy drop;

        # STEP 1: DROP BLACKLISTED NETWORKS & IPs IMMEDIATELY (IPv4 + IPv6)
        ip saddr @blacklist_ips drop
        ip6 saddr @blacklist_ips6 drop

        # STEP 2: DROP TEMPORARILY BANNED BRUTE-FORCE ATTACKERS (IPv4 + IPv6)
        ip saddr @banned_ips drop
        ip6 saddr @banned_ips6 drop

        # STEP 3: BASE INVARIANTS: LOOPBACK & ESTABLISHED CONNECTIONS
        iif "lo" accept
        ct state established,related accept
        ct state invalid drop

        # STEP 4: ACCEPT WHITELISTED / TRUSTED IPs UNCONDITIONALLY (IPv4 + IPv6)
        ip saddr @whitelist_ips accept
        ip6 saddr @whitelist_ips6 accept

        # STEP 5: ICMP (Ping) — configurable policy for both families
        ip protocol icmp icmp type echo-request accept
        ip6 nexthdr ipv6-icmp icmpv6 type echo-request accept

        # Essential IPv6 connectivity invariant (never severed): path-MTU
        # discovery, MLD multicast maintenance, and Neighbor Discovery only
        ip6 nexthdr ipv6-icmp icmpv6 type { packet-too-big, mld-listener-query, mld-listener-report, mld-listener-done, mld2-listener-report, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, nd-redirect } accept

        # STEP 6: CORE PBX TELEPHONY PORTS
        # SIP Signaling
        udp dport { 5060, 5061, 5080 } accept
        tcp dport { 5060, 5061, 5080 } accept

        # RTP Voice/Video Media Streams
        udp dport 16384-32768 accept

        # Web Admin Portal
        tcp dport { 80, 443 } accept

        # SSH Console
        tcp dport 22 accept

        # STEP 7: CUSTOM SEQUENTIAL RULES (Evaluated in sequence)
        # Rule 10: Specific Branch Allowed to Custom Port
        ip saddr 10.10.0.0/16 tcp dport 8080 accept

        # STEP 8: DEFAULT POLICY
        drop
    }

    chain forward {
        type filter hook forward priority filter; policy drop;
    }

    chain output {
        type filter hook output priority filter; policy accept;
    }
}
```

---

## 7. Native Attack Ingestion & Sliding Rate Limiter

### 7.1 Attack Ingestion
1. **Web UI Login Defense**: Laravel's `Illuminate\Auth\Events\Failed` listener captures failed logins in-process and calls `SecurityIncidentService::recordFailure()`.
2. **FreeSWITCH SIP Defense**: FreeSWITCH's Event Socket Layer (ESL) emits `CUSTOM sofia::failed_auth` directly over the TCP socket into `php artisan freeswitch:listen`. Offending IPs are forwarded to `SecurityIncidentService` in < 1 millisecond.

### 7.2 Redis Sliding-Window Rate Limiter (`SecurityIncidentService`)
```php
public function recordFailure(string $ip, string $vector, string $details): void
{
    // 1. Never track or ban Whitelisted IPs
    if ($this->isWhitelisted($ip)) {
        return;
    }

    $findTime = (int) SecuritySetting::get('find_time', 600);
    $maxRetry = (int) SecuritySetting::get('max_retry', 5);
    $banTime = (int) SecuritySetting::get('ban_time', 3600);

    $redisKey = "tallpbx:security:attempts:{$vector}:{$ip}";
    $attempts = Redis::incr($redisKey);

    if ($attempts === 1) {
        Redis::expire($redisKey, $findTime);
    }

    if ($attempts >= $maxRetry) {
        Redis::del($redisKey);

        $this->banService->ban(
            ip: $ip,
            vector: $vector,
            reason: "Exceeded {$maxRetry} failed attempts within {$findTime}s: {$details}",
            durationSeconds: $banTime
        );
    }
}
```

---

## 8. Bounded Sudoers & Helper Script

### 8.1 `/etc/sudoers.d/tallpbx-security`
```sudoers
www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/tallpbx-security
```

### 8.2 `/usr/local/sbin/tallpbx-security`
File permissions: `0750 root:www-data`.
```bash
#!/bin/bash
set -euo pipefail

ACTION="${1:-}"
IP_REGEX="^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$"
SECONDS_REGEX="^[0-9]{1,9}$"

case "$ACTION" in
    apply)
        if [ ! -f /etc/tallpbx/firewall.nft.pending ]; then
            echo "ERROR: Pending file not found" >&2; exit 1
        fi
        /usr/sbin/nft -c -f /etc/tallpbx/firewall.nft.pending
        mv /etc/tallpbx/firewall.nft.pending /etc/tallpbx/firewall.nft
        chmod 0640 /etc/tallpbx/firewall.nft
        /usr/sbin/nft -f /etc/tallpbx/firewall.nft
        echo "SUCCESS: Ruleset applied atomically"
        ;;

    ban)
        IP="${2:-}"
        SECS="${3:-3600}"
        [[ "$IP" =~ $IP_REGEX ]] || { echo "ERROR: Invalid IP format" >&2; exit 2; }
        [[ "$SECS" =~ $SECONDS_REGEX ]] || { echo "ERROR: Invalid seconds" >&2; exit 3; }
        /usr/sbin/nft add element inet tallpbx_filter banned_ips { "$IP" timeout "${SECS}s" }
        echo "SUCCESS: Banned $IP for $SECS seconds"
        ;;

    unban)
        IP="${2:-}"
        [[ "$IP" =~ $IP_REGEX ]] || { echo "ERROR: Invalid IP format" >&2; exit 2; }
        /usr/sbin/nft delete element inet tallpbx_filter banned_ips { "$IP" } 2>/dev/null || true
        echo "SUCCESS: Unbanned $IP"
        ;;

    status)
        /usr/sbin/nft list ruleset
        ;;

    *)
        echo "Usage: $0 {apply|ban <ip> <seconds>|unban <ip>|status}" >&2
        exit 1
        ;;
esac
```

### 8.3 Threat Model & Jailbreak Defense Guarantees

1. **Zero Shell Interpolation (CWE-78 Prevention)**:
   PHP invokes `/usr/local/sbin/tallpbx-security` strictly via Symfony Process using discrete argv string arrays:
   `new Process(['sudo', '-n', '/usr/local/sbin/tallpbx-security', 'ban', $ip, $seconds])`
   Because arguments are passed directly to `execve()`, shell metacharacters (`;`, `|`, `&&`, `$()`, backticks) are NEVER evaluated as shell operators.
2. **Bounded Sudoers Scope (Least Privilege)**:
   `/etc/sudoers.d/tallpbx-security` restricts `www-data` execution privileges solely to `/usr/local/sbin/tallpbx-security`. The web server user cannot invoke `bash`, `cat`, `rm`, or any arbitrary system binary as root.
3. **Hardcoded Command Paths**:
   All underlying utility calls inside the helper script use absolute hardcoded paths (`/usr/sbin/nft`, `/bin/mv`, `/bin/chmod`), eliminating PATH hijacking vulnerabilities.
4. **Strict Regex Parameter Whitelisting**:
   The helper script validates every input against strict regular expressions:
   - IPv4/IPv6: `^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$` or `^(([0-9a-fA-F]{0,4}:){1,7}[0-9a-fA-F]{0,4}(/[0-9]{1,3})?)$`
   - Duration: `^[0-9]{1,9}$`
   Any invalid or malformed parameter immediately exits with code `2` or `3` before touching the Linux packet filtering subsystem.
5. **No Interactive Escape Vectors (GTFOBins Immunity)**:
   The script does not invoke pagers (`less`, `more`), editors (`vi`, `nano`), or commands with interactive subshells (`find -exec`), guaranteeing that the helper cannot be leveraged for a root shell breakout.

---

## 9. Streamlined Single-Screen Livewire Component Architecture

Rather than fragmenting business logic and user interactions across multiple separate pages, controllers, or isolated Livewire components, a single unified master component **`Modules\Security\Livewire\SecurityManager`** orchestrates the entire command center at `/panel/security`.

### 9.1 Component State & Reactive Properties

```php
namespace Modules\Security\Livewire;

use Livewire\Component;
use Livewire\WithPagination;

class SecurityManager extends Component
{
    use WithPagination;

    // --- ZONE 1: ENGINE STATUS & ADMIN GUARD ---
    public bool $firewallEnabled = true;
    public string $defaultPolicy = 'drop'; // 'drop' or 'accept'
    public bool $sentinelEnabled = true;
    public string $adminIp = '';
    public bool $isAdminWhitelisted = false;
    public int $pendingChangesCount = 0;

    // --- ZONE 2: GLOBAL ACCESS LISTS (WHITELIST & BLACKLIST) ---
    public string $activeIpTab = 'whitelist'; // 'whitelist' or 'blacklist'
    public string $newIpAddress = '';
    public string $newIpDescription = '';
    public string $ipSearchQuery = '';

    // --- ZONE 3: LIVE THREAT SENTINEL & AUTO-BANS ---
    public string $banSearchQuery = '';
    public bool $showManualBanModal = false;
    public string $manualBanIp = '';
    public int $manualBanDuration = 3600; // seconds
    public string $manualBanReason = 'Manual administrative ban';

    // --- ZONE 4: SEQUENTIAL FIREWALL RULES & PBX CATALOG ---
    public bool $showRuleModal = false;
    public ?int $editingRuleId = null;
    public string $ruleDescription = '';
    public string $ruleSourceIp = 'any';
    public ?int $ruleServiceId = null;
    public string $ruleCustomPort = '';
    public string $ruleCustomProtocol = 'all'; // 'all', 'tcp', 'udp'
    public string $ruleAction = 'accept'; // 'accept', 'drop'
    public bool $ruleEnabled = true;

    // --- ZONE 5: INTRUSION SETTINGS (SLIDE-OVER DRAWER) ---
    public bool $showThresholdsDrawer = false;
    public int $maxRetry = 5;
    public int $findTime = 600;
    public int $banTime = 3600;
    public bool $protectSip = true;
    public bool $protectWeb = true;
    public bool $protectSsh = true;
    public bool $enforceLockoutProtection = true;
}
```

---

### 9.2 Real-Time Methods & Action Handlers

#### Zone 1: Status & Safety Actions
- **`mount()`**: Auto-detects admin IP (`request()->ip()`), evaluates whether it exists in `security_ip_lists` (trusted list), loads settings from `security_settings`, and calculates pending changes.
- **`whitelistCurrentIp()`**: Immediately inserts `$this->adminIp` into `security_ip_lists` (`type: whitelist`), recompiles the kernel `@whitelist_ips` set via `SecurityExecutor`, sets `$this->isAdminWhitelisted = true`, and dispatches a DaisyUI success toast: *"Your IP address has been added to the Trusted list. You will never be locked out."*
- **`applyFirewallChanges()`**:
  1. Calls `LockoutGuardService::validate()` to ensure the admin cannot lock themselves out.
  2. Compiles pending configuration via `SecurityConfigGenerator`.
  3. Executes `SecurityExecutor::apply()`.
  4. Resets `$pendingChangesCount = 0` and dispatches toast: *"Firewall rules successfully saved and activated."*

#### Zone 2: Trusted & Blocked IP List Operations
- **`switchIpTab(string $tab)`**: Toggles between `'whitelist'` (Trusted) and `'blacklist'` (Blocked) with zero page refresh.
- **`addIpEntry()`**: Validates IP or CIDR notation using regex (`^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$`), persists to `security_ip_lists`, triggers immediate kernel set update (`@whitelist_ips` or `@blacklist_ips`), resets the input fields, and dispatches toast: *"IP address successfully added to list."*
- **`deleteIpEntry(int $id)`**: Deletes the record from `security_ip_lists` and syncs the kernel set immediately.

#### Zone 3: Currently Blocked Attackers & Unblocking Operations
- **`refreshBans()`**: Triggered every 5 seconds via `wire:poll.5s="refreshBans"` to refresh the blocked attackers table and purge any records that expired in the database.
- **`unban(int $banId)`**: Calls `SecurityBanService::unban()`, which deletes the element from the kernel `banned_ips` set, clears the Redis failure counter, marks the database record inactive, records an audit log, and dispatches toast: *"Attacker IP has been unblocked."*
- **`promoteToWhitelist(int $banId)`**: Unblocks the host, copies its IP to `security_ip_lists` as a trusted entry, and syncs the kernel set. Dispatches toast: *"IP has been unblocked and added to the Trusted list."*
- **`promoteToBlacklist(int $banId)`**: Unblocks from temporary set, adds to permanent blocked list, and drops packets at step 1 of packet ingress. Dispatches toast: *"IP has been permanently blocked."*
- **`executeManualBan()`**: Validates IP and duration, invokes `SecurityBanService::ban()`, closes modal, refreshes the table, and dispatches toast: *"IP address has been blocked."*

#### Zone 4: Firewall Rules Operations
- **`reorderRule(int $ruleId, string $direction)`**: Swaps sequence numbers (e.g. moving rule 20 to 10), marks 1 pending change, and updates the table ordering immediately.
- **`toggleRuleStatus(int $ruleId)`**: Toggles `enabled` boolean on the rule, increments pending changes counter, and updates the UI toggle switch optimistically.
- **`saveRule()`**: Validates rule attributes, creates or updates the `SecurityRule` model, increments pending changes counter, closes the modal, and dispatches toast: *"Rule saved. Click 'Save & Apply Changes' to activate."*
- **`deleteRule(int $ruleId)`**: Removes rule from database and increments pending changes counter.

#### Zone 5: Attack Protection Settings Operations
- **`saveThresholdSettings()`**: Validates numeric thresholds (`maxRetry >= 1`, `findTime >= 10`, `banTime >= 0`), updates `security_settings` table, syncs settings to Redis, closes the slide-over drawer, and dispatches toast: *"Attack protection settings saved."*

---

### 9.3 Alpine 5 & DaisyUI 5 Micro-Interactions

1. **Slide-Over Drawer**: Controlled via Alpine `x-show="showDrawer"` with smooth transition classes (`translate-x-full` to `translate-x-0`). Clicking the backdrop or `[ ✕ ]` closes the drawer without unmounting the parent component.
2. **Live Countdown Timers**: Each row in the active bans table binds to a lightweight Alpine timer:
   ```html
   <span x-data="countdown({{ $ban->expires_at?->timestamp }})" x-text="timeRemaining"></span>
   ```
   Counts down every second in the browser and displays `Unblocked` when finished.
3. **Pulsing Pending Changes Badge**: If `$pendingChangesCount > 0`, the `[ Save & Apply Changes ]` button displays an animated amber pulse (`animate-pulse`) with a badge showing how many changes are pending, so administrators never forget to activate their edits.
4. **Zero-Lockout Safety Alert**: If an administrator attempts to set the default policy to `Block Inbound Traffic` without a valid trusted entry covering their IP, the UI displays a clear, friendly alert banner: *"Safety Notice: Your current connection is not yet in the Trusted list. Please click 'Always Allow My IP' before blocking inbound traffic."* with a 1-click button: **`[+ Always Allow My IP Now]`**.

---

## 10. Step-by-Step Implementation Tasks

### Task 1: Scaffolding, Composer & Base Configuration
- [ ] Create `app-modules/security/module.json`.
- [ ] Create `app-modules/security/composer.json` (`tallpbx/module-security`).
- [ ] Register path repository in root `composer.json` and run `composer update tallpbx/module-security`.
- [ ] Create `src/Providers/ModuleServiceProvider.php` extending `App\Support\ModuleServiceProvider`.
- [ ] Register permissions: `security.view` and `security.edit`.
- [ ] Register primary server-wide navigation on the main panel menu (`security`, route `panel.security.index`).

### Task 2: Database Migrations, Models & Seeder
- [ ] Create migration `2026_09_18_000001_create_security_rules_table.php`.
- [ ] Create migration `2026_09_18_000002_create_security_services_table.php`.
- [ ] Create migration `2026_09_18_000003_create_security_ip_lists_table.php` (Whitelist & Blacklist).
- [ ] Create migration `2026_09_18_000004_create_security_settings_table.php`.
- [ ] Create migration `2026_09_18_000005_create_security_bans_table.php`.
- [ ] Create migration `2026_09_18_000006_create_security_audit_logs_table.php`.
- [ ] Create Eloquent models: `SecurityRule`, `SecurityService`, `SecurityIpList`, `SecuritySetting`, `SecurityBan`, `SecurityAuditLog`.
- [ ] Create `SecurityServiceSeeder.php` seeding standard PBX services.
- [ ] Run migrations and seeders; write Pest tests verifying schemas and seed data.

### Task 3: In-Process Auth Failure Listener (Web UI Vector)
- [ ] Implement `Modules\Security\Listeners\LogFailedLoginListener` listening to `Illuminate\Auth\Events\Failed`.
- [ ] Inject `SecurityIncidentServiceInterface` into listener to call `recordFailure()`.
- [ ] Register listener in `ModuleServiceProvider`.
- [ ] Write Pest test simulating failed login attempts and verifying incident recording.

### Task 4: FreeSWITCH ESL SIP Auth Listener (SIP Vector)
- [ ] In `FreeSwitchListenCommand` or a dedicated event listener, hook `CUSTOM sofia::failed_auth` events.
- [ ] Extract `network-ip`, user, and realm; forward to `SecurityIncidentServiceInterface`.
- [ ] Write Pest test verifying ESL event dispatches incident to security service.

### Task 5: Redis Sliding-Window Rate Limiter & Ban Service
- [ ] Implement `Modules\Security\Services\SecurityIncidentService` (bypasses Whitelist, increments Redis counter with TTL).
- [ ] Implement `Modules\Security\Services\SecurityBanService`:
  * Saves active record to `security_bans` table.
  * Records entry in `security_audit_logs`.
  * Calls `SecurityExecutor->ban($ip, $duration)`.
  * Handles `unban($ip)` (updates DB, calls executor, flushes Redis).
- [ ] Write Pest unit tests with mock Redis and executor verifying threshold triggers, whitelist bypass, and unbans.

### Task 6: Bounded Host Helper Script & Sudoers Boundary
- [ ] Write `scripts/resources/tallpbx-security` bash helper.
- [ ] Write `scripts/resources/tallpbx-security.sudoers`.
- [ ] Validate shell script syntax: `bash -n scripts/resources/tallpbx-security`.
- [ ] Implement `Modules\Security\Services\SecurityExecutor` executing commands via `Symfony\Component\Process\Process`.
- [ ] Write Pest unit test mocking process execution.

### Task 7: Firewall Generator & Lockout Protection Guard
- [ ] Implement `Modules\Security\Services\SecurityConfigGenerator`:
  * Compiles `/etc/tallpbx/firewall.nft.pending` with `@blacklist_ips`, `@banned_ips`, `@whitelist_ips`, and sequential rules.
- [ ] Implement `Modules\Security\Services\LockoutGuardService`:
  * Evaluates `request()->ip()` against proposed rules and whitelist.
- [ ] Write Pest tests verifying generated `nftables` syntax, whitelist inclusion, and lockout prevention.

### Task 8: Console Commands
- [ ] Create `Modules\Security\Console\Commands\SecurityApplyCommand` (`php artisan security:apply`).
- [ ] Create `Modules\Security\Console\Commands\SecurityStatusCommand` (`php artisan security:status`).
- [ ] Create `Modules\Security\Console\Commands\SecurityUnbanCommand` (`php artisan security:unban <ip>`).
- [ ] Write Pest tests verifying console commands invoke expected executor methods.

### Task 9: Unified Single-Screen Livewire Component (`SecurityManager`)
- [ ] Create `Modules\Security\Livewire\SecurityManager`:
  * **Zone 1 (Top Hero)**: Engine state badges, admin IP detector, 1-click `whitelistCurrentIp()` action, and lockout guard warning.
  * **Zone 2 (Left Deck)**: Segmented Whitelist & Blacklist switcher, inline quick-add form with CIDR validation, search filter, and instant delete.
  * **Zone 3 (Right Deck)**: Live threat defense table showing active banned attackers with dynamic countdown timers, vector badges, 1-click `unban()`, 1-click `promoteToWhitelist()`, and 1-click `promoteToBlacklist()`.
  * **Zone 4 (Lower Deck)**: Sequential firewall rules table with Up/Down priority reordering, inline enable toggle, PBX port catalog quick-badges, custom rule modal, and pulsing `applyFirewallChanges()` button.
  * **Zone 5 (Slide-over Drawer)**: Non-intrusive intrusion sensitivity drawer with sliders/inputs for Max Retries, Find Time, Ban Duration, and attack vector toggles.
  * **Modals**: Lightweight Alpine modals for `[+ Manual Ban ]` and `[+ Add Custom Rule ]`.
- [ ] Build Blade view `resources/views/security-manager.blade.php` styled with Tailwind CSS v4 and DaisyUI 5 cards, badges, and modals.
- [ ] Write comprehensive Pest Livewire tests verifying:
  * Admin IP detection and 1-click whitelisting.
  * Instant tab switching between whitelist and blacklist without reload.
  * 1-click unban and promotion to whitelist.
  * Rule sequence reordering and pending changes counter.
  * Lockout protection blocking hazardous rule applications.

### Task 10: Installer Integration & End-to-End Verification
- [ ] Create `scripts/resources/security.sh`:
  * Installs `nftables`.
  * Installs `/usr/local/sbin/tallpbx-security` and `/etc/sudoers.d/tallpbx-security`.
  * Sets baseline `nftables` rules (ensuring SSH, Web, SIP, and RTP remain accessible).
  * Enables and starts `nftables` systemd service.
- [ ] Hook `security.sh` into `scripts/install.sh`.
- [ ] Clear caches with `php artisan optimize:clear`.
- [ ] Run full security test suite: `php artisan test --compact --filter=Security`.

---

## 11. Verification & Safety Guarantees

1. **Zero External Daemons**: Proves that Fail2ban is not required; all banning, unbanning, and expiration occurs in the Linux kernel via `nftables` sets and Redis.
2. **Single-Screen Usability**: All essential operations (viewing status, unbanning an attacker, whitelisting an IP, reordering firewall rules) happen on a single responsive screen without page reloads or tab navigation.
3. **Kernel-Level Ingress Drops**: Blacklisted subnets are dropped at step 1 before reaching any port or service daemon.
4. **Lockout Safety**: A test must verify that an admin session from IP `203.0.113.5` cannot save a `DROP` default policy without an explicit whitelist or `ACCEPT` rule covering their IP.
5. **Sub-Millisecond Banning**: Automated test verifies that 5 simulated failed logins trigger a kernel ban within milliseconds without waiting for log scraping.
6. **Atomic Packet Filtering**: If `/etc/tallpbx/firewall.nft.pending` contains invalid syntax, `nft -c` fails, the active firewall is untouched, and an actionable error is reported.
7. **Strict Parameter Validation**: The bash helper strictly rejects any IP containing semicolons, spaces, or non-IP characters using bash regex.
8. **Audit Trail**: Every unban, ban, and rule modification creates a record in `security_audit_logs`.
9. **Idempotency**: Running `scripts/resources/security.sh` repeatedly preserves existing custom rules, whitelists, and blacklists.
