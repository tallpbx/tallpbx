<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: tenant
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'tenant_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'tenants_title' => 'Inquilinos',
    'create_tenant' => 'Crear Inquilino',
    'edit_tenant' => 'Editar Inquilino',
    'tenant_name' => 'Nombre del Inquilino',
    'tenant_email' => 'Correo del Inquilino',
    'tenant_slug' => 'Slug',
    'tenant_provisioning' => 'URL de Aprovisionamiento',
    'no_tenants_found' => 'No se encontraron inquilinos.',
    'confirm_delete_tenant' => '¿Está seguro de que desea eliminar este inquilino?',
    'tenant_dashboard' => 'Panel',
    'tenant_domains_title' => 'Dominios de Inquilino',
    'modal_delete_tenant_title' => '¿Eliminar inquilino?',
    'modal_delete_tenant_confirm' => 'Eliminar inquilino',
    'modal_delete_tenant_message' => 'Esto elimina TODOS los datos de “:name”. Escriba el nombre del inquilino para confirmar.',
    'modal_delete_tenant_error_title' => 'El inquilino no se puede eliminar',
    'modal_delete_tenant_domain_title' => '¿Eliminar dominio?',
    'modal_delete_tenant_domain_confirm' => 'Eliminar dominio',
    'modal_delete_tenant_limit_title' => '¿Eliminar límite de inquilino?',
    'modal_delete_tenant_limit_confirm' => 'Eliminar límite de inquilino',
    'tenants_count_tooltip' => 'Número de organizaciones inquilinas a las que pertenece este usuario.',
    'tenant_slug_tooltip' => 'Identificador único apto para URL de este inquilino.',
    'tenant_status_tooltip' => 'Indica si esta cuenta de inquilino está activa o deshabilitada.',
    'tenant_limit' => 'Límite de Inquilino',
];
