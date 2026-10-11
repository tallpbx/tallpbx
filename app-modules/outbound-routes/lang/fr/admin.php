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
    'outbound_routes_title' => 'Routes Sortantes',
    'create_outbound_route' => 'Créer une Route Sortante',
    'edit_outbound_route' => 'Modifier la Route Sortante',
    'no_outbound_routes_found' => 'Aucune route sortante trouvée.',
    'confirm_delete_outbound_route' => 'Êtes-vous sûr de vouloir supprimer cette route sortante ?',
    'tenant_outbound_routes' => 'Routes Sortantes',
    'modal_delete_outbound_route_title' => 'Supprimer la route sortante ?',
    'modal_delete_outbound_route_confirm' => 'Supprimer la route sortante',
];
