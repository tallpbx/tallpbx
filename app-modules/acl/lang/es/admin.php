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
    'acl_title' => 'Listas de Control de Acceso',
    'acl_tooltip' => 'Controla qué redes y dispositivos de confianza pueden registrar teléfonos o conectar troncales SIP directamente dentro de FreeSWITCH. A diferencia del firewall del servidor que protege todo el sistema operativo del tráfico no deseado, las Listas de Control de Acceso se aplican específicamente a las funciones internas del sistema telefónico.',
    'create_acl' => 'Crear Lista de Control de Acceso',
    'edit_acl' => 'Editar Lista de Control de Acceso',
    'acl_cidr' => 'Red (CIDR)',
    'acl_action' => 'Acción',
    'acl_nodes' => 'Nodos',
    'acl_action_allow' => 'Permitir',
    'acl_action_deny' => 'Denegar',
    'no_acls_found' => 'No se encontraron listas de control de acceso.',
    'confirm_delete_acl' => '¿Está seguro de que desea eliminar esta lista de control de acceso?',
    'modal_delete_acl_title' => '¿Eliminar la regla de control de acceso?',
    'modal_delete_acl_confirm' => 'Eliminar regla de control de acceso',
];
