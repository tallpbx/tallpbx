<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: acl
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'acl_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'acl_title' => 'Access Control Lists',
    'acl_tooltip' => 'Controls which trusted networks and devices can register phones or connect SIP trunks directly inside FreeSWITCH. Unlike the server firewall which protects the entire operating system from unwanted traffic, Access Control Lists apply specifically to internal phone system features.',
    'create_acl' => 'Create Access Control List',
    'edit_acl' => 'Edit Access Control List',
    'acl_cidr' => 'Network (CIDR)',
    'acl_action' => 'Action',
    'acl_nodes' => 'Nodes',
    'acl_action_allow' => 'Allow',
    'acl_action_deny' => 'Deny',
    'no_acls_found' => 'No access control lists found.',
    'confirm_delete_acl' => 'Are you sure you want to delete this access control list?',
    'modal_delete_acl_title' => 'Delete Access Control Rule?',
    'modal_delete_acl_confirm' => 'Delete Access Control Rule',
];
