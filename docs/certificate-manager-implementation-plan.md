# Certificate Manager UI Module Implementation Plan (HTTPS, SIP TLS, & WebRTC WSS)

## Document Metadata & Status
- **Target Module**: `app-modules/certificates/` (`Modules\Certificates`)
- **Status**: Draft / Proposed Architectural Specification
- **Created**: 2026-10-04
- **Branch Target**: `3.x` (TallPBX active series branch)
- **Reference Model**: FreePBX Certificate Manager (`certman`) & Modern TALL Stack Architecture

---

## Table of Contents
1. [Executive Summary & FreePBX Parity Analysis](#1-executive-summary--freepbx-parity-analysis)
2. [Architectural Principles & System Invariants](#2-architectural-principles--system-invariants)
3. [Navigation & Role-Based Access Control](#3-navigation--role-based-access-control)
4. [Dual-Service Deployment Engine](#4-dual-service-deployment-engine)
   - 4.1 Web Infrastructure (Nginx HTTPS & Reverb WebSockets)
   - 4.2 Telephony Infrastructure (FreeSWITCH SIP TLS :5061 & WebRTC WSS :7443)
5. [Host Security & Bounded Helper Architecture](#5-host-security--bounded-helper-architecture)
   - 5.1 Privileged Helper Script: `/usr/local/sbin/tallpbx-certificate`
   - 5.2 Sudoers Policy: `/etc/sudoers.d/tallpbx-certificate`
   - 5.3 PHP Executor & Test-Isolation Guard
6. [Challenge Methods & Certificate Types](#6-challenge-methods--certificate-types)
   - 6.1 Let's Encrypt (ACME v2: HTTP-01 & DNS-01 Cloudflare)
   - 6.2 Custom PEM Certificate Import
   - 6.3 Self-Signed CA & Certificate Generator
7. [Database Schema & Eloquent Models](#7-database-schema--eloquent-models)
   - 7.1 Migrations & Table Definitions
   - 7.2 Models & Type Casts
8. [Livewire 4 & DaisyUI 5 User Interface](#8-livewire-4--daisyui-5-user-interface)
   - 8.1 Tabbed Administrative Layout
   - 8.2 Component Architecture & State Management
   - 8.3 Interaction Flows & Modals
9. [Automated Renewal, Monitoring & CLI Commands](#9-automated-renewal-monitoring--cli-commands)
   - 9.1 Artisan Commands
   - 9.2 Scheduled Tasks & Expiration Alerts
10. [Testing Strategy & Verification Gates](#10-testing-strategy--verification-gates)
11. [Phased Implementation Roadmap](#11-phased-implementation-roadmap)

---

## 1. Executive Summary & FreePBX Parity Analysis

### 1.1 Executive Summary
TallPBX requires a first-class, web-native **Certificate Manager Module** that eliminates the requirement for system administrators to use the Linux CLI for issuing, renewing, and binding SSL/TLS certificates. 

Unlike legacy open-source telephony systems where web server SSL and PBX telephony TLS were developed in isolation or configured manually across divergent config files, the TallPBX Certificate Manager provides a **unified certificate lifecycle engine**. A single certificate (e.g. `pbx.example.com` or wildcard `*.example.com`) can be issued once and assigned simultaneously to:
1. **Web Services**: Nginx reverse proxy serving the TallPBX administrative panel over HTTPS (port 443) and encrypted WebSocket tunnels for Laravel Reverb (`/app` $\rightarrow$ `127.0.0.1:8080`).
2. **Telephony Services**: FreeSWITCH Sofia SIP endpoints over SIP TLS (port 5061) and WebRTC Secure WebSockets (port 7443, `wss.pem`).

The module incorporates full support for:
- **Automated Let's Encrypt Issuance & Renewals**: Supporting standard HTTP-01 webroot challenges and DNS-01 challenges via Cloudflare API tokens (enabling wildcard certificates `*.domain.com`).
- **Custom Certificate Imports**: Allowing operators to upload or paste external certificates, private keys, and intermediate CA chains (e.g., DigiCert, Sectigo, GlobalSign).
- **Self-Signed Certificate Generator**: Providing instant cryptographic certificates for development, staging, or isolated lab environments.
- **Zero-Downtime Atomic Swaps**: Validating certificate/key modulus pairs and Nginx configuration syntax prior to activating changes, preventing web lockout or telephony crashes.

### 1.2 FreePBX `certman` Parity Matrix

| Feature / Capability | FreePBX `certman` | TallPBX Certificate Manager | Modernization Advantage in TallPBX |
| :--- | :--- | :--- | :--- |
| **ACME Client** | Native PHP ACME library / Certbot shell wrapper | Certbot via Bounded Root Helper (`/usr/local/sbin/tallpbx-certificate`) | Strict argument-sanitized sudo helper; prevents PHP arbitrary process execution |
| **HTTP-01 Challenge** | Yes (Asterisk built-in webserver or Apache) | Yes (Nginx standard `/.well-known/acme-challenge/`) | Non-blocking Nginx location block; never disrupts PBX web panel |
| **DNS-01 Challenge** | Optional third-party hooks / manual hooks | Native Cloudflare API Token integration (`python3-certbot-dns-cloudflare`) | Securely encrypted credential vault; automated wildcard renewal without manual hooks |
| **Wildcard Support** | Difficult (requires custom DNS scripts) | Out-of-the-box via Cloudflare DNS-01 | One-click wildcard issuance (`*.domain.com` + `domain.com`) |
| **Custom Uploads** | Upload or Paste PEM | Upload or Paste PEM with real-time browser & server modulus verification | Client & server pre-validation prevents corrupted or mismatched private keys |
| **Self-Signed Generator** | Basic OpenSSL wrapper | OpenSSL RSA 2048/4096 or ECDSA with customizable SANs and expiry | Modern cryptographic defaults and explicit SAN binding |
| **Service Scope: Web** | Apache / System Admin module | Nginx HTTPS + Laravel Reverb WebSocket proxy | Unifies web panel and real-time event broadcasting under one TLS certificate |
| **Service Scope: Telephony** | Asterisk SIP TLS / WebRTC | FreeSWITCH SIP TLS (:5061) + WebRTC WSS (:7443) | Auto-generates `agent.pem`, `cafile.pem`, `wss.pem`, and `dtls-srtp.pem` |
| **Auto-Renewal** | Cron job executing `fwconsole certman --renew` | Scheduled Artisan command (`certificates:renew`) + Systemd timer | Integrated into Laravel Scheduler; logs structured audit trail |
| **UI Framework** | jQuery / Bootstrap 3 / PHP procedural | Livewire 4, DaisyUI 5, Tailwind CSS v4, Alpine.js | Modern reactive single-page experience; instant validation and status toasts |
| **Audit Logging** | Generic Asterisk/FreePBX log | Dedicated `certificate_audit_logs` + `security_audit_logs` | Traceability of who created, renewed, deployed, or revoked certificates |

---

## 2. Architectural Principles & System Invariants

To maintain enterprise reliability, carrier-grade uptime, and strict host security, the Certificate Manager adheres to six core architectural invariants:

> [!IMPORTANT]
> **Invariant 1: Least-Privilege Bounded Execution**
> The PHP web application runs as unprivileged `www-data`. It MUST NEVER invoke `sudo certbot`, `sudo systemctl`, `sudo nginx`, or `sudo cp` directly. All privileged filesystem modifications and service reloads MUST flow through a single dedicated helper script: `/usr/local/sbin/tallpbx-certificate`. The helper enforces strict regex argument validation and umask `027`.

> [!IMPORTANT]
> **Invariant 2: Modulus Verification Before Persistence**
> A certificate and private key MUST NEVER be saved to the database or written to disk until their cryptographic public key modulus values are proven to match (`openssl x509 -modulus` == `openssl rsa -modulus`). Mismatched keypairs are rejected with a clear operational error.

> [!IMPORTANT]
> **Invariant 3: Atomic Service Activation & Safe Rollback**
> Activating a certificate for Nginx HTTPS or FreeSWITCH MUST be atomic:
> 1. In Nginx: Symlinks in `/etc/tallpbx/certs/active/` are swapped atomically. The helper executes `nginx -t`. If validation fails, the symlink is immediately rolled back to the prior known-good certificate and Nginx is NOT reloaded.
> 2. In FreeSWITCH: PEM bundles (`agent.pem`, `cafile.pem`, `wss.pem`) are generated in a temporary staging path, validated, copied into `/etc/freeswitch/tls/` with ownership `freeswitch:freeswitch` (`0600`), and Sofia profiles reloaded.

> [!IMPORTANT]
> **Invariant 4: Fail-Safe Auto-Renewal**
> Automated renewal checks run daily via Laravel's task scheduler. Certificates with $\le 30$ days remaining until expiration are triggered for renewal. If a Let's Encrypt renewal attempt fails (e.g. rate limit, temporary DNS outage), the existing active certificate remains untouched and an administrative warning notification/toast is emitted. The system never leaves an empty or corrupt certificate on disk.

> [!IMPORTANT]
> **Invariant 5: Encrypted Credential Isolation**
> External API tokens (such as Cloudflare DNS tokens) MUST be encrypted at rest in the database using Laravel's application encryption key (`encrypted` cast). They are written to a temporary restricted file (`chmod 600`) only at the instant Certbot executes DNS-01 authentication and scrubbed immediately afterward.

> [!NOTE]
> **Invariant 6: Dual-Role Awareness**
> The Certificate Manager recognizes that a PBX server is simultaneously a public web application and a real-time telecommunications node. The user interface clearly displays whether each certificate is active for Web, Telephony, both, or neither. Renewing or replacing a certificate automatically propagates to all assigned services.

---

## 3. Navigation & Role-Based Access Control

### 3.1 Sidebar Navigation Placement
During architectural evaluation, two navigation options were considered:
- *Option A*: Placing it under `PBX` $\rightarrow$ `Advanced`. (Rejected: HTTPS certificates also govern the Nginx web server and Reverb WebSocket daemon, not just PBX telephony).
- *Option B*: Splitting into two screens—Web SSL under System Settings, PBX TLS under PBX Advanced. (Rejected: Fragmenting SSL causes operator confusion, duplicate renewals, and broken keyrings).
- *Option C (Selected)*: **Top-Level Navigation Item beside Security**.

The navigation item is positioned on the primary administrative sidebar right beside **Security** (`order: 39.5`), reflecting its role as core host and cryptographic infrastructure:
- **Key**: `certificates`
- **Label**: `admin.certificates` ("HTTPS & TLS Certificates")
- **Route**: `panel.certificates.index`
- **Permission**: `certificates.view`
- **Icon**: `heroicon-o-key` (or `heroicon-o-lock-closed`)
- **Guard**: `admin`
- **Sidebar Order**: `39.5` (between `Security` at `39` and `PBX` at `40`)

### 3.2 Granular Permissions Matrix
All routes, controllers, and Livewire actions are protected by TallPBX's `AdminAuthorize` middleware (`admin.can:<permission>`). Permissions are seeded via `AdminSeeder` into the Super Administrators group:

| Permission Name | Description | Capabilities |
| :--- | :--- | :--- |
| `certificates.view` | View Certificates | Access the Certificate Manager dashboard, view installed certificates, check expiration dates, and view audit logs. |
| `certificates.create` | Issue & Import Certificates | Request new Let's Encrypt certificates, import custom PEM certificates, generate self-signed certs, and add DNS credentials. |
| `certificates.deploy` | Assign & Deploy Services | Assign active certificates to Web (Nginx) and Telephony (FreeSWITCH), trigger service reloads. |
| `certificates.renew` | Manual Certificate Renewal | Trigger manual ACME renewal runs from the UI. |
| `certificates.delete` | Delete Certificates | Delete inactive certificates and remove stored DNS credentials. |

---

## 4. Dual-Service Deployment Engine

### 4.1 Web Infrastructure (Nginx HTTPS & Reverb WebSockets)

#### Current Architecture
TallPBX runs behind Nginx. HTTP traffic arrives on port 80, while HTTPS arrives on port 443. WebSocket connections for real-time frontend notifications and call state connect to `https://<domain>/app` and are reverse-proxied to internal Laravel Reverb (`127.0.0.1:8080`).

#### Certificate Deployment Design
To ensure zero web downtime and clean configuration management, Nginx references a dedicated SSL include file or standard symlink directory:
- Active certificate directory: `/etc/tallpbx/certs/active/`
  - `fullchain.pem` $\rightarrow$ Symlink to `/etc/tallpbx/certs/<cert_identifier>/fullchain.pem`
  - `privkey.pem` $\rightarrow$ Symlink to `/etc/tallpbx/certs/<cert_identifier>/privkey.pem`
- Nginx configuration (`/etc/nginx/sites-available/pbx`):
  ```nginx
  server {
      listen 443 ssl http2;
      listen [::]:443 ssl http2;
      server_name pbx.example.com;

      ssl_certificate     /etc/tallpbx/certs/active/fullchain.pem;
      ssl_certificate_key /etc/tallpbx/certs/active/privkey.pem;

      # Modern TLS ciphers & protocols
      ssl_protocols TLSv1.2 TLSv1.3;
      ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
      ssl_prefer_server_ciphers off;
      
      # Reverse proxy WebSocket connections for Laravel Reverb
      location /app {
          proxy_http_version 1.1;
          proxy_set_header Host $http_host;
          proxy_set_header Scheme $scheme;
          proxy_set_header Upgrade $http_upgrade;
          proxy_set_header Connection "Upgrade";
          proxy_pass http://127.0.0.1:8080;
      }
      # ... fastcgi_pass php-fpm ...
  }
  ```

#### Web Activation Workflow
1. When an operator assigns Certificate #X as the Default Web Certificate:
2. The bounded helper updates the symlinks in `/etc/tallpbx/certs/active/` to point to Certificate #X.
3. The helper executes `nginx -t` to test syntax and cryptographic readability.
4. If valid, executes `systemctl reload nginx`.
5. Updates `.env` to set `SESSION_SECURE_COOKIE=true` if not already set.
6. Updates database flag: `is_default_web = true` on Certificate #X and `false` on all others.

---

### 4.2 Telephony Infrastructure (FreeSWITCH SIP TLS :5061 & WebRTC WSS :7443)

#### FreeSWITCH TLS Directory Structure
FreeSWITCH looks for TLS certificates in its configured `tls-cert-dir` (default: `/etc/freeswitch/tls/`). FreeSWITCH requires specific combined PEM structures:
- `/etc/freeswitch/tls/agent.pem`: Combined certificate and private key (`cat fullchain.pem privkey.pem > agent.pem`).
- `/etc/freeswitch/tls/cafile.pem`: Intermediate and root CA chain (`cat chain.pem > cafile.pem`).
- `/etc/freeswitch/tls/wss.pem`: Dedicated WebRTC certificate/key bundle (symlinked or copied from `agent.pem`).
- `/etc/freeswitch/tls/dtls-srtp.pem`: DTLS-SRTP media encryption keypair (symlinked or copied from `agent.pem`).

#### Permissions & Ownership
- Owner: `freeswitch:freeswitch`
- Directory permissions: `0750`
- PEM file permissions: `0600`

#### Sofia Profile Integration
TallPBX serves FreeSWITCH configuration dynamically via `mod_xml_curl` through `SofiaConfigXmlBuilder.php`. SIP Profiles (`internal`, `external`) contain settings configured via `DefaultSipProfilesProvisioner.php`:
```xml
<param name="tls" value="true"/>
<param name="tls-sip-port" value="5061"/>
<param name="tls-cert-dir" value="/etc/freeswitch/tls"/>
<param name="tls-bind-params" value="transport=tls"/>
<param name="tls-version" value="tlsv1.2,tlsv1.3"/>
<param name="wss-binding" value=":7443"/>
```

#### Telephony Activation Workflow
1. When an operator assigns Certificate #Y as the Default Telephony Certificate:
2. The bounded helper generates the required combined bundles (`agent.pem`, `cafile.pem`, `wss.pem`, `dtls-srtp.pem`) in `/etc/freeswitch/tls/`.
3. The helper executes `chown -R freeswitch:freeswitch /etc/freeswitch/tls && chmod 600 /etc/freeswitch/tls/*.pem`.
4. Sofia profiles are reloaded via `fs_cli -x "sofia profile internal restart"` and `fs_cli -x "sofia profile external restart"` (or via `FreeSwitchControlService` through ESL).
5. Updates database flag: `is_default_telephony = true` on Certificate #Y and `false` on all others.

---

## 5. Host Security & Bounded Helper Architecture

In strict compliance with TallPBX's privileged command policy documented in `AGENTS.md`, root-level operations are strictly bounded.

```
┌────────────────────────────────┐
│   Web Panel / Livewire 4       │ (Runs as www-data)
└───────────────┬────────────────┘
                │ Symfony Process discrete arguments
                ▼
┌────────────────────────────────┐
│   /usr/local/sbin/             │ (Owned by root:www-data, mode 0750)
│   tallpbx-certificate          │ Sudoers: NOPASSWD for www-data
└───────┬──────────────┬─────────┘
        │              │
        ▼              ▼
┌──────────────┐ ┌───────────────────────────┐
│ Certbot /    │ │ Atomic File Deployment    │
│ OpenSSL      │ │ /etc/tallpbx/certs/       │
└──────────────┘ │ /etc/nginx/               │
                 │ /etc/freeswitch/tls/      │
                 └───────────────────────────┘
```

### 5.1 Privileged Helper Script: `/usr/local/sbin/tallpbx-certificate`
Location: `/usr/local/sbin/tallpbx-certificate` (Source: `scripts/resources/tallpbx-certificate`)  
Owner: `root:www-data`, Mode: `0750`

#### Supported Actions & Parameter Grammar
The helper script accepts only predetermined actions with strict regex validation:
1. `version`: Emits `tallpbx-cert-helper-version: 1`.
2. `issue-letsencrypt-http <domain> <email> [staging]`: Executes Certbot with `--webroot` pointing to `/var/www/html` or `--nginx`.
3. `issue-letsencrypt-dns <domain> <email> <creds_path> [wildcard] [staging]`: Executes Certbot using `certbot-dns-cloudflare`.
4. `renew-letsencrypt <domain>`: Triggers renewal for a specific certificate name.
5. `import-custom <cert_identifier> <pending_cert_path> <pending_key_path> [pending_chain_path]`: Validates modulus, moves files from PHP upload staging to `/etc/tallpbx/certs/<cert_identifier>/`, sets ownership `root:www-data` (`0640` certs, `0600` keys).
6. `generate-self-signed <cert_identifier> <common_name> <days> <san_csv>`: Uses `openssl req -x509` with modern SAN extensions.
7. `deploy-web <cert_identifier>`: Atomically updates `/etc/tallpbx/certs/active/` symlinks, runs `nginx -t`, reloads Nginx. Rolls back on error.
8. `deploy-telephony <cert_identifier>`: Builds combined `agent.pem`, `cafile.pem`, `wss.pem` in `/etc/freeswitch/tls/`, chowns to `freeswitch:freeswitch`, restarts Sofia profile.
9. `deploy-all <cert_identifier>`: Executes both `deploy-web` and `deploy-telephony`.
10. `delete <cert_identifier>`: Safely removes certificate files for non-active certificates. Refuses deletion if active on Web or Telephony.
11. `status`: Outputs JSON with active web/telephony certificate identifiers and disk paths.

### 5.2 Sudoers Drop-in: `/etc/sudoers.d/tallpbx-certificate`
```sudoers
# TallPBX Bounded Certificate Management Helper
# Enables unprivileged www-data to execute the bounded certificate manager helper
www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/tallpbx-certificate
```

### 5.3 PHP Executor & Test-Isolation Guard
The helper is invoked via `Modules\Certificates\Services\CertificateExecutor`, implementing `CertificateExecutorInterface`:
```php
namespace Modules\Certificates\Services;

use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class CertificateExecutor implements CertificateExecutorInterface
{
    private string $helperPath;

    public function __construct(?string $helperPath = null)
    {
        $this->helperPath = $helperPath ?? '/usr/local/sbin/tallpbx-certificate';
    }

    protected function runCommand(array $arguments): array
    {
        // Test guard: Never execute the real system helper during automated test runs
        if (app()->runningUnitTests() && str_starts_with($this->helperPath, '/usr/local/sbin/')) {
            Log::info('Mocked certificate executor command in test suite', ['args' => $arguments]);
            return ['success' => true, 'output' => '', 'exit_code' => 0];
        }

        $prefix = (posix_geteuid() === 0 || !str_starts_with($this->helperPath, '/usr/local/sbin/'))
            ? [$this->helperPath]
            : ['sudo', '-n', $this->helperPath];

        $process = new Process(array_merge($prefix, $arguments));
        $process->setTimeout(180); // Certbot DNS propagation may take up to 2 minutes
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }
}
```

---

## 6. Challenge Methods & Certificate Types

### 6.1 Let's Encrypt (ACME v2)

#### 1. HTTP-01 Challenge
- **Use Case**: Single or multiple explicit domains (e.g. `pbx.example.com`, `sip.example.com`).
- **Mechanism**: Certbot places a token under `/var/www/html/.well-known/acme-challenge/`. Let's Encrypt verifies over HTTP (port 80).
- **Prerequisites**: Inbound port 80 open (already standard in TallPBX firewall ruleset) and public DNS A/AAAA record pointing to PBX IP.
- **Limitation**: Cannot issue wildcard certificates (`*.domain.com`).

#### 2. DNS-01 Challenge (Cloudflare)
- **Use Case**: Wildcard certificates (`*.example.com` + `example.com`), multi-tenant domains, or PBX servers behind restrictive firewalls where port 80 cannot be opened to the public.
- **Mechanism**: Certbot uses `certbot-dns-cloudflare` to authenticate with Cloudflare's API, create a temporary TXT record `_acme-challenge.<domain>`, wait for propagation, and complete validation.
- **Credentials Handling**: Cloudflare API Token (scoped with `Zone:DNS:Edit` permission). The token is saved in the database with Eloquent encryption. During issuance, the helper writes `/etc/letsencrypt/cloudflare.ini` with mode `0600`, executes Certbot, and cleans up.

#### 3. Staging vs Production
- The UI includes a "Use Let's Encrypt Staging Server" toggle.
- When enabled, appends `--staging` (`--test-cert`).
- Protects administrators from triggering Let's Encrypt's strict production rate limits (5 failures per account per hour, 50 duplicate certs per week) while testing network and DNS configuration.

---

### 6.2 Custom PEM Certificate Import
- **Use Case**: Commercial SSL certificates (DigiCert, GoDaddy, Comodo, Sectigo, Enterprise Internal CAs).
- **Inputs**:
  1. **Certificate (PEM)**: Begins with `-----BEGIN CERTIFICATE-----`.
  2. **Private Key (PEM)**: Begins with `-----BEGIN PRIVATE KEY-----` or `-----BEGIN RSA PRIVATE KEY-----`.
  3. **CA Bundle / Intermediate Chain (PEM)**: Optional intermediate certificates.
- **Client & Server Pre-Validation Pipeline**:
  ```php
  // 1. Parse certificate details
  $certInfo = openssl_x509_parse($certPem);
  if ($certInfo === false) {
      throw new InvalidCertificateException('Invalid X.509 PEM certificate.');
  }

  // 2. Validate private key syntax
  $keyRes = openssl_pkey_get_private($keyPem, $passphrase ?? '');
  if ($keyRes === false) {
      throw new InvalidCertificateException('Invalid or passphrase-protected private key.');
  }

  // 3. Cryptographic Modulus Match Verification
  $certDetails = openssl_pkey_get_details(openssl_pkey_get_public($certPem));
  $keyDetails = openssl_pkey_get_details($keyRes);
  if ($certDetails['key'] !== $keyDetails['key']) {
      throw new MismatchedKeypairException('Private key modulus does not match the certificate public key.');
  }
  ```

---

### 6.3 Self-Signed CA & Certificate Generator
- **Use Case**: Lab environments, dev instances, testing SIP TLS before official domain registration.
- **Parameters**:
  - Common Name (CN): e.g. `pbx.local` or Server Public IP.
  - Subject Alternative Names (SANs): e.g. `IP:192.168.1.100`, `DNS:pbx.local`.
  - Organization, Unit, Country (2-letter code), State/Province.
  - Validity Duration: 365, 730, or 1825 days.
  - Cryptographic Algorithm: RSA 2048, RSA 4096, or ECDSA (prime256v1).
- **Execution**: Generated via OpenSSL with subjectAltName extension; stored and instantly ready for deployment to Web and Telephony.

---

## 7. Database Schema & Eloquent Models

### 7.1 Migrations

#### Migration 1: `create_certificates_table`
```php
Schema::create('certificates', function (Blueprint $table) {
    $table->id();
    $table->string('name')->comment('Human-readable display name');
    $table->string('type', 30)->comment('lets_encrypt, custom, self_signed');
    $table->string('common_name')->comment('Primary FQDN or IP');
    $table->json('san_domains')->nullable()->comment('Array of Subject Alternative Names');
    $table->string('issuer')->nullable()->comment('Certificate Authority issuer name');
    $table->timestamp('valid_from')->nullable();
    $table->timestamp('valid_to')->nullable();
    $table->string('serial_number')->nullable();
    $table->string('fingerprint_sha256', 64)->nullable();
    
    // Service assignment flags
    $table->boolean('is_default_web')->default(false)->index();
    $table->boolean('is_default_telephony')->default(false)->index();
    
    // ACME details
    $table->string('challenge_type', 20)->nullable()->comment('http-01, dns-01');
    $table->foreignId('dns_credential_id')->nullable()->constrained('certificate_dns_credentials')->nullOnDelete();
    $table->boolean('auto_renew')->default(true);
    $table->boolean('is_staging')->default(false);
    $table->timestamp('last_renewed_at')->nullable();
    $table->text('last_renew_error')->nullable();
    
    // Storage paths (relative to /etc/tallpbx/certs/<identifier>/)
    $table->string('storage_identifier')->unique()->comment('Filesystem folder name');
    
    $table->timestamps();
    
    $table->index(['type', 'valid_to']);
});
```

#### Migration 2: `create_certificate_dns_credentials_table`
```php
Schema::create('certificate_dns_credentials', function (Blueprint $table) {
    $table->id();
    $table->string('name')->comment('e.g. Cloudflare Production');
    $table->string('provider', 30)->default('cloudflare');
    $table->text('credentials')->comment('Encrypted JSON payload containing API tokens');
    $table->timestamps();
});
```

#### Migration 3: `create_certificate_audit_logs_table`
```php
Schema::create('certificate_audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete();
    $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
    $table->string('action', 50)->comment('issued, renewed, imported, generated, deployed_web, deployed_telephony, deleted, failed');
    $table->string('status', 20)->comment('success, error, warning');
    $table->text('message');
    $table->json('details')->nullable();
    $table->timestamps();
    
    $table->index(['certificate_id', 'created_at']);
});
```

### 7.2 Eloquent Models
- `Modules\Certificates\Models\Certificate`: Has helper attributes `is_expired`, `days_until_expiration`, `status_badge_color`. Casts `san_domains` to array, `valid_from`/`valid_to`/`last_renewed_at` to datetime.
- `Modules\Certificates\Models\CertificateDnsCredential`: Casts `credentials` to `encrypted:array`.
- `Modules\Certificates\Models\CertificateAuditLog`: Relates to `Admin` and `Certificate`.

---

## 8. Livewire 4 & DaisyUI 5 User Interface

The Certificate Manager interface (`Modules\Certificates\Livewire\CertificateManager`) is designed around a single-page responsive tab layout, matching the design aesthetic of the Security Center.

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  HTTPS & TLS Certificates                                      (order: 39.5)     │
│  Manage SSL/TLS encryption for web portals, SIP signaling, and WebRTC telephony.  │
├──────────────────────────────────────────────────────────────────────────────────┤
│  [ Assigned Services Overview ]                                                  │
│  ┌───────────────────────────────┐   ┌───────────────────────────────┐           │
│  │ 🌐 Web Portal (Nginx HTTPS)    │   │ 📞 Telephony (FreeSWITCH SIP)  │           │
│  │ Active: pbx.example.com       │   │ Active: pbx.example.com       │           │
│  │ Issuer: Let's Encrypt (48d)   │   │ Issuer: Let's Encrypt (48d)   │           │
│  │ Status: [ Active / Valid ]    │   │ Status: [ Active / Valid ]    │           │
│  └───────────────────────────────┘   └───────────────────────────────┘           │
├──────────────────────────────────────────────────────────────────────────────────┤
│  [ Inventory ] [ Let's Encrypt ] [ Custom Import ] [ Self-Signed ] [ DNS Vault ] │
├──────────────────────────────────────────────────────────────────────────────────┤
│  Certificate Inventory Table                                                     │
│  Name            Domains         Type       Expires In    Services    Actions    │
│  ──────────────────────────────────────────────────────────────────────────────  │
│  Primary PBX     pbx.example.com ACME/HTTP  48 days       [Web] [SIP] [Deploy].. │
│  Wildcard Test   *.dev.local     Self-Sign  312 days      [None]      [Deploy].. │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 8.1 Administrative Tabs
1. **Inventory & Services Tab (`activeTab = 'inventory'`)**:
   - **Service Overview Cards**: Highlighting current Web Active and Telephony Active certificates, issuer, validity period, and quick "Switch Certificate" modal.
   - **Data Table**: Listing all certificates with:
     - Name & Common Name
     - SAN Count / Domains Tooltip
     - Type Badge (Let's Encrypt, Custom, Self-Signed)
     - Expiration countdown badge:
       - Green: $>30$ days remaining
       - Yellow warning: $\le 30$ days remaining
       - Red error: Expired
     - Assigned Services badges (`Web HTTPS`, `SIP TLS / WSS`)
     - Actions dropdown:
       - **Assign to Services** (opens modal to toggle Web / Telephony)
       - **Renew Now** (for Let's Encrypt)
       - **Download PEM** (Certificate, Key, Chain)
       - **View Details** (Full X.509 breakdown)
       - **Delete** (disabled if actively serving Web or Telephony)
2. **Let's Encrypt Tab (`activeTab = 'letsencrypt'`)**:
   - Primary Domain (FQDN input with automated hostname suggestion).
   - Additional SAN Domains (comma-separated or dynamic tag pills).
   - Administrator Notification Email.
   - Challenge Type radio selector:
     - **HTTP-01**: Displays requirement note: "Requires port 80 to be open and pointed to this server."
     - **DNS-01 (Cloudflare)**: Reveals Cloudflare API Token dropdown (select from DNS Vault or enter new token). Enables wildcard checkbox (`*.domain.com`).
   - Testing Mode toggle: "Use Staging Environment (Recommended for first issuance to avoid rate limits)".
   - Auto-Deploy checkboxes: "Deploy to Web upon issuance", "Deploy to Telephony upon issuance".
   - Action Button: `Issue Certificate` with Livewire `wire:loading` state and disabled form inputs during issuance.
3. **Custom Import Tab (`activeTab = 'import'`)**:
   - Certificate Name input.
   - Certificate PEM (Paste textarea or file upload).
   - Private Key PEM (Paste textarea or file upload).
   - Intermediate CA Chain PEM (Paste textarea or file upload - optional).
   - Live Modulus Verification indicator: Reactive Alpine.js/Livewire validator showing green checkmark when key matches cert, or error message if mismatched.
   - Auto-Deploy checkboxes.
   - Action Button: `Import & Save Certificate`.
4. **Self-Signed Tab (`activeTab = 'selfsigned'`)**:
   - Certificate Name & Common Name.
   - SAN list (e.g. `IP:192.168.1.50,DNS:tallpbx.local`).
   - Organization & Country Code.
   - Expiration Period (365, 730, 1825 days).
   - Key Type (RSA 2048, RSA 4096, ECDSA P-256).
   - Action Button: `Generate Self-Signed Certificate`.
5. **DNS Vault Tab (`activeTab = 'dnsvault'`)**:
   - Manage stored Cloudflare API credentials for seamless auto-renewal.
   - Add/edit credentials, test token connectivity, delete unused tokens.
6. **Audit Logs Tab (`activeTab = 'logs'`)**:
   - Historical record of certificate actions, renewals, deployments, and error stacktraces.

---

## 9. Automated Renewal, Monitoring & CLI Commands

### 9.1 Artisan Commands
The module provides three first-class Artisan CLI commands:

#### 1. `certificates:renew`
- **Purpose**: Sweeps all Let's Encrypt certificates with `auto_renew = true`.
- **Options**: `--force` (renew regardless of days remaining), `--dry-run` (simulate without issuing).
- **Behavior**:
  - Queries certificates where `type = 'lets_encrypt'` and `valid_to <= now()->addDays(30)`.
  - Invokes `tallpbx-certificate renew-letsencrypt <identifier>`.
  - If successful: updates `valid_from`, `valid_to`, `last_renewed_at`, re-deploys to any assigned services (Nginx / FreeSWITCH), logs audit entry.
  - If failed: logs error to `last_renew_error`, creates error notification.

#### 2. `certificates:deploy {id}`
- **Purpose**: Deploy a certificate to services via CLI.
- **Options**: `--service=web`, `--service=telephony`, `--service=all`.

#### 3. `certificates:status`
- **Purpose**: Output terminal table showing all certificates, expiration status, and service bindings.

### 9.2 Scheduled Tasks
In `routes/console.php`:
```php
// Run daily certificate renewal check at 03:30 AM
Schedule::command('certificates:renew')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/certificates-renewal.log'));
```

### 9.3 Expiration Warning Notifications
When a certificate is within 30, 14, and 7 days of expiration and either cannot auto-renew (custom/self-signed) or has experienced consecutive ACME failures:
- An administrative alert is raised in the TallPBX notification bell (`panel.notifications`).
- A persistent warning banner is displayed across the top of the Certificate Manager dashboard.

---

## 10. Testing Strategy & Verification Gates

The module will be implemented with rigorous test coverage in accordance with `AGENTS.md` and `testing-best-practices`.

```
┌────────────────────────────────────────────────────────┐
│               Testing & Verification Suite             │
├──────────────────────────┬─────────────────────────────┤
│ 1. Unit Tests            │ • OpenSSL Certificate Parser│
│                          │ • Modulus Matcher Engine    │
│                          │ • DNS Credential Encryption │
├──────────────────────────┼─────────────────────────────┤
│ 2. Feature Tests         │ • Permission Boundaries     │
│                          │ • Livewire Component State  │
│                          │ • Deployment Mocking        │
│                          │ • ACME Renewal Scheduling   │
├──────────────────────────┼─────────────────────────────┤
│ 3. Browser Tests (Pest)  │ • End-to-End Tab Switching  │
│                          │ • Custom PEM Import UI Flow │
│                          │ • Service Assignment Modal  │
└──────────────────────────┴─────────────────────────────┘
```

### 10.1 Unit Tests (`app-modules/certificates/tests/Unit/`)
- `CertificateParserTest`: Verifies parsing of valid X.509 certs, invalid strings, expired certs, extraction of SANs and Common Name.
- `ModulusMatcherTest`: Verifies that matching RSA keypairs pass and mismatched keypairs fail with typed exceptions.
- `DnsCredentialsEncryptionTest`: Verifies credentials are encrypted at rest and never exposed in logs or serializations.

### 10.2 Feature Tests (`app-modules/certificates/tests/Feature/`)
- `CertificatePermissionsTest`: Confirms non-permitted admins receive 403 Forbidden on all certificate routes.
- `CertificateManagerLivewireTest`: Tests all Livewire component lifecycle events, tab changes, form submissions, and operational toasts.
- `CertificateDeploymentServiceTest`: Tests service assignment logic with a mocked `CertificateExecutorInterface`, verifying that Nginx and FreeSWITCH deployment hooks fire with correct parameters.
- `CertificateRenewCommandTest`: Tests the renewal Artisan command with expired and fresh certificates, verifying auto-deployment upon renewal.

### 10.3 Playwright Browser Tests (`tests/Browser/CertificateManagerBrowserTest.php`)
- Visits `/panel/certificates`.
- Verifies tab navigation (Inventory, Let's Encrypt, Custom Import, Self-Signed, DNS Vault).
- Tests modal opening and closing for service assignment.
- Verifies DaisyUI tooltips and alert banners.

---

## 11. Phased Implementation Roadmap

### Phase 1: Module Scaffolding, Database Schema & Bounded Helper
- [x] **Task 1.1**: Scaffold module `app-modules/certificates/` with `module.json`, `composer.json`, and register in root `composer.json`.
- [x] **Task 1.2**: Implement database migrations (`certificates`, `certificate_dns_credentials`, `certificate_audit_logs`).
- [x] **Task 1.3**: Create Eloquent models (`Certificate`, `CertificateDnsCredential`, `CertificateAuditLog`).
- [x] **Task 1.4**: Author bounded root helper script `scripts/resources/tallpbx-certificate` and sudoers drop-in `scripts/resources/tallpbx-certificate.sudoers`, plus local module copies in `app-modules/certificates/scripts/`.
- [x] **Task 1.5**: Implement `CertificateExecutorInterface` and `CertificateExecutor` with test-suite isolation guard.

### Phase 2: Core Cryptographic Services & Service Deployer
- [x] **Task 2.1**: Implement `CertificateParserService` (OpenSSL X.509 parsing, SAN extraction, validity date calculation).
- [x] **Task 2.2**: Implement `CertificateValidatorService` (Cryptographic modulus matching, private key passphrase handling).
- [x] **Task 2.3**: Implement `CertificateDeploymentService` (Nginx symlink swap & reload, FreeSWITCH PEM bundling & Sofia reload).
- [x] **Task 2.4**: Implement `SelfSignedGeneratorService` (RSA/ECDSA key generation and CSR/cert emission).
- [x] **Task 2.5**: Implement `LetsEncryptAcmeService` (Certbot driver for HTTP-01 and DNS-01 Cloudflare).

### Phase 3: Service Provider, Navigation & Permissions
- [x] **Task 3.1**: Create `Modules\Certificates\Providers\ModuleServiceProvider` extending `App\Support\ModuleServiceProvider`.
- [x] **Task 3.2**: Register navigation item at `order: 39.5` (`admin.certificates`, route `panel.certificates.index`, icon `heroicon-o-key`).
- [x] **Task 3.3**: Register permissions (`certificates.view`, `certificates.create`, `certificates.deploy`, `certificates.renew`, `certificates.delete`).
- [x] **Task 3.4**: Add translation strings in `lang/en/admin.php` (and Spanish/French counterparts).
- [x] **Task 3.5**: Update `AdminSeeder` to grant Super Administrators all certificate permissions.

### Phase 4: Livewire 4 Administrative User Interface
- [x] **Task 4.1**: Build `Modules\Certificates\Livewire\CertificateManager` component class.
- [x] **Task 4.2**: Author Blade template `resources/views/certificate-manager.blade.php` with DaisyUI 5 tabs.
- [x] **Task 4.3**: Implement Service Overview Cards & Certificate Inventory Table with expiration badges.
- [x] **Task 4.4**: Implement Let's Encrypt ACME issuance form (HTTP-01 & DNS-01 Cloudflare).
- [x] **Task 4.5**: Implement Custom Certificate PEM import form with live modulus feedback.
- [x] **Task 4.6**: Implement Self-Signed generator form.
- [x] **Task 4.7**: Implement DNS Credentials Vault manager modal.
- [x] **Task 4.8**: Implement Service Assignment modal (Web / Telephony toggles).

### Phase 5: Automation, Scheduler & CLI Commands
- [x] **Task 5.1**: Implement Artisan commands `certificates:renew`, `certificates:deploy`, `certificates:status`.
- [x] **Task 5.2**: Register `certificates:renew` in `routes/console.php` daily schedule.
- [x] **Task 5.3**: Add audit logging listeners and expiration notification triggers.
- [x] **Task 5.4**: Add installer step in `scripts/install.sh` to install `/usr/local/sbin/tallpbx-certificate` and sudoers drop-in.

### Phase 6: Comprehensive Test Suite & Documentation Verification
- [x] **Task 6.1**: Author Unit tests for parser, modulus matcher, and credential encryption.
- [x] **Task 6.2**: Author Feature tests for Livewire UI, permissions, and deployment workflows.
- [x] **Task 6.3**: Author Playwright browser test suite (`tests/Browser/CertificateManagerBrowserTest.php`).
- [x] **Task 6.4**: Run complete test verification:
  ```bash
  php artisan optimize:clear
  php artisan test --compact --parallel
  ./vendor/bin/pest tests/Browser
  ```
- [x] **Task 6.5**: Update `CHANGELOG.md` under `[Unreleased]` and update `docs/operations.md` with Certificate Management operational runbooks.


