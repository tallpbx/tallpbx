<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-flows
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-flows_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_flows_title' => 'Call Flows',
    'call_flow' => 'Call Flow',
    'create_call_flow' => 'Create Call Flow',
    'edit_call_flow' => 'Edit Call Flow',
    'call_flow_name' => 'Name',
    'call_flow_extension' => 'Extension',
    'call_flow_destination_type' => 'Destination Type',
    'call_flow_destination_id' => 'Destination ID',
    'no_call_flows_found' => 'No call flows found.',
    'confirm_delete_call_flow' => 'Are you sure you want to delete this call flow?',
    'call_flow_action_tooltip' => 'What happens when this call flow is triggered — transfer to extension, play announcement, bridge to external number, etc.',
    'modal_delete_call_flow_title' => 'Delete Call Flow?',
    'modal_delete_call_flow_confirm' => 'Delete Call Flow',
];
