# Operations & Maintenance

Day-to-day commands for running a TallPBX server. Installation is covered in
[INSTALL.md](../INSTALL.md).

## Service Management

TallPBX runs as systemd services. Check or restart any of them with
`systemctl status <name>` / `systemctl restart <name>`:

| Service | Purpose |
| --- | --- |
| `freeswitch` | Phone system engine |
| `freeswitch-listener` | FreeSWITCH event listener |
| `tallpbx-queue` | Background jobs (recordings, media, mail) |
| `tallpbx-scheduler` | Scheduled tasks and sweeps |
| `tallpbx-reverb` | Real-time WebSocket server |
| `redis-server` | In-memory storage |
| `nftables` | Kernel firewall |

Handy checks: `redis-cli ping` (expects `PONG`) and `php artisan security:status`
(firewall state and active bans).

## Health Checks and Testing

After installation — and at any time — run the smoke check to confirm the
application is healthy:

```bash
cd /var/www/tallpbx
php artisan app:test --smoke
```

Developers can run the broader suites:

```bash
php artisan app:test              # all application tests
php artisan app:test --full       # also browser tests (Chromium required)
php artisan app:test --sequential # one process at a time when investigating failures
```

Tests run in a temporary in-memory database, never the live TallPBX database,
and they stop if that safety rule is not in effect. TallPBX also blocks Laravel
commands that would erase or rebuild the primary database; these safeguards do
not prevent a person with MariaDB access from running destructive SQL manually.

### Telephony Performance & Cache Verification

To benchmark telephony lookup latency, verify Redis hit rates, and ensure PHP-FPM workers are not starved under call bursts:

1. **Verify Redis Cache Hit Rate**:
   Inspect active Redis keyspace hits and misses:
   ```bash
   redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'
   ```

2. **Run XML Handler Cache Sweep**:
   Benchmark all 5 standard cache configurations (cold baseline, contributor cache, default 5s burst, 30s call-center profile, and memory hit ceiling) and calculate hit rates on your hardware:
   ```bash
   bash scripts/run-cache-sweep.sh
   ```
   The runner automatically restores recommended production defaults (`TTL=5`) when finished.

3. **Verify PHP-FPM Worker Pool**:
   Confirm PHP-FPM is running in `static` mode and check for worker pool saturation warnings:
   ```bash
   grep -E 'pm =|pm.max_children' /etc/php/8.5/fpm/pool.d/www.conf
   tail -n 50 /var/log/php8.5-fpm.log | grep "server reached pm.max_children"
   ```

4. **SIPp Telephony Validation**:
   Validate SIP registrations, extensions, gateways, loopback call forwarding, and media flow:
   ```bash
   PBX_HOST=<ip> LOAD_GENERATOR_IP=<ip> bash scripts/pbx-sipp-validate.sh
   ```
   See [docs/load-testing-guide.md](load-testing-guide.md) for full instructions, topology, and benchmark results.

## Troubleshooting

### Web Panel Shows 502 or 504 Error

PHP-FPM is not running, has crashed, or worker processes are exhausted during call bursts. Check its status and restart it:

```bash
systemctl status php8.5-fpm
systemctl restart php8.5-fpm
```

If it refuses to start or errors under load, check the log:

```bash
journalctl -u php8.5-fpm --no-pager -n 50
tail -n 50 /var/log/php8.5-fpm.log | grep "server reached pm.max_children"
```

If you see `server reached pm.max_children setting`, the pool was saturated by concurrent HTTP XML handler requests. The installer auto-configures `pm = static` with `12` workers on 4GB systems (`6` on 2GB systems). If needed on high-traffic PBX servers, increase `pm.max_children` in `/etc/php/8.5/fpm/pool.d/www.conf` and restart PHP-FPM.

### "Permission denied" Errors in the Panel or Logs

File ownership has drifted, usually after a manual deployment or running
commands as root. Repair permissions with:

```bash
cd /var/www/tallpbx
sudo php artisan permissions:repair --scope=full
```

If Laravel itself will not boot, use the emergency fallback:

```bash
sudo bash scripts/repair-application-permissions.sh
```

### FreeSWITCH Will Not Start

Check the systemd status and FreeSWITCH logs:

```bash
systemctl status freeswitch
journalctl -u freeswitch --no-pager -n 50
```

Common causes:
- **Port conflict**: another process is using port 5060. Check with
  `ss -tlnp | grep 5060`.
- **Missing configuration**: the XML-curl gateway URL may be empty. Re-run
  `bash scripts/resources/freeswitch.sh --configure-only && systemctl restart freeswitch`.
- **Permission error on media directories**: run
  `sudo php artisan permissions:repair --scope=full`.

### Phones Cannot Register

Confirm FreeSWITCH is running (`fs_cli -x 'status'`), then check that the
SIP profile loaded correctly:

```bash
fs_cli -x 'sofia status'
```

If the profile shows as "down", reload XML and restart the profile:

```bash
fs_cli -x 'reloadxml'
fs_cli -x 'sofia profile internal restart'
```

### Redis Is Down — Sessions and Cache Not Working

The PBX keeps working without Redis, but panel sessions, cache, and dynamic
ban counters pause. Restart Redis:

```bash
systemctl restart redis-server
redis-cli ping    # should respond PONG
```

After restoring Redis, clear stale cache:

```bash
cd /var/www/tallpbx
php artisan optimize:clear && php artisan optimize
```

## Outgoing Mail & Notifications (Email Connector)

