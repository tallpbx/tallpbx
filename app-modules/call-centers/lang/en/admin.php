<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-centers
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-centers_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_center_queues_title' => 'Call Center Queues',
    'call_center_queue_name' => 'Name',
    'call_center_queue_strategy' => 'Strategy',
    'call_center_queue_timeout' => 'Timeout',
    'call_center_queue_moh' => 'Music on Hold',
    'call_center_pause' => 'Pause',
    'call_center_unpause' => 'Resume',
    'call_center_logout' => 'Log Out',
];
