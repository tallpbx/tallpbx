<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: dialplan-tools
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'dialplan-tools_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'dialplan_tools_title' => 'Dialplan Tools',
    'dialplan_tools_description' => 'Test dialplan regular expressions against phone numbers.',
    'dialplan_tools_pattern' => 'Regex Pattern',
    'dialplan_tools_number' => 'Test Number',
    'dialplan_tools_test' => 'Test',
    'dialplan_tools_match' => 'Match!',
    'dialplan_tools_no_match' => 'No match',
    'dialplan_tools_captures' => 'Captured Groups:',
];
