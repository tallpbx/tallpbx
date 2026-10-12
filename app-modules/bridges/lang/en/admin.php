<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: bridges
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'bridges_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'bridges_title' => 'Bridges',
    'bridge' => 'Bridge',
    'create_bridge' => 'Create Bridge',
    'edit_bridge' => 'Edit Bridge',
    'bridge_name' => 'Bridge Name',
    'no_bridges_found' => 'No bridges found.',
    'confirm_delete_bridge' => 'Are you sure you want to delete this bridge?',
    'bridge_destination_tooltip' => 'The phone number or SIP URI this bridge endpoint connects to when dialed.',
    'bridge_pin_tooltip' => 'Optional PIN callers must enter before the bridge connects. Used to restrict outbound calling through the bridge.',
    'modal_delete_bridge_title' => 'Delete Bridge?',
    'modal_delete_bridge_confirm' => 'Delete Bridge',
];
