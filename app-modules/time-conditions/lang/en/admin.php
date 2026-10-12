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
    'time_conditions_title' => 'Time Conditions',
    'time_condition' => 'Time Condition',
    'create_time_condition' => 'Create Time Condition',
    'edit_time_condition' => 'Edit Time Condition',
    'time_condition_name' => 'Name',
    'time_condition_weekdays' => 'Weekdays',
    'time_condition_hours' => 'Hours',
    'time_condition_start_time' => 'Start Time',
    'time_condition_end_time' => 'End Time',
    'time_condition_match_destination' => 'Match Destination',
    'time_condition_no_match_destination' => 'No Match Destination',
    'time_condition_timezone' => 'Timezone',
    'no_time_conditions_found' => 'No time conditions found.',
    'confirm_delete_time_condition' => 'Are you sure you want to delete this time condition?',
    'time_condition_wday_tooltip' => 'Days of the week this condition applies. Use 1-7 (Mon-Sun) or ranges like 1-5 for weekdays.',
    'time_condition_hour_tooltip' => 'Time range in 24-hour format, e.g. 8:00-17:00 means business hours only.',
    'modal_delete_time_condition_title' => 'Delete Time Condition?',
    'modal_delete_time_condition_confirm' => 'Delete Time Condition',
];
