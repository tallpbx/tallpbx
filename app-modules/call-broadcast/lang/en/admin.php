<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-broadcast
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-broadcast_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'create_call_broadcast' => 'Create Broadcast',
    'call_broadcast_name' => 'Name',
    'call_broadcast_recipients' => 'Recipients',
    'call_broadcast_phone_numbers' => 'Phone Numbers',
    'call_broadcast_date' => 'Date',
    'call_broadcast_draft' => 'Draft',
    'call_broadcast_sending' => 'Sending',
    'call_broadcast_answered' => 'answered',
    'call_broadcast_completed' => 'Completed',
    'call_broadcast_failed' => 'Failed',
    'no_call_broadcasts_found' => 'No call broadcasts found.',
    'confirm_delete_call_broadcast' => 'Are you sure you want to delete this broadcast?',
    'modal_delete_call_broadcast_title' => 'Delete Call Broadcast?',
    'modal_delete_call_broadcast_confirm' => 'Delete Call Broadcast',
];