TallPBX delivers all transactional emails (password resets, voicemail-to-email
notifications, backup summaries, and security alerts) asynchronously through
background queues.

### 1. Setting Up the Connector
Sign in to the web panel and navigate to **Email Connector**:
- **Standard SMTP**: Works with any standard mail server, including Postmark,
  SendGrid, Amazon SES, or Mailgun.
- **Gmail / Google Workspace**: Supports both standard App Passwords (via SMTP)
  and secure OAuth 2.0.
- **Microsoft 365**: Supports OAuth 2.0 with Microsoft Graph or modern SMTP
  authentication.

Use the in-panel **Send Test Email** button after saving credentials to verify
connectivity.

### 2. Queue Worker Service
Outgoing emails are dispatched to the `tallpbx-queue` systemd service so web
requests remain fast. Ensure the service is active:

```bash
systemctl status tallpbx-queue
```

If emails fail to send or queue up, inspect failed jobs with Artisan:

```bash
cd /var/www/tallpbx
php artisan queue:failed            # view failed mail jobs and error traces
php artisan queue:retry all         # retry failed jobs after fixing credentials
```

---

## FreeSWITCH Sound Prompt Languages

FreeSWITCH uses two components for voice prompts and system announcements:
1. **Audio files**: Recorded prompts stored in `/usr/share/freeswitch/sounds/{lang}/{dialect}/{voice}/`.
2. **Grammar "Say" modules**: Modules like `mod_say_en`, `mod_say_es`, and `mod_say_fr` that pronounce numbers, dates, times, currency, and voicemail message counts.

### 1. Managing Languages with Artisan (Recommended)

TallPBX includes built-in Artisan commands that manage sound packages and modules automatically, whether FreeSWITCH was installed from packages or compiled from source.

#### View Installed and Available Languages
```bash
cd /var/www/tallpbx
php artisan pbx:sounds:list
```

#### Install an Additional Language
```bash
# Install Spanish (Mario voice)
php artisan pbx:sounds:install es

# Install French (June voice) and set it as the default immediately
php artisan pbx:sounds:install fr --default
```

#### Change the Default Sound Prompt Language
```bash
php artisan pbx:sounds:default es
```
This updates the preprocessor directives in `/etc/freeswitch/vars.xml` and reloads FreeSWITCH XML in real time without dropping active calls.

---

### 2. Manual Package Installation (APT-Based FreeSWITCH)

For servers installed using the default package method, you can also manage sound packages directly through APT:

```bash
# 1. Install sound files and grammar module for Spanish
apt-get install -y freeswitch-sounds-es-ar-mario freeswitch-mod-say-es

# 2. Or for French
apt-get install -y freeswitch-sounds-fr-ca-june freeswitch-mod-say-fr

# 3. Enable the grammar module in /etc/freeswitch/autoload_configs/modules.conf.xml
# Add <load module="mod_say_es"/> under the say modules section

# 4. Load the module into the running FreeSWITCH instance
fs_cli -x "load mod_say_es"

# 5. Change the default prompt language in /etc/freeswitch/vars.xml
# Update:
#   <X-PRE-PROCESS cmd="set" data="default_language=es"/>
#   <X-PRE-PROCESS cmd="set" data="default_dialect=ar"/>
#   <X-PRE-PROCESS cmd="set" data="default_voice=mario"/>
#   <X-PRE-PROCESS cmd="set" data="sound_prefix=$${sounds_dir}/es/ar/mario"/>

# 6. Apply the configuration change
fs_cli -x "reloadxml"
```

---

### 3. Manual Source Installation (Compiled FreeSWITCH)

For servers where FreeSWITCH was compiled from source:

#### Step A: Compile the Say Module (if not built during initial setup)
The FreeSWITCH installer script enables `mod_say_es` and `mod_say_fr` during the initial source build. If your build does not have them, compile from the source directory:
```bash
cd /usr/src/freeswitch
make mod_say_es-install
make mod_say_fr-install
```

#### Step B: Download and Extract Sound Archives
FreeSWITCH hosts official sound archives at `https://files.freeswitch.org/releases/sounds/`:
```bash
# Spanish Mario (8 kHz and 16 kHz)
mkdir -p /usr/share/freeswitch/sounds/es/ar/mario
curl -sSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-es-ar-mario-8000-1.0.51.tar.gz \
  | tar -xz -C /usr/share/freeswitch/sounds/es/ar/mario
curl -sSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-es-ar-mario-16000-1.0.51.tar.gz \
  | tar -xz -C /usr/share/freeswitch/sounds/es/ar/mario
chown -R freeswitch:freeswitch /usr/share/freeswitch/sounds/es

# French June (8 kHz and 16 kHz)
mkdir -p /usr/share/freeswitch/sounds/fr/ca/june
curl -sSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-fr-ca-june-8000-1.0.51.tar.gz \
  | tar -xz -C /usr/share/freeswitch/sounds/fr/ca/june
curl -sSL https://files.freeswitch.org/releases/sounds/freeswitch-sounds-fr-ca-june-16000-1.0.51.tar.gz \
  | tar -xz -C /usr/share/freeswitch/sounds/fr/ca/june
chown -R freeswitch:freeswitch /usr/share/freeswitch/sounds/fr
```

#### Step C: Enable Module and Switch Default
```bash
fs_cli -x "load mod_say_es"
# Edit /etc/freeswitch/vars.xml to set default_language=es and sound_prefix
fs_cli -x "reloadxml"
```

