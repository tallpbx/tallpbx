<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: emergency
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'emergency_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'emergency_title' => 'Emergency Configuration',
    'create_emergency' => 'Create Emergency Config',
    'emergency_caller' => 'Caller ID',
    'emergency_address' => 'Address',
    'emergency_latitude' => 'Latitude',
    'emergency_longitude' => 'Longitude',
    'confirm_delete_emergency' => 'Are you sure you want to delete this record?',
    'modal_delete_emergency_title' => 'Delete Emergency Record?',
    'modal_delete_emergency_confirm' => 'Delete Emergency Record',
    'modal_delete_emergency_message' => 'Delete the emergency record for “:name”? This cannot be undone.',
];
