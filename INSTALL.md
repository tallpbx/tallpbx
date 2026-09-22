# Installation Guide

This guide explains how to set up TallPBX on a new Debian 13 server. The main
install is one command; the sections after it cover optional configuration,
maintenance, and tuning.

TallPBX is a web-managed phone system that runs on your own server. After
installing, you'll have a working web panel ready for phones, extensions, call
routing, voicemail, and recordings — all manageable from your browser.

## 1. Create the Virtual Machine

- **OS Type**: Linux, Debian 13 (64-bit)
- **Hardware Requirements**:

| | Minimum (Production) | Recommended (Development) |
|---|---|---|
| CPU | 1 vCPU (2+ recommended) | 4 vCPUs |
| Storage | 25 GB (40 GB+ for recordings) | 40 GB |
| RAM | 1 GB | 4 GB |
| Swap | 2 GB | 2 GB |

Development specs are higher because building frontend assets and running
tests need more CPU and memory.

## 2. Attach the Installer

Download the Debian 13 amd64 netinst ISO and attach it as the VM's optical drive.

## 3. Install Debian 13

Boot the VM and proceed through the Debian installer UI:

1. Select language, location, and keyboard layout
2. Configure network (DHCP is fine for initial install)
3. Set hostname and domain
4. Set root password
5. Create a standard user account
6. Partition disk (guided - use entire disk is simplest)
7. **Software selection**: check only:
   - SSH server
   - Standard system utilities
8. Install GRUB boot loader to the master boot record

After installation completes, the VM will reboot. Log in as root.

### Configure a Swapfile (When Total Swap Is Below 2 GB)

Check the current swap:

```bash
swapon --show
```

If less than 2 GB is active, create a 2 GB swapfile:

```bash
swapoff /swapfile
rm -f /swapfile
dd if=/dev/zero of=/swapfile count=2048 bs=1MiB
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
```

Make the swapfile persistent across reboots:

```bash
nano /etc/fstab
```

Add the following line to the bottom of `/etc/fstab` if it is not already present:

```text
/swapfile swap swap defaults 0 0
```

Verify that the swapfile is active:

```bash
swapon --show
free -h
```

## 4. Configure a Static IP Address (Optional)

If you need a static IP address, edit the network interfaces file:

```bash
nano /etc/network/interfaces
```

Replace the DHCP line for your interface with:

```
iface enp0s3 inet static
address 192.168.1.76
netmask 255.255.255.0
gateway 192.168.1.254
dns-nameservers 192.168.1.254 8.8.8.8 8.8.4.4
```

Restart networking and verify:

```bash
systemctl restart networking
ip addr show enp0s3
```

## 5. Run the Install Script

Install TallPBX with a single command:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

The command downloads a small bootstrap script and runs it. The bootstrap
prepares the TallPBX source code in `/var/www/tallpbx`, then starts the main
installer, which asks a few setup questions and installs everything.

