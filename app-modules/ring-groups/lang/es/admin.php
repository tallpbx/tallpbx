<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: ring-groups
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'ring-groups_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'ring_groups_title' => 'Grupos de Timbrado',
    'ring_group' => 'Grupo de Timbrado',
    'create_ring_group' => 'Crear Grupo de Timbrado',
    'edit_ring_group' => 'Editar Grupo de Timbrado',
    'ring_group_name' => 'Nombre',
    'ring_group_strategy' => 'Estrategia',
    'ring_group_timeout' => 'Tiempo de Espera',
    'ring_group_extensions' => 'Extensiones',
    'no_ring_groups_found' => 'No se encontraron grupos de timbrado.',
    'confirm_delete_ring_group' => '¿Está seguro de que desea eliminar este grupo de timbrado?',
    'ring_group_strategy_tooltip' => 'Cómo se distribuyen las llamadas: simultánea hace sonar a todos los miembros a la vez, secuencia los prueba uno por uno, empresa utiliza round-robin ponderado.',
    'ring_group_timeout_tooltip' => 'Cuánto tiempo (en segundos) hacer sonar a cada miembro o a todo el grupo antes de pasar al siguiente destino.',
    'modal_delete_ring_group_title' => '¿Eliminar grupo de timbrado?',
    'modal_delete_ring_group_confirm' => 'Eliminar grupo de timbrado',
    'modal_delete_ring_group_message' => '¿Eliminar “:name” y sus miembros? Esta acción no se puede deshacer.',
    'ring_group_timeout_seconds' => 'Tiempo de Espera de Timbrado (segundos)',
];
