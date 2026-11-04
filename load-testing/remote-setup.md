# Remote Host Setup

Every setting that changes a measurement, with the command that applies it and the command that proves it. Run these on the host named; nothing here is applied by guesswork.

Two hosts are involved:

| Role | What it does | What it must not do |
|:--|:--|:--|
| **PBX under test** | Serves FreeSWITCH, nginx, PHP-FPM, the persistent worker, the ESL listener, Reverb, MariaDB and Redis | Run load generators |
| **Load generator** | Runs SIPp and the XML HTTP client | Constrain its own packets (no firewall, no shaping) |

The operator supplies the addresses and the SSH identity. They are never written into this directory.

```bash
export PBX_HOST=<pbx-address>
export SIPP_HOST=<generator-address>
export SSH_IDENTITY=<path-to-key>      # optional; ambient SSH config is enough
```

---

## 1. Build parity

A run must measure the branch the documents describe, with a clean tree.

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && git rev-parse --short HEAD && git branch --show-current && git status --porcelain'
```

**Expected:** the branch is `5.x`, the commit equals `origin/5.x`, and `git status --porcelain` prints nothing. A dirty tree blocks the installer's own fast-forward, so it must be resolved first.

---

## 2. Install and repair (idempotent)

```bash
ssh "$PBX_HOST" 'wget -qO- https://raw.githubusercontent.com/tallpbx/tallpbx/5.x/scripts/bootstrap.sh | bash'
```

**Prove idempotency** by running it twice and comparing a state fingerprint (file hashes, service states, migration counts, row counts) between the two passes. Identical fingerprints mean the installer converged.

After any install, confirm the pieces a measurement depends on:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && \
  echo "fpm: $(grep -E "^pm( |\.max_children)" /etc/php/8.5/fpm/pool.d/www.conf | tr "\n" " ")"; \
  echo "cdr-worker: $(systemctl is-active tallpbx-cdr-worker)"; \
  echo "mod_json_cdr: $(fs_cli -x "module_exists mod_json_cdr")"; \
  echo "firewall table: $(nft list ruleset | grep -c "table inet tallpbx_filter")"'
```

---

## 3. FreeSWITCH logging: `notice`

Default is `info`, which logs every XML lookup and makes the host spend CPU writing to disk.

**Apply** (both files, then a restart — a reload does not re-open the log handle reliably):

```bash
sudo sed -i -E 's|(<param name="loglevel" value=")info("/>)|<param name="loglevel" value="notice"/>|' \
  /etc/freeswitch/autoload_configs/switch.conf.xml
sudo sed -i -E 's|(data="console_loglevel=)info(")|console_loglevel=notice|' /etc/freeswitch/vars.xml
sudo systemctl restart freeswitch
```

**Prove** with the marker pair — a line at `notice` must reach the file, a line at `info` must not:

```bash
fs_cli -x 'log notice PROBE-NOTICE'
fs_cli -x 'log info PROBE-INFO'
sleep 2
grep -c PROBE-NOTICE /var/log/freeswitch/freeswitch.log   # expect 1
grep -c PROBE-INFO   /var/log/freeswitch/freeswitch.log   # expect 0
```

> `fs_cli -x "fsctl loglevel"` reports `DISABLE` on FreeSWITCH 1.11.4 even while logging works. Never use it as the assertion.

The level name is `err`, not `error`; the accepted order is `alert, crit, err, warning, notice, info, debug`, and it is a ceiling (records above it are discarded before any logger sees them).

---

## 4. ESL event profile: `essential` (default) or `full`

`essential` subscribes to 10 call-lifecycle events; `full` subscribes to the complete catalogue.

**Apply:**

```bash
sudo sed -i -E 's|^FS_ESL_EVENT_PROFILE=.*|FS_ESL_EVENT_PROFILE=essential|' /var/www/tallpbx/.env
sudo systemctl restart freeswitch-listener
```

**Prove:** the listener logs its profile and event count on boot, and the configured set can be read from the application:

```bash
journalctl -u freeswitch-listener --since '-2 min' --no-pager | grep -iE 'profile|Listening for'
cd /var/www/tallpbx && php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("freeswitch.event_profile")." events=".count(config("freeswitch.subscribe")).PHP_EOL;'
```

**Effect on the series:** none for XML-only cells — the listener receives no events while no calls run. It matters for SIP cells and for CPU contention once events flow. Measure the difference with `scripts/tools/ab-esl-reverb.sh`.

