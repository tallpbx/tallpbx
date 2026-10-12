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
    'time_conditions_title' => 'Conditions Horaires',
    'time_condition' => 'Condition Horaire',
    'create_time_condition' => 'Créer une Condition Horaire',
    'edit_time_condition' => 'Modifier la Condition Horaire',
    'time_condition_name' => 'Nom',
    'time_condition_weekdays' => 'Jours de la Semaine',
    'time_condition_hours' => 'Heures',
    'time_condition_start_time' => 'Heure de Début',
    'time_condition_end_time' => 'Heure de Fin',
    'time_condition_match_destination' => 'Destination si Correspondance',
    'time_condition_no_match_destination' => 'Destination si Pas de Correspondance',
    'time_condition_timezone' => 'Fuseau Horaire',
    'no_time_conditions_found' => 'Aucune condition horaire trouvée.',
    'confirm_delete_time_condition' => 'Êtes-vous sûr de vouloir supprimer cette condition horaire ?',
    'time_condition_wday_tooltip' => 'Jours de la semaine auxquels s\'applique cette condition. Utilisez 1-7 (lun-dim) ou des plages comme 1-5 pour les jours ouvrables.',
    'time_condition_hour_tooltip' => 'Plage horaire au format 24h, par ex. 8:00-17:00 pour les heures de bureau uniquement.',
    'modal_delete_time_condition_title' => 'Supprimer la condition horaire ?',
    'modal_delete_time_condition_confirm' => 'Supprimer la condition horaire',
];
