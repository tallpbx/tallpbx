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
    'certificates_title' => 'Certificate Manager',
    'certificates_description' => 'Manage SSL/TLS encryption for web portals, SIP signaling, and WebRTC telephony.',
    'certificates_inventory' => 'Inventory',
    'certificates_lets_encrypt' => 'Let\'s Encrypt',
    'certificates_custom' => 'Custom Import',
    'certificates_self_signed' => 'Self-Signed',
    'certificates_dnsvault' => 'DNS Vault',
    'certificates_audit_logs' => 'Audit Logs',
    'certificates_active_web' => 'Web Portal (HTTPS :443)',
    'certificates_active_telephony' => 'Telephony (SIP TLS & WebRTC)',
    'certificates_active_badge' => 'Active',
    'certificates_no_certificates' => 'No certificates installed yet.',
];
