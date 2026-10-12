<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: conferences
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'conferences_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'conferences_title' => 'Conférences',
    'conference' => 'Conférence',
    'create_conference' => 'Créer une Conférence',
    'edit_conference' => 'Modifier la Conférence',
    'conference_name' => 'Nom',
    'conference_profile' => 'Profil',
    'conference_pin' => 'PIN',
    'conference_max_members' => 'Membres Maximum',
    'no_conferences_found' => 'Aucune conférence trouvée.',
    'confirm_delete_conference' => 'Êtes-vous sûr de vouloir supprimer cette conférence ?',
    'conference_members' => 'Membres',
    'conference_pin_tooltip' => 'PIN facultatif que les appelants doivent saisir pour rejoindre cette salle de conférence. Laisser vide pour un accès ouvert.',
    'conference_max_members_tooltip' => 'Nombre maximum de participants simultanés dans cette salle de conférence.',
    'modal_delete_conference_title' => 'Supprimer la conférence ?',
    'modal_delete_conference_confirm' => 'Supprimer la conférence',
];
