<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: dialplans
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'dialplan_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'dialplans_title' => 'Dialplans',
    'dialplan' => 'Dialplan',
    'create_dialplan' => 'Create Dialplan',
    'edit_dialplan' => 'Edit Dialplan',
    'dialplan_name' => 'Name',
    'dialplan_context' => 'Context',
    'dialplan_details' => 'Details',
    'dialplan_rules' => 'rules',
    'dialplan_context_placeholder' => 'default',
    'dialplan_order' => 'Order',
    'no_dialplans_found' => 'No dialplans found.',
    'confirm_delete_dialplan' => 'Are you sure you want to delete this dialplan?',
    'modal_delete_dialplan_title' => 'Delete Dialplan?',
    'modal_delete_dialplan_confirm' => 'Delete Dialplan',
    'modal_delete_dialplan_message' => 'Delete “:name” and its rules? This cannot be undone.',
];
