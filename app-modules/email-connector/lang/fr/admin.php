<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-connector
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-connector_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'email_connector_title' => 'Configuration du Connecteur Email',
    'email_connector_description' => 'Configurez l\'envoi d\'emails sortants à l\'aide de l\'authentification SMTP standard par nom d\'utilisateur/mot de passe ou OAuth 2.0 (Google, Microsoft ou fournisseur personnalisé).',
    'email_connector_tooltip' => 'Configurez la manière dont le PBX envoie les emails : réinitialisations de mot de passe, notifications de messagerie vocale, rapports de sauvegarde et alertes système. Prend en charge l\'authentification SMTP standard par nom d\'utilisateur/mot de passe ainsi que OAuth 2.0 (Google, Microsoft ou fournisseurs personnalisés). Pour l\'authentification par mot de passe : saisissez votre nom d\'utilisateur et mot de passe SMTP (ou mot de passe d\'application). Pour OAuth 2.0 : sélectionnez votre fournisseur, saisissez votre Client ID et Secret, puis autorisez.',
];
