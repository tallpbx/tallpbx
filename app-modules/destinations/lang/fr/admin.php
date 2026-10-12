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
    'create_destination' => 'Créer une Destination',
    'edit_destination' => 'Modifier la Destination',
    'destination_type' => 'Type',
    'destination_dial_string' => 'Chaîne de Numérotation',
    'destination_type_conference' => 'Conférence',
    'destination_type_ivr' => 'IVR',
    'destination_type_ring_group' => 'Groupe de Sonnerie',
    'destination_type_call_flow' => 'Flux d\'Appel',
    'destination_number' => 'Numéro',
    'no_destinations_found' => 'Aucune destination trouvée.',
    'confirm_delete_destination' => 'Êtes-vous sûr de vouloir supprimer cette destination ?',
    'destination_number_tooltip' => 'Le numéro composé par les appelants pour atteindre cette destination. Utilisé dans les expressions du plan de numérotation pour le routage.',
    'modal_delete_destination_title' => 'Supprimer la destination ?',
    'modal_delete_destination_confirm' => 'Supprimer la destination',
];
