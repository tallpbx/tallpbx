<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-blocks
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-blocks_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_blocks_title' => 'Bloqueos de Llamadas',
    'call_block' => 'Bloqueo de Llamada',
    'create_call_block' => 'Crear Bloqueo de Llamada',
    'edit_call_block' => 'Editar Bloqueo de Llamada',
    'no_call_blocks_found' => 'No se encontraron bloqueos de llamada.',
    'confirm_delete_call_block' => '¿Está seguro de que desea eliminar esta regla de bloqueo de llamada?',
    'call_block_cid_tooltip' => 'El patrón de identificación de llamadas que se va a bloquear, como una expresión regular. P. ej. ^15550000\\d{3}$ bloquea todos los números que comienzan con 15550000.',
    'modal_delete_call_block_title' => '¿Eliminar bloqueo de llamadas?',
    'modal_delete_call_block_confirm' => 'Eliminar bloqueo de llamadas',
];
