<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: follow-me
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'follow-me_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'follow_me_title' => 'Follow Me',
    'create_follow_me' => 'Create Follow Me',
    'edit_follow_me' => 'Edit Follow Me',
    'follow_me_name' => 'Name',
    'follow_me_extension' => 'Extension',
    'follow_me_destination' => 'Destination',
    'follow_me_ring_timeout' => 'Ring Timeout',
    'no_follow_me_found' => 'No follow me records found.',
    'confirm_delete_follow_me' => 'Are you sure you want to delete this follow me record?',
    'follow_me_timeout_tooltip' => 'How long (in seconds) to ring each destination in the follow-me list before moving to the next.',
    'modal_delete_follow_me_title' => 'Delete Follow-Me Rule?',
    'modal_delete_follow_me_confirm' => 'Delete Follow-Me Rule',
];
