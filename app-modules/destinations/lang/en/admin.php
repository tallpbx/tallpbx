<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: destinations
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'destinations_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'destinations_title' => 'Destinations',
    'destination' => 'Destination',
    'create_destination' => 'Create Destination',
    'edit_destination' => 'Edit Destination',
    'destination_type' => 'Type',
    'destination_dial_string' => 'Dial String',
    'destination_type_conference' => 'Conference',
    'destination_type_ivr' => 'IVR',
    'destination_type_ring_group' => 'Ring Group',
    'destination_type_call_flow' => 'Call Flow',
    'destination_number' => 'Destination Number',
    'no_destinations_found' => 'No destinations found.',
    'confirm_delete_destination' => 'Are you sure you want to delete this destination?',
    'destination_number_tooltip' => 'The number callers dial to reach this destination. Used in dialplan expressions for routing.',
    'modal_delete_destination_title' => 'Delete Destination?',
    'modal_delete_destination_confirm' => 'Delete Destination',
];
