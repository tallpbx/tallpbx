<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: conference-centers
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'conference-centers_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'conference_centers_title' => 'Conference Centers',
    'conference_center' => 'Conference Center',
    'create_conference_center' => 'Create Conference Center',
    'edit_conference_center' => 'Edit Conference Center',
    'conference_center_name' => 'Name',
    'conference_center_extension' => 'Extension',
    'conference_center_pin' => 'PIN',
    'conference_center_greeting' => 'Greeting',
    'no_conference_centers_found' => 'No conference centers found.',
    'confirm_delete_conference_center' => 'Are you sure you want to delete this conference center?',
    'modal_delete_conference_center_title' => 'Delete Conference Center?',
    'modal_delete_conference_center_confirm' => 'Delete Conference Center',
];
