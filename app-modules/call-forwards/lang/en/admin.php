<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-forwards
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-forwards_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_forwards_title' => 'Call Forwards',
    'call_forward' => 'Call Forward',
    'create_call_forward' => 'Create Call Forward',
    'edit_call_forward' => 'Edit Call Forward',
    'no_call_forwards_found' => 'No call forwards found.',
    'confirm_delete_call_forward' => 'Are you sure you want to delete this call forward rule?',
    'call_forward_type_tooltip' => 'Unconditional: always forward. Busy: forward only when the extension is on another call. No Answer: forward after ringing with no pickup.',
    'modal_delete_call_forward_title' => 'Delete Call Forward?',
    'modal_delete_call_forward_confirm' => 'Delete Call Forward',
    'modal_delete_call_forward_message' => 'Delete the call forward to “:name”? This cannot be undone.',
];
