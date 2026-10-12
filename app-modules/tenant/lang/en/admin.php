<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: tenant
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'tenant_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'tenants_count_tooltip' => 'Number of tenant organizations this user belongs to.',
    'tenants_title' => 'Tenants',
    'create_tenant' => 'Create Tenant',
    'edit_tenant' => 'Edit Tenant',
    'tenant_name' => 'Tenant Name',
    'tenant_email' => 'Tenant Email',
    'tenant_slug' => 'Slug',
    'tenant_slug_tooltip' => 'Unique URL-friendly identifier for this tenant.',
    'tenant_provisioning' => 'Provisioning URL',
    'tenant_status_tooltip' => 'Whether this tenant account is active or disabled.',
    'no_tenants_found' => 'No tenants found',
    'confirm_delete_tenant' => 'Are you sure you want to delete this tenant?',
    'tenant_limit' => 'Tenant Limit',
    'tenant_dashboard' => 'Dashboard',
    'tenant_domains_title' => 'Tenant Domains',
    'modal_delete_tenant_title' => 'Delete Tenant?',
    'modal_delete_tenant_confirm' => 'Delete Tenant',
    'modal_delete_tenant_message' => 'This deletes ALL data for “:name”. Type the tenant name to confirm.',
    'modal_delete_tenant_error_title' => 'Tenant Cannot Be Deleted',
    'modal_delete_tenant_domain_title' => 'Delete Domain?',
    'modal_delete_tenant_domain_confirm' => 'Delete Domain',
    'modal_delete_tenant_limit_title' => 'Delete Tenant Limit?',
    'modal_delete_tenant_limit_confirm' => 'Delete Tenant Limit',
];
