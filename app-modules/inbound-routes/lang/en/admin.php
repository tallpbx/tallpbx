<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: inbound-routes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'inbound_route_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'inbound_routes_title' => 'Inbound Routes',
    'create_inbound_route' => 'Create Inbound Route',
    'edit_inbound_route' => 'Edit Inbound Route',
    'no_inbound_routes_found' => 'No inbound routes found.',
    'confirm_delete_inbound_route' => 'Are you sure you want to delete this inbound route?',
    'tenant_inbound_routes' => 'Inbound Routes',
    'modal_delete_inbound_route_title' => 'Delete Inbound Route?',
    'modal_delete_inbound_route_confirm' => 'Delete Inbound Route',
    'inbound_route_name' => 'Route Name',
    'inbound_route_destination_number' => 'Destination Number (DID)',
    'inbound_route_action' => 'Action',
    'inbound_route_select_action' => 'Select action',
    'inbound_route_action_data' => 'Action Data',
    'inbound_route_priority' => 'Priority',
];
