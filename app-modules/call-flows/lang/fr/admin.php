<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-flows
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-flows_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_flows_title' => 'Flux d\'Appels',
    'call_flow' => 'Flux d\'Appel',
    'create_call_flow' => 'Créer un Flux d\'Appel',
    'edit_call_flow' => 'Modifier le Flux d\'Appel',
    'call_flow_name' => 'Nom',
    'call_flow_extension' => 'Extension',
    'call_flow_destination_type' => 'Type de Destination',
    'call_flow_destination_id' => 'ID de Destination',
    'no_call_flows_found' => 'Aucun flux d\'appel trouvé.',
    'confirm_delete_call_flow' => 'Êtes-vous sûr de vouloir supprimer ce flux d\'appel ?',
    'call_flow_action_tooltip' => 'Ce qui se passe lorsque ce flux d\'appels est déclenché : transfert vers extension, annonce, pont vers un numéro externe, etc.',
    'modal_delete_call_flow_title' => 'Supprimer le flux d\'appel ?',
    'modal_delete_call_flow_confirm' => 'Supprimer le flux d\'appel',
];
