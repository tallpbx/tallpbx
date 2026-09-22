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

## Troubleshooting

### Web Panel Shows 502 or 504 Error

PHP-FPM is not running or has crashed. Check its status and restart it:

```bash
systemctl status php8.5-fpm
systemctl restart php8.5-fpm
```

If it refuses to start, check the log for configuration errors:

```bash
journalctl -u php8.5-fpm --no-pager -n 50
```

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
