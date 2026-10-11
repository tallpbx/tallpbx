<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Passerelles (gateways)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'gateway_host', 'create_gateway', 'no_gateways_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'gateways_title' => 'Passerelles',
    'create_gateway' => 'Créer une Passerelle',
    'edit_gateway' => 'Modifier la Passerelle',
    'gateway_name' => 'Nom de la Passerelle',
    'gateway_username' => 'Nom d\'utilisateur',
    'gateway_password' => 'Mot de Passe',
    'gateway_host' => 'Hôte',
    'gateway_proxy' => 'Proxy',
    'gateway_register' => 'Enregistrement',
    'gateway_registered' => 'Oui',
    'gateway_not_registered' => 'Non',
    'gateway_proxy_placeholder' => 'sip.fournisseur.com',
    'no_gateways_found' => 'Aucune passerelle trouvée.',
    'confirm_delete_gateway' => 'Êtes-vous sûr de vouloir supprimer cette passerelle ?',
    'gateway_name_tooltip' => 'Un nom court et unique pour cette passerelle. Utilisé dans la configuration Sofia et le routage sortant.',
    'gateway_host_tooltip' => 'Le nom d\'hôte ou l\'adresse IP du fournisseur de tronc SIP, par ex. sip.monfournisseur.com.',
    'gateway_register_tooltip' => 'Indique s\'il faut envoyer des requêtes SIP REGISTER à cette passerelle. Désactiver pour les troncs authentifiés par IP.',
    'modal_delete_gateway_title' => 'Supprimer la passerelle ?',
    'modal_delete_gateway_confirm' => 'Supprimer la passerelle',
];
