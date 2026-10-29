<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: file-stores
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'file-stores_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_file_store_title' => '¿Eliminar almacén de archivos?',
    'modal_delete_file_store_confirm' => 'Eliminar almacén de archivos',
    'modal_delete_file_store_message' => 'Eliminar “:name” de TallPBX. Esto solo elimina el perfil de conexión guardado; no elimina archivos del servidor remoto.',
    'modal_delete_file_store_error_title' => 'El almacén de archivos no se puede eliminar',
    'file_store_path' => 'Ruta',
    'file_store_bucket' => 'Cubo (Bucket)',
    'file_store_region' => 'Región',
    'file_store_access_key' => 'Clave de Acceso',
    'file_store_secret_key' => 'Clave Secreta',
    'file_store_endpoint' => 'Punto de Conexión Compatible con S3',
    'file_store_host' => 'Host',
    'file_store_username' => 'Nombre de Usuario',
    'file_store_port' => 'Puerto',
    'file_store_private_key' => 'Clave Privada',
    'file_store_passphrase' => 'Frase de Contraseña de la Clave Privada',
    'file_store_host_fingerprint' => 'Huella Digital del Host',
    'file_store_access_token' => 'Token de Acceso',
    'file_store_recipient' => 'Destinatario',
    'file_store_sender' => 'Remitente Personalizado',
];
