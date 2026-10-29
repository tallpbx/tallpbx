<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: file-stores
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'file-stores_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_file_store_title' => 'Delete File Store?',
    'modal_delete_file_store_confirm' => 'Delete File Store',
    'modal_delete_file_store_message' => 'Delete “:name” from TallPBX. This removes only the saved connection profile; it does not delete files on the remote server.',
    'modal_delete_file_store_error_title' => 'File Store cannot be deleted',
    'file_store_path' => 'Path',
    'file_store_bucket' => 'Bucket',
    'file_store_region' => 'Region',
    'file_store_access_key' => 'Access Key',
    'file_store_secret_key' => 'Secret Key',
    'file_store_endpoint' => 'S3-Compatible Endpoint',
    'file_store_host' => 'Host',
    'file_store_username' => 'Username',
    'file_store_port' => 'Port',
    'file_store_private_key' => 'Private Key',
    'file_store_passphrase' => 'Private Key Passphrase',
    'file_store_host_fingerprint' => 'Host Fingerprint',
    'file_store_access_token' => 'Access Token',
    'file_store_recipient' => 'Recipient',
    'file_store_sender' => 'Sender Override',
];
