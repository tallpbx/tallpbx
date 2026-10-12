<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: pin-numbers
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'pin-numbers_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_pin_number_title' => 'Supprimer le code PIN ?',
    'modal_delete_pin_number_confirm' => 'Supprimer le code PIN',
    'pin_numbers_title' => 'Numéros PIN',
    'create_pin_number' => 'Créer un Numéro PIN',
    'edit_pin_number' => 'Modifier le Numéro PIN',
    'no_pin_numbers_found' => 'Aucun numéro PIN trouvé.',
    'confirm_delete_pin_number' => 'Êtes-vous sûr de vouloir supprimer ce numéro PIN ?',
];