---

## 5. Reverb and broadcast debouncing

**Connection** (single-host appliance):

```bash
grep -E '^(BROADCAST_CONNECTION|REVERB_APP_ID|REVERB_HOST|REVERB_PORT|REVERB_SCHEME)=' /var/www/tallpbx/.env
systemctl is-active tallpbx-reverb
grep -c 'location /app' /etc/nginx/sites-enabled/pbx
```

**Debouncing** is `FS_BROADCAST_THROTTLE_MS` (default 500). `0` disables it entirely:

```bash
grep -E '^FS_BROADCAST_THROTTLE_MS=' /var/www/tallpbx/.env || echo 'unset (default 500)'
sudo sed -i -E 's|^FS_BROADCAST_THROTTLE_MS=.*|FS_BROADCAST_THROTTLE_MS=0|' /var/www/tallpbx/.env
cd /var/www/tallpbx && tpbx config:clear && sudo systemctl restart freeswitch-listener
```

**Effect on the series:** none for XML-only cells; meaningful once calls produce events.

---

## 6. PHP-FPM pool

```bash
grep -E '^(pm|pm\.max_children)' /etc/php/8.5/fpm/pool.d/www.conf
```

- `pm = dynamic` — Debian's default; workers start and stop with load.
- `pm = static` — fixed worker count; the installer default for an appliance.

**Apply:**

```bash
sudo sed -i -E 's|^pm = .*|pm = static|; s|^pm\.max_children = .*|pm.max_children = 5|' \
  /etc/php/8.5/fpm/pool.d/www.conf
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.5-fpm
```

**Prove:** the runner asserts the values back. Switching the pool briefly answers `502`; the runner waits for a readiness probe before measuring.

---

## 7. XML entry file (stock vs fast controller)

The decision is one nginx location block.

**stock** — `try_files` falls through to `index.php`, so every lookup boots the framework:

```nginx
location = /api/v1/xml-handler {
    access_log off;
    try_files $uri /index.php?$query_string;
}
```

**fast** — FastCGI straight to `public/xml-handler-fast.php`:

```nginx
location = /api/v1/xml-handler {
    access_log off;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/xml-handler-fast.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
}
```

**Apply:** `scripts/lib/switch.sh apply_xml_path stock|fast` (it runs `nginx -t` and refuses an invalid rewrite).
**Prove:** `scripts/lib/switch.sh assert_xml_path stock|fast`.

---

## 8. Lua XML cache proxy

Two halves that must move together.

| Half | File | Enabled | Disabled |
|:--|:--|:--|:--|
| FreeSWITCH binding | `/etc/freeswitch/autoload_configs/lua.conf.xml` | both `<param>` lines active | both wrapped in `<!-- … -->` |
| Application mirror | `.env` `FS_LUA_PROXY_ENABLED` | `true` | `false` |

```bash
grep -cE '^[[:space:]]*<param name="xml-handler-(script|bindings)"' /etc/freeswitch/autoload_configs/lua.conf.xml
grep -E '^FS_LUA_PROXY_ENABLED=' /var/www/tallpbx/.env
```

**Correct pairings:** `0 + false` (off, the installer default) or `2 + true` (on).
**Wrong pairings measure nothing:** `0 + true` makes Laravel fill a Redis mirror FreeSWITCH never reads; `2 + false` makes Lua read a mirror nobody fills.

`mod_lua` must load **before** `mod_xml_curl` in `modules.conf.xml`, or the handler is never consulted:

```bash
grep -nE 'mod_(lua|xml_curl)' /etc/freeswitch/autoload_configs/modules.conf.xml
```

**Apply:** `scripts/lib/switch.sh apply_lua_bindings on|off` (also reloads `mod_lua` and clears config).
**Prove:** `scripts/lib/switch.sh assert_lua_bindings on|off` — checks the params, the `.env` flag, and that `mod_lua` is loaded.

---

## 9. Octane binding and port 8090

`xml_curl.conf.xml` ships **two** gateways. FreeSWITCH consults the first declared one:

```xml
<binding name="tallpbx-octane">    <param name="gateway-url" value="http://127.0.0.1:8090/api/v1/xml-handler?token=…"/></binding>
<binding name="tallpbx-fallback">  <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler?token=…"/></binding>
```

