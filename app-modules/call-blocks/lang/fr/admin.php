<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-blocks
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-blocks_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_blocks_title' => 'Blocages d\'Appels',
    'call_block' => 'Blocage d\'Appel',
    'create_call_block' => 'Créer un Blocage d\'Appel',
    'edit_call_block' => 'Modifier le Blocage d\'Appel',
    'no_call_blocks_found' => 'Aucun blocage d\'appel trouvé.',
    'confirm_delete_call_block' => 'Êtes-vous sûr de vouloir supprimer cette règle de blocage d\'appel ?',
    'call_block_cid_tooltip' => 'Le modèle d\'identification de l\'appelant à bloquer, sous forme d\'expression régulière.',
    'modal_delete_call_block_title' => 'Supprimer le blocage d\'appel ?',
    'modal_delete_call_block_confirm' => 'Supprimer le blocage d\'appel',
];
