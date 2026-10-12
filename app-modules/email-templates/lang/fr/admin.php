<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-templates
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-templates_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_email_template_title' => 'Supprimer le modèle d\'e-mail ?',
    'modal_delete_email_template_confirm' => 'Supprimer le modèle d\'e-mail',
    'template_name' => 'Nom',
    'template_vendor' => 'Fournisseur',
    'template_model' => 'Modèle',
    'template_file_path' => 'Chemin du Fichier',
    'email_templates_title' => 'Modèles d\'Email',
    'create_email_template' => 'Créer un Modèle d\'Email',
    'email_template_name' => 'Nom',
    'email_template_subject' => 'Objet',
    'email_template_body' => 'Corps',
    'no_email_templates_found' => 'Aucun modèle d\'email trouvé.',
    'confirm_delete_email_template' => 'Êtes-vous sûr de vouloir supprimer ce modèle ?',
];
