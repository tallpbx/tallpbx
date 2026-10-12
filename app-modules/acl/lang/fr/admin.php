<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: acl
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'acl_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'acl_title' => 'Listes de Contrôle d\'Accès',
    'acl_tooltip' => 'Contrôle quels réseaux et appareils de confiance peuvent enregistrer des téléphones ou connecter des liaisons SIP directement dans FreeSWITCH. Contrairement au pare-feu du serveur qui protège l\'ensemble du système d\'exploitation contre le trafic indésirable, les Listes de Contrôle d\'Accès s\'appliquent spécifiquement aux fonctionnalités internes du système téléphonique.',
    'create_acl' => 'Créer une Liste de Contrôle d\'Accès',
    'edit_acl' => 'Modifier la Liste de Contrôle d\'Accès',
    'acl_cidr' => 'Réseau (CIDR)',
    'acl_action' => 'Action',
    'acl_nodes' => 'Nœuds',
    'acl_action_allow' => 'Autoriser',
    'acl_action_deny' => 'Refuser',
    'no_acls_found' => 'Aucune liste de contrôle d\'accès trouvée.',
    'confirm_delete_acl' => 'Êtes-vous sûr de vouloir supprimer cette liste de contrôle d\'accès ?',
    'modal_delete_acl_title' => 'Supprimer la règle de contrôle d\'accès ?',
    'modal_delete_acl_confirm' => 'Supprimer la règle de contrôle d\'accès',
];
