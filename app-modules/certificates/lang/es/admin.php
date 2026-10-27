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
    'certificates_title' => 'Gestor de Certificados',
    'certificates_description' => 'Gestione el cifrado SSL/TLS para portales web, señalización SIP y telefonía WebRTC.',
    'certificates_inventory' => 'Inventario',
    'certificates_lets_encrypt' => 'Let\'s Encrypt',
    'certificates_custom' => 'Importación Personalizada',
    'certificates_self_signed' => 'Autofirmado',
    'certificates_dnsvault' => 'Bóveda DNS',
    'certificates_audit_logs' => 'Registros de Auditoría',
    'certificates_active_web' => 'Portal Web (HTTPS :443)',
    'certificates_active_telephony' => 'Telefonía (SIP TLS y WebRTC)',
    'certificates_active_badge' => 'Activo',
    'certificates_no_certificates' => 'No hay certificados instalados todavía.',
    'certificates_name_domain' => 'Nombre / Dominio',
    'certificates_san_domains' => 'Dominios SAN',
    'certificates_expires_in' => 'Expira en',
    'certificates_assigned_services' => 'Servicios Asignados',
    'certificates_delete_action' => 'Eliminar',
    'certificates_delete_confirm_title' => '¿Eliminar certificado?',
    'certificates_delete_confirm_message' => '¿Está seguro de que desea eliminar el certificado ":name"? Esta acción no se puede deshacer.',
    'certificates_delete_credential_confirm_title' => '¿Eliminar credencial DNS?',
    'certificates_delete_credential_confirm_message' => '¿Está seguro de que desea eliminar la credencial DNS ":name"?',
];
