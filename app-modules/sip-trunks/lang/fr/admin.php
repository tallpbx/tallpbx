<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Troncs SIP (sip-trunks)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'sip_trunk_*', 'create_sip_trunk', 'no_sip_trunks_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_trunks_title' => 'Troncs SIP',
    'sip_trunk' => 'Tronc SIP',
    'create_sip_trunk' => 'Créer un Tronc SIP',
    'sip_trunk_name' => 'Nom',
    'sip_trunk_host' => 'Hôte',
    'sip_trunk_port' => 'Port',
    'sip_trunk_username' => 'Nom d\'utilisateur',
    'sip_trunk_password' => 'Mot de passe',
    'sip_trunk_codecs' => 'Codecs',
    'sip_trunk_enabled' => 'Activé',
    'no_sip_trunks_found' => 'Aucun tronc trouvé.',
    'confirm_delete_sip_trunk' => 'Êtes-vous sûr de vouloir supprimer ce tronc ?',
    'modal_delete_sip_trunk_title' => 'Supprimer la liaison SIP ?',
    'modal_delete_sip_trunk_confirm' => 'Supprimer la liaison SIP',
];
