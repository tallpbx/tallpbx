<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: outbound-routes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'outbound_route_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'outbound_routes_title' => 'Outbound Routes',
    'create_outbound_route' => 'Create Outbound Route',
    'edit_outbound_route' => 'Edit Outbound Route',
    'no_outbound_routes_found' => 'No outbound routes found.',
    'confirm_delete_outbound_route' => 'Are you sure you want to delete this outbound route?',
    'tenant_outbound_routes' => 'Outbound Routes',
    'modal_delete_outbound_route_title' => 'Delete Outbound Route?',
    'modal_delete_outbound_route_confirm' => 'Delete Outbound Route',
    'outbound_route_name' => 'Route Name',
    'outbound_route_dial_pattern' => 'Dial Pattern (regex)',
    'outbound_route_gateway' => 'Gateway',
    'outbound_route_external_fallback' => 'Use external profile fallback',
    'outbound_route_legacy_gateway' => 'Legacy Gateway Name',
    'outbound_route_caller_id_name' => 'Caller ID Name',
    'outbound_route_caller_id_number' => 'Caller ID Number',
    'outbound_route_priority' => 'Priority',
];
