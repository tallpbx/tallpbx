<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: ring-groups
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'ring-groups_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'ring_groups_title' => 'Ring Groups',
    'ring_group' => 'Ring Group',
    'create_ring_group' => 'Create Ring Group',
    'edit_ring_group' => 'Edit Ring Group',
    'ring_group_name' => 'Name',
    'ring_group_strategy' => 'Strategy',
    'ring_group_timeout' => 'Timeout',
    'ring_group_extensions' => 'Extensions',
    'no_ring_groups_found' => 'No ring groups found.',
    'confirm_delete_ring_group' => 'Are you sure you want to delete this ring group?',
    'ring_group_strategy_tooltip' => 'How calls are distributed: simultaneous rings all members at once, sequence tries them one by one, enterprise uses a weighted round-robin.',
    'ring_group_timeout_tooltip' => 'How long (in seconds) to ring each member or the whole group before moving to the next destination.',
    'modal_delete_ring_group_title' => 'Delete Ring Group?',
    'modal_delete_ring_group_confirm' => 'Delete Ring Group',
    'modal_delete_ring_group_message' => 'Delete “:name” and its members? This cannot be undone.',
    'ring_group_timeout_seconds' => 'Ring Timeout (seconds)',
];
