<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: feature-codes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'feature-codes_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'feature_codes_title' => 'Codes de Fonction',
    'create_feature_code' => 'Créer un Code de Fonction',
    'edit_feature_code' => 'Modifier le Code de Fonction',
    'feature_code_placeholder' => '*97',
    'feature_description' => 'Description',
    'no_feature_codes_found' => 'Aucun code de fonction trouvé.',
    'confirm_delete_feature_code' => 'Êtes-vous sûr de vouloir supprimer ce code de fonction ?',
    'modal_delete_feature_code_title' => 'Supprimer le code de fonction ?',
    'modal_delete_feature_code_confirm' => 'Supprimer le code de fonction',
    'feature_code_application_tooltip' => 'L\'application FreeSWITCH déclenchée par ce code de fonction, par ex. transfer, record_session ou voicemail.',
    'feature_code_data_tooltip' => 'Arguments transmis à l\'application FreeSWITCH, par ex. l\'ID de la boîte vocale ou le chemin du fichier d\'enregistrement.',
];
