# Deferred Geo-Blocking Refinements

This document preserves the deferred findings identified during the final review of the v4.0.1/v4.0.2 Geo-Blocking subsystem remediation.

## Status Summary

| ID | Severity | Status | Location | Summary |
| --- | --- | --- | --- | --- |
| **DM-1** | Minor | **Resolved** | `ManagesGeoBlocking::saveGeoSettings` | Added warning toast and recorded sync status in the audit log when blacklist-mode sync fails, matching `setGeoEnabled` feedback. |
| **DM-2** | Minor | **Resolved** | `GeoBlockingService::mergeWithExternalBlocklistPending` | Moved read inside the exclusive `flock` (`stream_get_contents`), ensuring race-free read-modify-write against concurrent threat-feed updates. |
| **DM-3** | Minor / Design | **Deferred (Architectural)** | `GeoBlockingService::sync` promotion vs ruleset apply | On a whitelist→blacklist mode switch, a bounded (apply-latency) window exists where kernel sets hold new-mode compilation while running chain still references previous mode sets. Requires a dedicated architectural design. |

---

## Architectural Task: DM-3 Specification

### Context
When switching Geo-Blocking mode on an armed rule (e.g. from `whitelist` to `blacklist`), `GeoBlockingService::sync` compiles the new mode's set elements and promotes them into the kernel via `updateExternalBlocklist()`. Immediately following this, `ManagesGeoBlocking::regenerateAndApplyFirewall()` compiles and loads the updated nftables ruleset into the kernel.

During the millisecond latency window between `updateExternalBlocklist()` and `regenerateAndApplyFirewall()`, the running kernel chain (`chain geo_perimeter`) still tests against `@geo_allowed_ips`, while the sets in the kernel have already been flushed and replaced with `@geo_blocked_ips`. In whitelist mode, an empty allow-set causes all inbound traffic to be dropped until the chain rule itself is updated.

### Proposed Solutions for Future Architectural Refactor
1. **Dual-Populated Mode Sets Selected by Subchain**:
   - Maintain both sets populated simultaneously or populate the destination set *before* switching the subchain rule, and flush the obsolete set *after* the subchain rule switches.
2. **Atomic Generational Set/Chain Swap**:
   - Introduce versioned set names (e.g. `geo_ips_gen_a` and `geo_ips_gen_b`) with an atomic nftables swap transaction changing both the rule target and set reference simultaneously.
