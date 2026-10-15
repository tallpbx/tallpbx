<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: admin
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'admin_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'admin_panel' => 'Panel de Administración',
    'administrators_description' => 'Administre las cuentas de administrador del sistema y las membresías de grupos.',
    'admin_add' => 'Agregar Administrador',
    'admin_edit' => 'Editar Administrador',
    'admin_edit_description' => 'Configure las credenciales de administrador y los grupos asignados.',
    'admin_name' => 'Nombre',
    'admin_email' => 'Correo Electrónico',
    'admin_password' => 'Contraseña',
    'admin_password_help' => 'Deje en blanco para mantener la contraseña actual.',
    'admin_password_create_hint' => 'Debe tener al menos 8 caracteres.',
    'admin_password_edit_hint' => 'Deje en blanco para mantener la contraseña actual, o ingrese al menos 8 caracteres para establecer una nueva.',
    'admin_enabled' => 'Habilitado',
    'admin_groups' => 'Grupos del Sistema',
    'admin_delete' => 'Eliminar',
    'admin_delete_modal_title' => 'Eliminar Administrador',
    'admin_delete_confirm' => '¿Está seguro de que desea eliminar al administrador :email? Esta acción no se puede deshacer.',
    'admin_delete_self_error' => 'No puede eliminar su propia cuenta de administrador.',
    'admin_delete_last_super_error' => 'No se puede eliminar el único Super Administrador restante.',
    'admin_deleted_success' => 'Administrador eliminado exitosamente.',
    'admin_created_success' => 'Administrador creado exitosamente.',
    'admin_updated_success' => 'Administrador actualizado exitosamente.',
    'admin_cannot_disable_self' => 'No puede deshabilitar su propia cuenta de administrador.',
    'no_admins_found' => 'No se encontraron administradores.',

    // Modules workbench (Task 7): category headers, source badges, and
    // the custom-extension deletion flow. No root-dictionary collisions.
    'modules_category_core' => 'Administración central',
    'modules_category_custom' => 'Extensiones personalizadas',
    'modules_source' => 'Origen',
    'modules_builtin' => 'Integrado',
    'modules_custom' => 'Personalizado',
    'modules_vendor' => 'Proveedor',
    'modules_enabling' => 'Activando…',
    'modules_disabling' => 'Desactivando…',
    'modules_delete_extension' => 'Eliminar extensión',
    'modules_delete_confirm' => '¿Eliminar permanentemente la extensión personalizada ":name"? Esta acción no se puede deshacer: las extensiones personalizadas no se pueden restaurar.',
    'modules_protected_hint' => 'Los módulos protegidos no se pueden desinstalar.',
    'modules_delete_not_custom' => 'Solo las extensiones personalizadas se pueden eliminar desde el panel de módulos.',
    'modules_delete_failed' => 'No se pudo eliminar la extensión.',
];
