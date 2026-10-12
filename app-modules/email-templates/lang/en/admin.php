<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-templates
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-templates_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'template_name' => 'Name',
    'template_vendor' => 'Vendor',
    'template_model' => 'Model',
    'template_file_path' => 'File Path',
    'email_templates_title' => 'Email Templates',
    'create_email_template' => 'Create Email Template',
    'email_template_name' => 'Name',
    'email_template_subject' => 'Subject',
    'email_template_body' => 'Body',
    'no_email_templates_found' => 'No templates found.',
    'confirm_delete_email_template' => 'Are you sure you want to delete this template?',
    'modal_delete_email_template_title' => 'Delete Email Template?',
    'modal_delete_email_template_confirm' => 'Delete Email Template',
];
