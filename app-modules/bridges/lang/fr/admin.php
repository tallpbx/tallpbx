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
    'bridges_title' => 'Ponts',
    'bridge' => 'Pont',
    'create_bridge' => 'Créer un Pont',
    'edit_bridge' => 'Modifier le Pont',
    'bridge_name' => 'Nom du Pont',
    'no_bridges_found' => 'Aucun pont trouvé.',
    'confirm_delete_bridge' => 'Êtes-vous sûr de vouloir supprimer ce pont ?',
    'bridge_destination_tooltip' => 'Le numéro de téléphone ou l\'URI SIP auquel ce point de terminaison de pont se connecte lorsqu\'il est composé.',
    'bridge_pin_tooltip' => 'PIN facultatif que les appelants doivent saisir avant la connexion du pont. Utilisé pour restreindre les appels sortants via le pont.',
    'modal_delete_bridge_title' => 'Supprimer le pont ?',
    'modal_delete_bridge_confirm' => 'Supprimer le pont',
];
