<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-templates
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-templates_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_email_template_title' => '¿Eliminar plantilla de correo?',
    'modal_delete_email_template_confirm' => 'Eliminar plantilla de correo',
    'template_name' => 'Nombre',
    'template_vendor' => 'Proveedor',
    'template_model' => 'Modelo',
    'template_file_path' => 'Ruta del Archivo',
    'email_templates_title' => 'Plantillas de Correo',
    'create_email_template' => 'Crear Plantilla de Correo',
    'email_template_name' => 'Nombre',
    'email_template_subject' => 'Asunto',
    'email_template_body' => 'Cuerpo',
    'no_email_templates_found' => 'No se encontraron plantillas de correo.',
    'confirm_delete_email_template' => '¿Está seguro de que desea eliminar esta plantilla?',
];
