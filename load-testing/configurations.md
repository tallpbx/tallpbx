# Configuration Switches

Six variants are built from four independent switches, plus two runtime settings used for ablations. Each switch is applied by `scripts/lib/switch.sh`, which then calls its matching `assert_*` and reads the effective value back. A mismatch prints `SWITCH-FAILED <detail>` and returns non-zero, which fails that cell rather than producing a number.

```bash
bash load-testing/scripts/lib/switch.sh <command> [arguments]
```

Every command runs on the PBX. From the orchestrator the runner wraps it in SSH automatically (see `reference.md`).

---

## Quick index

| Command | Argument | What it changes |
|:--|:--|:--|
| `apply_fpm_profile` | `static\|dynamic <children>` | PHP-FPM pool mode and worker count |
| `apply_xml_path` | `stock\|fast` | nginx entry file for `/api/v1/xml-handler` |
| `apply_lua_bindings` | `on\|off` | Lua XML handler params + `FS_LUA_PROXY_ENABLED` |
| `apply_octane_binding` | `on\|off` | Which `xml_curl` gateway FreeSWITCH consults |
| `apply_ttl` | `<dialplan> <contributor> [directory] [acl]` | XML cache lifetimes |
| `apply_fs_loglevel` | `debug\|info\|notice\|warning\|err` | FreeSWITCH file log ceiling |
| `apply_fs_db_mode` | `disk\|tmpfs` | Where FreeSWITCH keeps its runtime SQLite database |
| `apply_cdr_transport` | `fpm\|octane` | Which endpoint `mod_json_cdr` posts records to |
| `apply_esl_profile` | `essential\|full` | How many event types the ESL listener subscribes to |
| `apply_broadcast_throttle` | `<milliseconds>` | Reverb debounce window; `0` disables it |
| `probe_lookup` | `<extension> <domain> [count]` | Nothing — it measures one directory lookup |
| `coherent_baseline` | — | Makes the Lua flag agree with the bindings |
| `restore_baseline` | — | Reverts everything this library changed |

Each `apply_*` has an `assert_*` twin. `probe_lookup`, `coherent_baseline` and `restore_baseline` have no twin by design.

---

## `apply_fpm_profile`

- **Argument:** `static <n>` or `dynamic <n>`.
- **File:** `/etc/php/<version>/fpm/pool.d/www.conf`.
- **Assertion:** re-reads `pm` and `pm.max_children` from the file after the reload.
- **Effect:** worker spawn cost and memory. `dynamic` starts and stops workers with load; `static` keeps them resident.
- **Caveat:** the reload leaves a brief `502` window. The runner waits for a readiness probe instead of recording that window as a result.

## `apply_xml_path`

- **Argument:** `stock` or `fast`.
- **File:** every nginx file that declares `location = /api/v1/xml-handler`, recorded so the teardown can restore each one.
- **Assertion:** the location body contains the expected `try_files … /index.php` (stock) or `SCRIPT_FILENAME … xml-handler-fast.php` (fast).
- **Effect:** framework boot per lookup versus a reduced bootstrap.
- **Caveat:** `nginx -t` runs before the reload; an invalid rewrite is refused and the old workers keep serving.

## `apply_lua_bindings`

- **Argument:** `on` or `off`.
- **Files:** `lua.conf.xml` (two `<param>` lines commented or active) **and** `.env` `FS_LUA_PROXY_ENABLED`.
- **Assertion:** three things — the params' state, the `.env` flag, and that `mod_lua` is loaded when `on`.
- **Effect:** on `on`, directory and dialplan lookups can be answered inside FreeSWITCH from Redis; on `off`, every lookup reaches PHP.
- **Caveat:** the halves must agree. `flag true + bindings off` fills a mirror nothing reads; `flag false + bindings on` reads a mirror nobody fills. `assert_lua_bindings` rejects both.

## `apply_octane_binding`

- **Argument:** `on` or `off`.
- **File:** `/etc/freeswitch/autoload_configs/xml_curl.conf.xml` — the whole `<binding name="tallpbx-octane">` block is commented or uncommented, then `mod_xml_curl` reloads.
- **Assertion:** exactly one of the two states, read from the file.
- **Effect:** whether FreeSWITCH's own lookups go to the persistent worker on `:8090` or to nginx.
- **Caveat:** the shipped file declares **both** gateways active, so `on` reproduces the installed state. An Octane *cell* must also post its HTTP load at `:8090`; the runner does this per variant and it is recorded in the report path.

## `apply_ttl`

- **Argument:** four integers — `dialplan`, `contributor`, then optional `directory`, `acl`. `0` disables that cache.
- **File:** `.env` (`FS_XML_HANDLER_*_CACHE_TTL`), followed by `config:clear`.
- **Assertion:** the four values read back from `.env`.
- **Effect:** how often PHP must rebuild an answer, and with that how much database work a lookup costs.
- **Caveat:** only `dialplan` and `contributor` affect dialplan XML generation. Changing `directory` and `acl` while measuring the dialplan section changes nothing measurable — that is why `uniform-30` and `production-60` land inside each other's noise.

