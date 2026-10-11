<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: dialplans
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'dialplan_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'dialplans_title' => 'Planos de Marcado',
    'dialplan' => 'Plano de Marcado',
    'create_dialplan' => 'Crear Plano de Marcado',
    'edit_dialplan' => 'Editar Plano de Marcado',
    'dialplan_name' => 'Nombre',
    'dialplan_context' => 'Contexto',
    'dialplan_details' => 'Detalles',
    'dialplan_rules' => 'reglas',
    'dialplan_context_placeholder' => 'predeterminado',
    'dialplan_order' => 'Orden',
    'no_dialplans_found' => 'No se encontraron planos de marcado.',
    'confirm_delete_dialplan' => '¿Está seguro de que desea eliminar este plano de marcado?',
    'modal_delete_dialplan_title' => '¿Eliminar plan de marcado?',
    'modal_delete_dialplan_confirm' => 'Eliminar plan de marcado',
    'modal_delete_dialplan_message' => '¿Eliminar “:name” y sus reglas? Esta acción no se puede deshacer.',
];
