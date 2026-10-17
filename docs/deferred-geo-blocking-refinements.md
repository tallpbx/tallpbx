# Deferred Geo-Blocking Refinements

This document preserves the deferred minor findings identified during the final review of the v4.0.1/v4.0.2 Geo-Blocking subsystem remediation. These items were accepted and deliberately separated from the core safety fixes per the single-pass remediation rule, preserved here for future implementation.

## Deferred Findings

| ID | Severity | Location | Summary |
| --- | --- | --- | --- |
| **DM-1** | Minor | `ManagesGeoBlocking::saveGeoSettings` (blacklist branch after whitelist check at ~L237) | A failed blacklist-mode sync falls through to the success toast and standard audit log — the administrator sees "settings saved" while the new country's zone download failed (fail-open by design, but `setGeoEnabled` surfaces a warning toast in the same situation; inconsistent user feedback). |
| **DM-2** | Minor | `GeoBlockingService::mergeWithExternalBlocklistPending` (read at ~L236–L241, lock at ~L271) | The existing blocklist file content is read *before* the exclusive `flock` is acquired. If a `ThreatFeedIngestionService` rebuild finishes between the unlocked read and the locked write, that merge could be written from a stale snapshot. (The write itself is atomic and any lost feed elements self-heal on the next hourly threat-feed rebuild). |
| **DM-3** | Minor / Design | `GeoBlockingService::sync` promotion (`compileSetElementFile` + `updateExternalBlocklist`) vs subsequent ruleset apply | On a whitelist→blacklist mode switch, there is a bounded (apply-latency, milliseconds-scale) window where the kernel sets already hold the new-mode compilation while the running chain still references the previous mode's sets. Eliminating this requires per-mode set namespaces (e.g. `geo_allowed_ips` and `geo_blocked_ips` both populated, with the chain selecting by mode) or pre-provisioned generational set swaps. |

## Suggested Remediation Specifications

- **DM-1**:
  - In `saveGeoSettings()`, after `$result = app(GeoBlockingService::class)->sync($rule, force: false)`, add a check for blacklist mode when `$result['status'] !== 'success'`.
  - Dispatch a warning toast to mirror `setGeoEnabled` (~L171–L173): `notifyError(__('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']))` or a warning notification.
  - Record the partial-failure detail in the `geo_blocking_updated` audit log description (e.g. `sync: {$result['status']}`).
- **DM-2**:
  - Move the read inside the lock in `mergeWithExternalBlocklistPending()`.
  - Open the file with `fopen($externalPending, 'c+')`, acquire `flock($handle, LOCK_EX)`, read existing content via `stream_get_contents($handle)`, rewind, filter, truncate via `ftruncate($handle, 0)`, write `$combined`, flush, and close/unlock.
- **DM-3**:
  - Architectural redesign rather than a patch.
  - Design dual-populated mode sets selected by chain rules or generational set pairs swapped by a single atomic nftables transaction.
