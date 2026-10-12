<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: admin
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'admin_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'admin_panel' => 'Panneau d\'Administration',
    'administrators_description' => 'Gérer les comptes d\'administrateur système et les appartenances aux groupes.',
    'admin_add' => 'Ajouter un Administrateur',
    'admin_edit' => 'Modifier l\'Administrateur',
    'admin_edit_description' => 'Configurer les identifiants de l\'administrateur et les groupes système attribués.',
    'admin_name' => 'Nom',
    'admin_email' => 'E-mail',
    'admin_password' => 'Mot de passe',
    'admin_password_help' => 'Laisser vide pour conserver le mot de passe actuel.',
    'admin_password_create_hint' => 'Doit comporter au moins 8 caractères.',
    'admin_password_edit_hint' => 'Laisser vide pour conserver le mot de passe actuel, ou saisir au moins 8 caractères pour en définir un nouveau.',
    'admin_enabled' => 'Activé',
    'admin_groups' => 'Groupes Système',
    'admin_delete' => 'Supprimer',
    'admin_delete_modal_title' => 'Supprimer l\'Administrateur',
    'admin_delete_confirm' => 'Êtes-vous sûr de vouloir supprimer l\'administrateur :email ? Cette action est irréversible.',
    'admin_delete_self_error' => 'Vous ne pouvez pas supprimer votre propre compte administrateur.',
    'admin_delete_last_super_error' => 'Impossible de supprimer le seul Super Administrateur restant.',
    'admin_deleted_success' => 'Administrateur supprimé avec succès.',
    'admin_created_success' => 'Administrateur créé avec succès.',
    'admin_updated_success' => 'Administrateur mis à jour avec succès.',
    'admin_cannot_disable_self' => 'Vous ne pouvez pas désactiver votre propre compte administrateur.',
    'no_admins_found' => 'Aucun administrateur trouvé.',
];
