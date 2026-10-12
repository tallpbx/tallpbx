<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: follow-me
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'follow-me_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'follow_me_title' => 'Suivez-moi',
    'create_follow_me' => 'Créer un Suivez-moi',
    'edit_follow_me' => 'Modifier le Suivez-moi',
    'follow_me_name' => 'Nom',
    'follow_me_extension' => 'Extension',
    'follow_me_destination' => 'Destination',
    'follow_me_ring_timeout' => 'Délai de Sonnerie',
    'no_follow_me_found' => 'Aucun enregistrement de suivez-moi trouvé.',
    'confirm_delete_follow_me' => 'Êtes-vous sûr de vouloir supprimer cet enregistrement de suivez-moi ?',
    'follow_me_timeout_tooltip' => 'Combien de temps (en secondes) faire sonner chaque destination dans la liste de suivez-moi avant de passer à la suivante.',
    'modal_delete_follow_me_title' => 'Supprimer la règle de suivi ?',
    'modal_delete_follow_me_confirm' => 'Supprimer la règle de suivi',
];
