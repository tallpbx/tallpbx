<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: time-conditions
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'time-conditions_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'time_conditions_title' => 'Condiciones Horarias',
    'time_condition' => 'Condición Horaria',
    'create_time_condition' => 'Crear Condición Horaria',
    'edit_time_condition' => 'Editar Condición Horaria',
    'time_condition_name' => 'Nombre',
    'time_condition_weekdays' => 'Días de la Semana',
    'time_condition_hours' => 'Horas',
    'time_condition_start_time' => 'Hora de Inicio',
    'time_condition_end_time' => 'Hora de Fin',
    'time_condition_match_destination' => 'Destino si Coincide',
    'time_condition_no_match_destination' => 'Destino si No Coincide',
    'time_condition_timezone' => 'Zona Horaria',
    'no_time_conditions_found' => 'No se encontraron condiciones horarias.',
    'confirm_delete_time_condition' => '¿Está seguro de que desea eliminar esta condición horaria?',
    'time_condition_wday_tooltip' => 'Días de la semana en los que se aplica esta condición. Utilice 1-7 (lun-dom) o rangos como 1-5 para días laborables.',
    'time_condition_hour_tooltip' => 'Rango de tiempo en formato de 24 horas, p. ej. 8:00-17:00 significa solo horario comercial.',
    'modal_delete_time_condition_title' => '¿Eliminar condición de tiempo?',
    'modal_delete_time_condition_confirm' => 'Eliminar condición de tiempo',
];