## `apply_fs_loglevel`

- **Argument:** `alert|crit|err|warning|notice|info|debug`.
- **Effect:** how many records FreeSWITCH writes per lookup. At `info` the host spends measurable CPU on disk I/O.
- **Assertion:** a functional probe, not `fsctl`. A line at the requested level must reach `/var/log/freeswitch/freeswitch.log`, and a line one step more verbose must not.

## `apply_fs_db_mode`

- **Argument:** `disk` or `tmpfs`.
- **Effect:** whether runtime database writes hit disk or memory.
- **Assertion:** `mountpoint` plus the filesystem type.
- **Caveat:** switching stops and starts FreeSWITCH. It is an ablation, not a prerequisite — disk is the shipped baseline.

## `apply_cdr_transport`

- **Argument:** `fpm` or `octane`.
- **File:** the `url` in `json_cdr.conf.xml`, then `reload mod_json_cdr`.
- **Assertion:** the URL and a probe of the endpoint (a `401` proves the route is live and the token is enforced).
- **Effect:** where CDR records are posted, and therefore whether CDR traffic competes with XML traffic in the same workers.

## `apply_esl_profile`

- **Argument:** `essential` or `full`.
- **File:** `.env` `FS_ESL_EVENT_PROFILE`, then the listener is restarted — it boots once and keeps its subscription for its whole life, so an `.env` change is invisible until then.
- **Assertion:** the declared value, plus the listener's own start-up line, which reports the profile and the number of event types it subscribed to.
- **Effect:** `essential` takes 10 call-lifecycle events, `full` takes the catalogue. Measurable **only under call traffic** — with no calls there are no events to filter, and both profiles measure zero.
- **Caveat:** the listener's start-up line is the proof that the process, not just the file, changed.

## `apply_broadcast_throttle`

- **Argument:** milliseconds. `500` is the shipped default (about two broadcasts per second per scope); `0` disables debouncing so every handled event is dispatched to Reverb.
- **File:** `.env` `FS_BROADCAST_THROTTLE_MS`, then the listener is restarted for the same reason as above.
- **Assertion:** the declared value and that the listener was restarted after it was written. The running value cannot be read out of the process, so the **effect** is proven by the measurement — listener CPU and Reverb CPU under a fixed call load — not by a configuration read.
- **Effect:** this is what the debounce is worth. With `0`, a call burst produces one HTTP POST to Reverb per event instead of at most two per second, and on a single core that competes directly with call handling.
- **Caveat:** like the ESL profile, it is meaningless without calls. Measured under XML-only load, both Reverb and the listener reported zero CPU regardless of this setting.

## `probe_lookup`
- **Argument:** extension, SIP domain, and a count (default 30).
- **What it does:** times `find_user_xml id <extension> <domain>` *n* times, times `status` the same number of times, and prints the difference:

```
lookup_count=30 find_user_ms=1594 status_ms=66 net_ms=1528 lua_bindings=off
```

- **Why `status` is subtracted:** the CLI round trip is fixed and large enough to swamp the lookup it is meant to measure.
- **What it proves:** what FreeSWITCH waits for on a directory lookup — the only measurement that can see a Lua hit, because a hit never reaches nginx or PHP.
- **What it does *not* prove:** `lua_bindings` reports the configured binding state, not which component answered. Proving a hit needs the mirror-TTL check described in `docs/load-testing-guide.md`.

## `coherent_baseline` and `restore_baseline`

`restore_baseline` restores every file and `.env` value this library changed, reloads the affected services, then calls `coherent_baseline` before declaring success.

`coherent_baseline` compares the Lua bindings against `FS_LUA_PROXY_ENABLED` and corrects the flag to match the bindings, printing:

```
baseline-corrected FS_LUA_PROXY_ENABLED true -> false (bindings are commented)
```

This exists because a snapshot can capture the two disagreeing, and restoring that faithfully would leave the next cell measuring a configuration that cannot work.

`restore_baseline` is idempotent: with no snapshots present it does nothing, so it is safe to call from a teardown trap after a failure that happened before the first apply.

---

## How the variants combine the switches

| Variant | FPM | XML path | Lua | Octane |
|:--|:--|:--|:--|:--|
| `v-stock-dynamic` | `dynamic 5` | stock | off | off |
| `v-stock-static` | `static 5` | stock | off | off |
| `v-fast` | `static 5` | **fast** | off | off |
| `v-lua-stock` | `static 5` | stock | **on** | off |
| `v-lua-fast` | `static 5` | **fast** | **on** | off |
| `v-octane` | `static 5` | stock | off | **on** |

`config/load-test-matrix.php` is the authority; the runner reads the `requires` list per variant and applies each entry in order.

> `v-octane` applies `fpm:static` for consistency, but FPM is not in the XML path for that variant — the cell posts to `:8090`. Treat the pool setting as inert there.
