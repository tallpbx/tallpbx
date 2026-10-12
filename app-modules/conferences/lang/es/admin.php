<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: conferences
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'conferences_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'conferences_title' => 'Conferencias',
    'conference' => 'Conferencia',
    'create_conference' => 'Crear Conferencia',
    'edit_conference' => 'Editar Conferencia',
    'conference_name' => 'Nombre',
    'conference_profile' => 'Perfil',
    'conference_pin' => 'PIN',
    'conference_max_members' => 'Máximo de Miembros',
    'no_conferences_found' => 'No se encontraron conferencias.',
    'confirm_delete_conference' => '¿Está seguro de que desea eliminar esta conferencia?',
    'conference_members' => 'Miembros',
    'conference_pin_tooltip' => 'PIN opcional que los llamantes deben ingresar para unirse a esta sala de conferencias. Deje en blanco para acceso abierto.',
    'conference_max_members_tooltip' => 'Número máximo de participantes simultáneos en esta sala de conferencias.',
    'modal_delete_conference_title' => '¿Eliminar conferencia?',
    'modal_delete_conference_confirm' => 'Eliminar conferencia',
];
