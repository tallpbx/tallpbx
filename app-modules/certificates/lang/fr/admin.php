<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: certificates
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'certificates_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'certificates_title' => 'Gestionnaire de Certificats',
    'certificates_description' => 'Gérez le chiffrement SSL/TLS pour les portails web, la signalisation SIP et la téléphonie WebRTC.',
    'certificates_inventory' => 'Inventaire',
    'certificates_lets_encrypt' => 'Let\'s Encrypt',
    'certificates_custom' => 'Import Personnalisé',
    'certificates_self_signed' => 'Auto-signé',
    'certificates_dnsvault' => 'Coffre-fort DNS',
    'certificates_audit_logs' => 'Journaux d\'Audit',
    'certificates_active_web' => 'Portail Web (HTTPS :443)',
    'certificates_active_telephony' => 'Téléphonie (SIP TLS et WebRTC)',
    'certificates_active_badge' => 'Actif',
    'certificates_no_certificates' => 'Aucun certificat installé pour le moment.',
    'certificates_name_domain' => 'Nom / Domaine',
    'certificates_san_domains' => 'Domaines SAN',
    'certificates_expires_in' => 'Expire dans',
    'certificates_assigned_services' => 'Services Assignés',
];
