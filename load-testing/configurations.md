# Configuration Switches

Five variants (`v-stock-dynamic`, `v-stock-static`, `v-fast`, `v-fast-default`, `v-octane`) are built from three independent switches, plus the runtime settings used for ablations. `matrix.php` is the authority for this list. Each switch is applied by `load-testing/scripts/lib/switch.sh`, which then calls its matching `assert_*` and reads the effective value back. A mismatch prints `SWITCH-FAILED <detail>` and returns non-zero, which fails that cell rather than producing a number.

```bash
bash load-testing/scripts/lib/switch.sh <command> [arguments]
```

When invoking a switch by hand, run it from `/var/www/tallpbx` on the PBX. The matrix runner invokes switches on the PBX over SSH. [Runner reference](reference.md).

---

## Quick index

| Command | Argument | What it changes |
|:--|:--|:--|
| `apply_fpm_profile` | `static\|dynamic <children>` | PHP-FPM pool mode and worker count. A dynamic profile also applies the installer's idle floor (`pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`) so a cell measuring "the shipped default" is faithful |
| `apply_xml_path` | `stock\|fast` | nginx entry file for `/api/v1/xml-handler` |
| `apply_octane_binding` | `on\|off\|only` | Which XML endpoint FreeSWITCH can use |
| `apply_xml_worker` | `off\|only` | Octane service state and the selected endpoint for a test |
| `apply_ttl` | `<dialplan> <contributor> [directory] [acl]` | XML cache lifetimes |
| `apply_fs_loglevel` | `debug\|info\|notice\|warning\|err` | FreeSWITCH file log ceiling |
| `apply_fs_db_mode` | `sqlite\|mariadb\|disk\|tmpfs` | FreeSWITCH runtime database driver (`sqlite` default vs. `mariadb`; `disk`/`tmpfs` kept for storage ablations) |
| `apply_esl_profile` | `essential\|full` | How many event types the ESL listener subscribes to |
| `apply_broadcast_throttle` | `<milliseconds>` | Reverb debounce window; `0` disables it |
| `probe_lookup` | `<extension> <domain> [count]` | Nothing — it measures one directory lookup |
| `restore_baseline` | — | Reverts everything this library changed |

The switch helpers check the settings they apply. `probe_lookup` reads timing and `restore_baseline` uses the captured settings.

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

## `apply_octane_binding`

- **Argument:** `on` enables both endpoints, `off` selects PHP-FPM, and `only` selects Octane. The benchmark runner calls `apply_xml_worker` to set the worker service and select one endpoint together.
- **File:** `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`. Each binding defines an address FreeSWITCH can ask for XML.
- **Verification:** the helper validates the edited configuration and restarts FreeSWITCH when it changes. The runner checks the selected HTTP endpoint with authenticated requests before measuring. The Octane test uses port `8090`.
- **Restore:** the run restores the original configuration and whether `tallpbx-xml-worker` was active. The service's boot setting remains as configured on the host.

## `apply_ttl`

- **Argument:** four integers — `dialplan`, `contributor`, then optional `directory`, `acl`. `0` disables that cache. Pre-configured presets include `default` (300s / 60s), `tuned` (1800s / 600s), `production-60` (60s / 60s), `extended-30` (30s / 30s), and `uncached-db` (0s / 0s, store `null`). Load profiles such as `1000x25` (1000 requests, concurrency 25, tested across rates 5, 10, 15, 20, 25) evaluate these retention ceilings.
- **File:** `/var/www/tallpbx/.env` (`FS_XML_HANDLER_*_CACHE_TTL`), followed by `config:clear` and a restart of the Octane worker if it is running and values changed.
- **Assertion:** `assert_ttl` reads Laravel's resolved runtime configuration: `config('freeswitch.xml_handler.dialplan_cache_ttl')`, `config('freeswitch.xml_handler.dialplan_contributor_cache_ttl')`, `config('freeswitch.xml_handler.directory_cache_ttl')`, and `config('freeswitch.xml_handler.directory_cache_store')`.
- **Effect:** how often PHP must rebuild an answer, and with that how much database work a lookup costs.
- **Caveat:** only `dialplan` and `contributor` affect dialplan XML generation. Changing `directory` and `acl` while measuring the dialplan section changes nothing measurable — that is why `extended-30` and `production-60` land inside each other's noise.

## `apply_fs_loglevel`

