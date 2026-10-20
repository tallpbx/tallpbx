<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: admin
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'admin_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'admin_panel' => 'Admin Panel',
    'administrators_description' => 'Manage system administrator accounts and system group memberships.',
    'admin_add' => 'Add Administrator',
    'admin_edit' => 'Edit Administrator',
    'admin_edit_description' => 'Configure administrator credentials and assigned system groups.',
    'admin_name' => 'Name',
    'admin_email' => 'Email',
    'admin_password' => 'Password',
    'admin_password_help' => 'Leave blank to keep current password.',
    'admin_password_create_hint' => 'Must be at least 8 characters.',
    'admin_password_edit_hint' => 'Leave blank to keep current password, or enter at least 8 characters to set a new one.',
    'admin_enabled' => 'Enabled',
    'admin_groups' => 'System Groups',
    'admin_delete' => 'Delete',
    'admin_delete_modal_title' => 'Delete Administrator',
    'admin_delete_confirm' => 'Are you sure you want to delete administrator :email? This action cannot be undone.',
    'admin_delete_self_error' => 'You cannot delete your own administrator account.',
    'admin_delete_last_super_error' => 'Cannot delete the only remaining Super Administrator.',
    'admin_deleted_success' => 'Administrator deleted successfully.',
    'admin_created_success' => 'Administrator created successfully.',
    'admin_updated_success' => 'Administrator updated successfully.',
    'admin_cannot_disable_self' => 'You cannot disable your own administrator account.',
    'no_admins_found' => 'No administrators found.',

    // Modules workbench (Task 7): category headers, source badges, and
    // the custom-module deletion flow. No root-dictionary collisions.
    'modules_category_core' => 'Core',
    'modules_category_custom' => 'Custom Modules',
    'modules_source' => 'Source',
    'modules_builtin' => 'Built-in',
    'modules_custom' => 'Custom',
    'modules_vendor' => 'Vendor',
    'modules_enabling' => 'Enabling…',
    'modules_disabling' => 'Disabling…',
    'modules_delete_extension' => 'Delete Module',
    'modules_delete_confirm' => 'Permanently delete the custom module ":name"? This action cannot be undone — custom modules cannot be restored.',
    'modules_protected_hint' => 'Protected modules cannot be uninstalled.',
    'modules_delete_not_custom' => 'Only custom modules can be deleted from the workbench.',
    'modules_delete_failed' => 'The module could not be deleted.',
];
