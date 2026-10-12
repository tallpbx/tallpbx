<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: conferences
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'conferences_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'conferences_title' => 'Conferences',
    'conference' => 'Conference',
    'create_conference' => 'Create Conference',
    'edit_conference' => 'Edit Conference',
    'conference_name' => 'Name',
    'conference_profile' => 'Profile',
    'conference_pin' => 'PIN',
    'conference_max_members' => 'Max Members',
    'no_conferences_found' => 'No conferences found.',
    'confirm_delete_conference' => 'Are you sure you want to delete this conference?',
    'conference_members' => 'Members',
    'conference_pin_tooltip' => 'Optional PIN that callers must enter to join this conference room. Leave blank for open access.',
    'conference_max_members_tooltip' => 'Maximum number of simultaneous participants in this conference room.',
    'modal_delete_conference_title' => 'Delete Conference?',
    'modal_delete_conference_confirm' => 'Delete Conference',
];
