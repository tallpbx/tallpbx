<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: event-rate-limits
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'event-rate-limits_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'event_rate_limits_title' => 'Event Rate Limits',
    'event_rate_limits_description' => 'Configure event rate limiting for FreeSWITCH events to prevent system overload and event floods.',
    'event_rate_limits_tooltip' => 'FreeSWITCH generates real-time events for every call, registration, DTMF tone, and state change. Event Rate Limits protect the PBX from event storms or misconfigured devices flooding the system by capping incoming events per minute and burst volumes. They are separate from FreeSWITCH\'s own rate limits (the sessions-per-second setting and the limit dialplan application, which cap call and session rates) and from the OS kernel firewall, which blocks network packets. Event Rate Limits throttle internal telephony event processing to prevent PBX lockups.',
    'event_rate_limits_max_events' => 'Max Events Per Minute',
    'event_rate_limits_max_events_tooltip' => 'The maximum number of FreeSWITCH events allowed from a single source (IP address or SIP account) within one minute before rate limiting kicks in. Default is 300. Lower this on busy production systems to catch floods earlier; raise it for lightly loaded dev/staging environments where false positives are more disruptive than the protection is worth.',
    'event_rate_limits_burst' => 'Burst Limit',
    'event_rate_limits_burst_tooltip' => 'The maximum number of events a source can send in a single burst before the rate limiter engages, even if the per-minute average is below the threshold. Prevents short, intense spikes from overwhelming the system between rate-check intervals. Default is 50. A low burst limit catches rapid scans or brute-force registration attempts; a higher limit accommodates legitimate registration storms after a network outage.',
    'event_rate_limits_block_duration' => 'Block Duration (seconds)',
    'event_rate_limits_block_duration_tooltip' => 'How long (in seconds) a blocked source remains denied after triggering the rate limit. During this period, all events from that source are dropped. Default is 60 seconds. Shorter durations allow legitimate devices to recover quickly after a transient spike; longer durations provide stronger protection against persistent abusers.',
];