Re-running the command is safe — the installer can run repeatedly without
affecting existing data, and it updates TallPBX in place. See
[Upgrading TallPBX](#upgrading-tallpbx) for details.
Use the same `--ref` value every time: without it, the re-run switches the
working copy to `main`.

To customize the installation, add options after `-s --`:

```bash
# Pin the stable 1.1 release branch instead of the default main:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --ref 1.1

# Skip the demo-data question (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo

# Install without demo data or development tooling (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo --no-development
```

### First Administrator Setup

The installer asks how to create the first administrator:

> [!TIP]
> **Option 1 is recommended for most users.** It's the simplest path — you'll
> have a working admin account as soon as the installer finishes.

1. **Create during installation (default)** — enter the administrator email and
   password at the prompt; TallPBX creates the account before finishing.
2. **Browser setup with a one-time activation code** — the installer prints a
   code (also saved to `/var/log/pbx-install.log`). Open
   `http://SERVER-IP/panel/setup`, enter the code, and choose the email and
   password there.
3. **Browser setup without a code — trusted networks only** — the first person
   to open `http://SERVER-IP/panel/setup` becomes the administrator. Do not use
   this on an internet-reachable server.

Re-runs never replace an existing administrator. There is no default
administrator password.

#### Changing or Resetting the Administrator Password

If you forgot your password or need to change it from the Linux command line:

**Using Artisan (recommended):**

Run the password command interactively (it will prompt for the email and new password):

```bash
cd /var/www/tallpbx
php artisan admin:password
```

Or provide the email and new password directly:

```bash
cd /var/www/tallpbx
php artisan admin:password admin@example.com --password="YourNewPassword"
```

**Directly via MariaDB (if Laravel cannot boot):**

If Laravel's bootstrap cannot run (for example, due to a broken cache or syntax error), update the password directly in MariaDB using PHP's built-in password hashing:

```bash
mariadb tallpbx -e "UPDATE admins SET password = '$(php -r 'echo password_hash("YourNewPassword", PASSWORD_BCRYPT);')' WHERE email = 'admin@example.com';"
```

> [!TIP]
> If you do not remember the administrator's email address, run:
> ```bash
> mariadb tallpbx -e "SELECT id, name, email FROM admins;"
> ```

### Demo Data and Development Tooling

The installer asks about two optional extras. Demo data adds sample tenants,
users, and callable extensions for evaluation. Development tooling adds test
and coding utilities and is only for servers where TallPBX itself will be
developed — choose **No** for a normal PBX server.

When it finishes, the installer prints the panel address. Open it in a browser
and sign in with your administrator account. Development tooling also switches
TallPBX to Laravel's local development mode; without it, production mode is
used automatically.

### Outgoing Mail (Email Connector)

To enable password resets, voicemail notifications, backup reports, and system
alerts, sign in to the panel and open **Email Connector**. Standard
username/password SMTP (including Gmail App Passwords) and OAuth 2.0 (Google,
Microsoft 365, or a custom provider) are supported.

See `docs/operations.md` for service management (restarting FreeSWITCH, the
queue worker, Redis, and the other background services), health checks, and
test commands.

### Running the Installer Again

It is safe to run the installer again at any time. It keeps existing call data,
users, settings, and recordings, reuses saved passwords and choices, keeps the
application key, applies only missing database updates, and renews the
TallPBX-to-FreeSWITCH connection and media permissions.

Demo data is added only when selected and never removed by later runs. If you
switch FreeSWITCH between packages and a source build, the installer removes
the old program first; that does not touch your database or recordings.

### Redis Cache And Session Storage

Redis is TallPBX's in-memory storage for sessions, cache, and dynamic
intrusion bans, and new installs configure it automatically. Keep
`redis-server` running in production: if Redis is temporarily down the PBX
keeps working, but dynamic login and ban counters pause until it returns — the
kernel firewall and manual bans remain active. After changing cache or session
values in `.env`, run `php artisan optimize:clear && php artisan optimize`.

### FreeSWITCH Session Rate

Fresh installs cap new calls at FreeSWITCH's default of `60` sessions per
second. This is a safety limit suitable for most servers; change it only after
load testing shows that this specific limit is holding back a server with
enough CPU, memory, and network capacity.

### PHP-FPM Worker Sizing

PHP-FPM serves the web panel. Debian's defaults suit small systems; adjust only
when monitoring shows worker exhaustion or slow bursts:

| Server size | Suggested `pm.max_children` | Notes |
| --- | --- | --- |
| Minimum, 1 GB RAM | Keep Debian defaults | Change only after monitoring. |
| Small, 2 GB RAM | `6` | A reasonable starting point. |
| Standard, 4 GB RAM | `12` | Recommended starting point. |
| Larger, 8 GB RAM | `24` | Confirm memory is still available. |
| High-volume | Measure first | Tune from real traffic data. |

Set `pm = static` alongside the value and leave `pm.max_requests` at `0`.
Always leave memory for FreeSWITCH, MariaDB, Redis, Nginx, recordings, and the
operating system, and check `/var/log/php8.5-fpm.log` for worker-limit warnings.

### File Permissions

The installer applies correct ownership and permissions automatically. After a
manual deployment, Composer update, or permission error, restore them with:

```bash
cd /var/www/tallpbx
sudo php artisan permissions:repair --scope=full
```

Do not run recursive `chown` or `chmod` commands over the application
directory; they can make the source writable by the web server or break helper
scripts. If Laravel itself will not boot, use the emergency fallback
`sudo bash scripts/repair-application-permissions.sh`.

Recordings, faxes, and voicemail under `/var/lib/tallpbx/media` are set up
automatically too. If you use a custom `TALLPBX_MEDIA_ROOT`, add that path to
the `ReadWritePaths` line of the `freeswitch-listener` and `tallpbx-queue`
systemd units, then run
`systemctl daemon-reload && systemctl restart freeswitch-listener tallpbx-queue`.

### Firewall (nftables)

The installer configures the kernel firewall for these services:

| Service | Port / Protocol |
| --- | --- |
| SSH | `22/tcp` |
| HTTP / HTTPS | `80/tcp`, `443/tcp` |
| SIP internal | `5060/udp,tcp` |
| SIP over TLS | `5061/tcp` |
| SIP external (carriers) | `5080/udp,tcp` |
| RTP media | `16384-32768/udp` |

Intrusion defense runs automatically: SIP authentication scanning, web login
rate limits, and dynamic kernel bans. Manage it from the panel's Security
pages, or from the terminal:

```bash
php artisan security:status              # firewall state, sets, and bans
php artisan security:unban <IP_ADDRESS>  # lift a ban immediately
sudo nft flush ruleset                   # emergency: clear all rules if locked out
```

Privileged firewall changes run through the bounded helper
`/usr/local/sbin/tallpbx-security` (mode `0750 root:www-data`); the web user
never receives general sudo access.

### Browser Testing (Dusk)

Browser tests are optional developer tooling. **Do not use the web panel while
they run**: `bash scripts/dusk.sh` temporarily swaps in the test environment,
and concurrent panel use interferes with the run. Documentation screenshots
are refreshed on demand only (`DUSK_CAPTURE_DOCS=1 bash scripts/dusk.sh`); see
`AGENTS.md` for the full workflow.

## 6. FreeSWITCH Installation Choice

The installer asks whether to install FreeSWITCH from packages or from source
code, and remembers the choice. Switching later removes the old FreeSWITCH
program first; your database and recordings are untouched.

| | Packages (recommended) | Source build |
|---|---|---|
| Speed | Fast (pre-built binaries) | Slow (compiles on your server) |
| Updates | `apt upgrade` | Rebuild from source |
| Token required | Yes (free SignalWire PAT) | No |
| Best for | Most servers | Custom FreeSWITCH development |

**Package install (recommended for most servers).** Faster to install and
update, and installs the modules TallPBX needs for phones, voicemail,
recordings, queues, music, and dynamic configuration. It requires a free
SignalWire Personal Access Token — create one at https://signalwire.com under
**Personal Access Tokens** (repo access). The installer configures FreeSWITCH
to fetch its phone directory and call routing from the application. If you
change `FREESWITCH_XML_HANDLER_TOKEN` later, re-apply the configuration:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
systemctl restart freeswitch
```

**Source build.** Only when you need to change FreeSWITCH itself or cannot use
the SignalWire packages. No token is required, but compilation takes much
longer and updates require rebuilding. On later runs, the installer asks
whether to rebuild the source installation.

## 7. HTTPS Certificates (Optional)

Helper scripts cover free Let's Encrypt certificates and Cloudflare DNS. Run
them after the main install, once a public domain name points at the server.

> [!IMPORTANT]
> Before requesting a certificate, make sure your domain name's DNS A record
> points to this server's public IP address. Let's Encrypt verifies ownership
> by connecting to your server over the internet on port 80.

### Let's Encrypt (Single Domain)

```bash
cd /var/www/tallpbx/scripts/resources

bash letsencrypt.sh                                        # interactive
bash letsencrypt.sh --domain pbx.example.com --email admin@example.com
bash letsencrypt.sh --domain pbx.example.com --staging     # test, no rate limits
```

Nginx must be running, and the internet must be able to reach port 80. The
script switches Nginx to HTTPS.

### Wildcard Certificate (Cloudflare DNS)

A wildcard certificate (`*.example.com`) secures your base domain and all
possible subdomains under a single certificate.

**When to use a wildcard certificate:**
- **Multi-Tenant Hosting**: If you host multiple tenants using subdomains
  (e.g., `tenant1.pbx.example.com` and `tenant2.pbx.example.com`), a wildcard
  certificate covers all current and future subdomains automatically without
  requesting a new certificate for each tenant.
- **Port 80 Inaccessible**: Standard single-domain certificates require inbound
  HTTP access on port 80 for Let's Encrypt verification. If your server is behind
  a restrictive firewall, NAT, or carrier CGNAT where port 80 cannot be opened,
  the wildcard script uses the DNS-01 challenge via Cloudflare's API instead —
  verifying domain ownership entirely through DNS records.

```bash
cd /var/www/tallpbx/scripts/resources

# 1. Save and verify your Cloudflare API token:
bash cloudflare-dns.sh

# 2. Issue the wildcard certificate:
bash letsencrypt.sh --wildcard --domain pbx.example.com
```

The Cloudflare token needs `Zone:Zone:Read` and `Zone:DNS:Edit` permissions
(create one at https://dash.cloudflare.com/profile/api-tokens).

### Automatic Certificate Renewal

Certificates issued through Let's Encrypt renew automatically:

- **Frequency**: The `certbot.timer` systemd background service runs twice daily.
- **Renewal window**: Certbot checks all installed certificates and only renews those within **30 days of expiration** (Let's Encrypt certificates are valid for 90 days). If a certificate is not yet due for renewal, no action is taken.
- **Web server reload**: Upon successful renewal, Nginx reloads automatically so the updated certificate takes effect immediately without downtime.

To check the timer status or test renewal:

```bash
# Verify the renewal timer is running:
systemctl status certbot.timer

# Test renewal without affecting live certificates:
certbot renew --dry-run
```

### Other Certificates

For a certificate from another provider, place `fullchain.pem` and
`privkey.pem` under `/etc/letsencrypt/live/<domain>/` and run
`nginx -t && systemctl reload nginx`. Tenant domains are added under
**Admin → Domains** and issued with the same `letsencrypt.sh` command.

## Upgrading TallPBX

> [!CAUTION]
> Always take a full backup before upgrading. Database updates move forward and
> cannot be easily reversed. If an upgrade fails, restoring the backup is the
> safest recovery path.

### Updating from the Web Panel (Recommended)

The recommended way to update TallPBX is directly from the web interface:

1. Sign in to the web panel as an administrator.
2. In the sidebar, navigate to **Git Update** (`/panel/git-update`).
3. The page displays your current branch, version, and any incoming commits
   available from the repository.
4. Click **Update Now**, type `UPDATE` in the safety confirmation dialog, and
   proceed.

TallPBX executes the update pipeline automatically: pulling the latest code,
installing dependencies, applying database migrations, syncing modules,
rebuilding frontend assets, clearing stale caches, and repairing file permissions.

---

### Manual Updating from the Linux CLI

If you prefer to update from the terminal, need to automate updates via scripts,
or cannot access the web panel, use one of the manual CLI methods below.

#### Method 1: Using the Bootstrap Script (Terminal One-Liner)

Re-run the one-line installer command — it pulls the latest code, applies
database migrations, and preserves all your existing configuration, accounts,
and recordings:

```bash
# Update on the default main branch:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash

# Or update on a specific release branch (such as 1.1):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --ref 1.1
```

Use the same `--ref` value you used during initial installation so the working
copy stays on your chosen release branch.

#### Method 2: Step-by-Step Manual Update

To update manually step by step, take a backup first, then run from `/var/www/tallpbx`:

```bash
# 1. Pull the latest code from the branch you installed
#    (main, or a release branch such as 1.1).
git pull --ff-only origin main

# 2. Install PHP dependencies and rebuild browser files.
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 3. Apply database and module updates.
php artisan migrate --force
php artisan module:sync --only-local

# 4. Refresh caches, permissions, and FreeSWITCH configuration.
php artisan optimize:clear
sudo php artisan permissions:repair --scope=full
fs_cli -x "reloadxml"
```

After upgrading, confirm that the panel opens, FreeSWITCH is running, and a
test call works. If an upgrade fails, restore the backup. Database updates move
forward and should not be rolled back casually.

### If Git Will Not Pull the Update

Git may refuse the update when the server has local changes that conflict with
the new code. Do not delete those changes — save them, update, then restore:

```bash
# Save local changes, including new files.
git stash push --include-untracked -m "before TallPBX upgrade"

# Download the straightforward update.
git pull --ff-only origin main

# Put the saved local changes back after the update.
git stash pop
```

If the final command reports a conflict, resolve it before continuing. The
saved change remains available as a Git stash until it is applied successfully.

## Troubleshooting

### Installer Fails at "Installing Composer"

Some VPS providers assign a global IPv6 address without a default IPv6 route.
PHP tries IPv6 first when downloading files, the connection times out, and
the installer fails with:

```text
PHP Warning: copy(https://getcomposer.org/installer): Failed to open stream: Connection timed out
```

The installer detects this automatically and configures the system to prefer
IPv4 by activating the IPv4 precedence rule in `/etc/gai.conf`. If the
detection did not run (for example, on a manual Composer install), activate
the rule yourself:

```bash
# Uncomment or add this line in /etc/gai.conf:
precedence ::ffff:0:0/96  100
```

To undo the preference later, comment out or remove that line.

### SignalWire Token Rejected

If the FreeSWITCH package step fails with an authentication error, the saved
token may have expired or been revoked. Re-run the installer — it will ask for
a new token. You can also update the token directly:

```bash
nano /etc/pbx/installer.env   # update the SWITCH_TOKEN line
```

### Git Conflicts During Upgrade

See [If Git Will Not Pull the Update](#if-git-will-not-pull-the-update) above.
