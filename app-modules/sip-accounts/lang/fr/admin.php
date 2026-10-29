<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: sip-accounts
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'sip-accounts_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_accounts_title' => 'Comptes SIP',
    'create_sip_account' => 'Créer un Compte SIP',
    'edit_sip_account' => 'Modifier le Compte SIP',
    'no_sip_accounts_found' => 'Aucun compte SIP trouvé.',
    'confirm_delete_sip_account' => 'Êtes-vous sûr de vouloir supprimer ce compte SIP ?',
    'modal_delete_sip_account_title' => 'Supprimer le compte SIP ?',
    'modal_delete_sip_account_confirm' => 'Supprimer le compte SIP',
    'sip_account_identity_mode' => 'Mode d\'Identité',
    'sip_account_select_domain' => 'Sélectionner le domaine',
    'sip_account_auth_username' => 'Nom d\'Utilisateur d\'Authentification',
    'sip_account_auth_password' => 'Mot de Passe d\'Authentification',
];
