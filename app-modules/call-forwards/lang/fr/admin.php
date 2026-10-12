<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-forwards
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-forwards_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_forwards_title' => 'Renvois d\'Appel',
    'call_forward' => 'Renvoi d\'Appel',
    'create_call_forward' => 'Créer un Renvoi d\'Appel',
    'edit_call_forward' => 'Modifier le Renvoi d\'Appel',
    'no_call_forwards_found' => 'Aucun renvoi d\'appel trouvé.',
    'confirm_delete_call_forward' => 'Êtes-vous sûr de vouloir supprimer cette règle de renvoi d\'appel ?',
    'call_forward_type_tooltip' => 'Inconditionnel : toujours renvoyer. Occupé : renvoyer uniquement lorsque l\'extension est occupée. Non réponse : renvoyer après sonnerie sans réponse.',
    'modal_delete_call_forward_title' => 'Supprimer le renvoi d\'appel ?',
    'modal_delete_call_forward_confirm' => 'Supprimer le renvoi d\'appel',
    'modal_delete_call_forward_message' => 'Supprimer le renvoi d\'appel vers « :name » ? Cette action est irréversible.',
];