- **Argument:** `alert|crit|err|warning|notice|info|debug`.
- **Effect:** how many records FreeSWITCH writes per lookup. At `info` the host spends measurable CPU on disk I/O.
- **Assertion:** a functional probe, not `fsctl`. A line at the requested level must reach `/var/log/freeswitch/freeswitch.log`, and a line one step more verbose must not.

## `apply_fs_db_mode`

- **Argument:** `sqlite`, `mariadb`, `disk`, or `tmpfs`.
- **Effect:** switches FreeSWITCH runtime databases (`core.db`, `sofia_reg_*.db`, `callcenter.db`, `voicemail_default.db`, `call_limit.db`, `fifo.db`) between local SQLite (`FS_DB_DRIVER=sqlite`) and MariaDB (`FS_DB_DRIVER=mariadb` via `mod_mariadb`), or moves SQLite storage between disk and tmpfs.
- **Assertion:** checks `.env` `FS_DB_DRIVER`, `module_exists mod_mariadb` and MariaDB schema creation for `mariadb`, or `findmnt` and `mod_mariadb` disabled for `sqlite`/`disk`/`tmpfs`.
- **Caveat:** switching stops and starts FreeSWITCH, and snapshots `.env` / `pre_load_modules.conf.xml` so `restore_baseline` returns the host cleanly.

## `apply_esl_profile`

- **Argument:** `essential` or `full`.
- **File:** `.env` `FS_ESL_EVENT_PROFILE`, then the listener is restarted — it boots once and keeps its subscription for its whole life, so an `.env` change is invisible until then.
- **Assertion:** the declared value, plus the listener's own start-up line, which reports the profile and the number of event types it subscribed to.
- **Effect:** `essential` takes 10 call-lifecycle events, `full` takes the catalogue. Measurable **only under call traffic** — with no calls there are no events to filter, and both profiles measure zero.
- **Caveat:** the listener's start-up line is the proof that the process, not just the file, changed.

## `apply_broadcast_throttle`

- **Argument:** milliseconds. `500` is the shipped default (about two broadcasts per second per scope); `0` disables debouncing so every handled event is dispatched to Reverb.
- **File:** `.env` `FS_BROADCAST_THROTTLE_MS`, then the listener is restarted for the same reason as above.
- **Assertion:** `assert_broadcast_throttle` inspects the listener's start-up record for `"broadcast_throttle_ms":<expected>`. The runner verifies that the listener was restarted and that the new process recorded the expected debounce interval.
- **Effect:** this is what the debounce is worth. With `0`, a call burst produces one HTTP POST to Reverb per event instead of at most two per second, and on a single core that competes directly with call handling.
- **Caveat:** like the ESL profile, it is meaningless without calls. Measured under XML-only load, both Reverb and the listener reported zero CPU regardless of this setting.

## `probe_lookup`
- **Argument:** extension, SIP domain, and a count (default 30).
- **What it does:** times `find_user_xml id <extension> <domain>` *n* times, times `status` the same number of times, and prints the difference:

```
lookup_count=30 find_user_ms=1594 status_ms=66 net_ms=1528
```

- **Why `status` is subtracted:** the CLI round trip is fixed and large enough to swamp the lookup it is meant to measure.
- **What it proves:** what FreeSWITCH waits for on a directory lookup, with the CLI overhead removed.
- **What it does *not* prove:** which component answered the lookup — a configured state is not proof of who served.

## `restore_baseline`

`restore_baseline` restores every file and `.env` value this library changed and reloads the affected services before declaring success.

`restore_baseline` is idempotent: with no snapshots present it does nothing, so it is safe to call from a teardown trap after a failure that happened before the first apply.

---

## How the variants combine the switches

| Variant | FPM | XML path | Octane |
|:--|:--|:--|:--|
| `v-stock-dynamic` | `dynamic` (Debian default) | stock | off |
| `v-stock-static` | `static 5` | stock | off |
| `v-fast` | `static 5` | **fast** | off |
| `v-fast-default` | `dynamic` (installer default: 1–3 workers on 1 GB) | **fast** | off |
| `v-octane` | `dynamic` (same installer limits as `v-fast-default`) | **fast** | **Octane only for XML** |

`load-testing/matrix.php` is the authority; the runner reads the `requires` list per variant and applies each entry in order.

The fast PHP-FPM handler loads only the telephony modules. Optional Octane loads the same list and keeps the application in memory. On a standard appliance, FreeSWITCH falls back to PHP-FPM when Octane is unavailable, and the fast PHP-FPM handler can run the full web-panel application on an error. The benchmark isolates the selected XML endpoint. PHP-FPM still handles other work such as call records during the Octane test, so both comparisons use the same pool settings.
