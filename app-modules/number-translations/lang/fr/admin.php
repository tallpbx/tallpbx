<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: number-translations
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'number-translations_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'number_translations_title' => 'Traductions de Numéros',
    'number_translation' => 'Traduction de Numéro',
    'create_number_translation' => 'Créer une Traduction de Numéro',
    'number_translation_name' => 'Nom',
    'number_translation_match' => 'Modèle de Correspondance',
    'number_translation_replace' => 'Modèle de Remplacement',
    'number_translation_direction' => 'Direction',
    'number_translation_enabled' => 'Activé',
    'no_number_translations_found' => 'Aucune traduction trouvée.',
    'confirm_delete_number_translation' => 'Êtes-vous sûr de vouloir supprimer cette traduction ?',
    'modal_delete_number_translation_title' => 'Supprimer la traduction de numéro ?',
    'modal_delete_number_translation_confirm' => 'Supprimer la traduction de numéro',
];
