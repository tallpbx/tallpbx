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
    'email_connector_title' => 'Configuración del Conector de Email',
    'email_connector_description' => 'Configure la entrega de correo saliente mediante autenticación SMTP estándar con nombre de usuario y contraseña o OAuth 2.0 (Google, Microsoft o proveedor personalizado).',
    'email_connector_tooltip' => 'Configure cómo la PBX envía correos electrónicos (restablecimientos de contraseña, notificaciones de correo de voz, informes de respaldo y alertas del sistema). Admite autenticación SMTP estándar con nombre de usuario/contraseña, así como OAuth 2.0 (Google, Microsoft o proveedores personalizados). Para autenticación con contraseña: ingrese su usuario y contraseña SMTP (o contraseña de aplicación). Para OAuth 2.0: seleccione su proveedor, ingrese su Client ID y Secret, y autorice.',
];