The Octane worker serves the same route on `:8090`. Both return `403` without a token, which is how you prove the route is live on each server:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8090/api/v1/xml-handler -d '<xml/>'
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/xml-handler  -d '<xml/>'
```

**Apply:** `scripts/lib/switch.sh apply_octane_binding on|off` (comments or uncomments the whole binding, then reloads `mod_xml_curl`).
**Prove:** `scripts/lib/switch.sh assert_octane_binding on|off`.

**Measurement rule:** an Octane cell must post to `http://127.0.0.1:8090/api/v1/xml-handler`. The runner does this automatically for the `v-octane` variant; posting to nginx would measure PHP-FPM and label it Octane.

---

## 10. FreeSWITCH runtime database: disk or tmpfs

```bash
mountpoint /var/lib/freeswitch/db && findmnt -n -o FSTYPE --target /var/lib/freeswitch/db
```

Disk-backed is the shipped default. tmpfs is one configuration under comparison, not a prerequisite.

**Apply:** `scripts/lib/switch.sh apply_fs_db_mode disk|tmpfs` (stops FreeSWITCH, copies, mounts, starts).
**Prove:** `scripts/lib/switch.sh assert_fs_db_mode disk|tmpfs`.

---

## 11. XML cache TTLs

```bash
grep -E '^FS_XML_HANDLER_(DIALPLAN|CONTRIBUTOR|DIRECTORY|ACL)_CACHE_TTL=' /var/www/tallpbx/.env
```

Four lifetimes: `dialplan` (the finished answer), `contributor` (shared building blocks), `directory` (registrations, users), `acl` (profile access lists). Only the first two affect dialplan XML generation.

**Apply:** `scripts/lib/switch.sh apply_ttl <dialplan> <contributor> [directory] [acl]`, followed by `config:clear`.
**Prove:** `scripts/lib/switch.sh assert_ttl`.

---

## 12. Firewall: verify, never change

```bash
nft list ruleset | grep -E 'policy|dport'
```

A working appliance accepts SIP and RTP from any source, so a generator needs no allow-list entry:

```
udp dport { 5060, 5061, 5080 } accept
tcp dport { 5060, 5061, 5080 } accept
udp dport 16384-32768 accept
```

**Never** run `nft add`, `insert`, `delete`, `chain` or `flush` against a live host. Two production outages were caused that way. Firewall changes go through `tpbx security:apply` or the Security Center.

---

## 13. Load generator preparation

The generator must not be the bottleneck.

```bash
sudo tee /etc/sysctl.d/99-tallpbx-loadtest.conf >/dev/null <<'EOF'
net.core.rmem_max=16777216
net.core.wmem_max=16777216
net.ipv4.ip_local_port_range=10000 65000
EOF
sudo tee /etc/security/limits.d/99-tallpbx-loadtest.conf >/dev/null <<'EOF'
root soft nofile 65535
root hard nofile 65535
EOF
sudo sysctl --system
```

**Prove:**

```bash
echo "rmem=$(sysctl -n net.core.rmem_max) wmem=$(sysctl -n net.core.wmem_max) ports=$(sysctl -n net.ipv4.ip_local_port_range)"
echo "nofile=$(ulimit -n) firewall_lines=$(nft list ruleset 2>/dev/null | wc -l)"
command -v sipp && tc qdisc show | head -3
```

**Expected:** 16777216 / 16777216 / `10000 65000`, `nofile` 65535, **zero** firewall lines, only the default `fq_codel` qdisc, and `sipp` on the path.

---

## Baseline settings of record

A run is only comparable when these are what the manifest says they are:

| Setting | Baseline | Where |
|:--|:--|:--|
| FreeSWITCH log level | `notice` | `switch.conf.xml`, `vars.xml` |
| ESL profile | `essential` | `.env` |
| Broadcast throttle | unset (500 ms) | `.env` |
| PHP-FPM | `pm = static`, `max_children = 5` | `pool.d/www.conf` |
| XML entry file | stock (`index.php`) | nginx `location` |
| Lua proxy | `.env false` + bindings commented | `.env`, `lua.conf.xml` |
| Octane binding | as installed (both gateways declared) | `xml_curl.conf.xml` |
| Runtime database | disk | `/var/lib/freeswitch/db` |
| Cache TTLs | unset (config defaults) | `.env` |

Every one of these is asserted by the runner before a cell runs, and restored by its teardown afterwards.
