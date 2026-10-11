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
    'inbound_routes_title' => 'Routes Entrantes',
    'create_inbound_route' => 'Créer une Route Entrante',
    'edit_inbound_route' => 'Modifier la Route Entrante',
    'no_inbound_routes_found' => 'Aucune route entrante trouvée.',
    'confirm_delete_inbound_route' => 'Êtes-vous sûr de vouloir supprimer cette route entrante ?',
    'tenant_inbound_routes' => 'Routes Entrantes',
    'modal_delete_inbound_route_title' => 'Supprimer la route entrante ?',
    'modal_delete_inbound_route_confirm' => 'Supprimer la route entrante',
];
