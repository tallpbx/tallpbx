<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: pin-numbers
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'pin-numbers_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'pin_numbers_title' => 'PIN Numbers',
    'create_pin_number' => 'Create PIN Number',
    'edit_pin_number' => 'Edit PIN Number',
    'no_pin_numbers_found' => 'No PIN numbers found.',
    'confirm_delete_pin_number' => 'Are you sure you want to delete this PIN number?',
    'modal_delete_pin_number_title' => 'Delete PIN Number?',
    'modal_delete_pin_number_confirm' => 'Delete PIN Number',
];
