<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-connector
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-connector_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'email_connector_title' => 'Email Connector Configuration',
    'email_connector_description' => 'Configure outgoing email delivery using standard username/password SMTP authentication or OAuth 2.0 (Google, Microsoft, or custom provider).',
    'email_connector_tooltip' => 'Configure how the PBX sends email — password resets, voicemail notifications, backup reports, and system alerts. Supports standard username/password SMTP authentication as well as OAuth 2.0 (Google, Microsoft, or custom providers). For password auth: enter your SMTP username and password (or App Password). For OAuth 2.0: select your provider, enter your Client ID and Secret, and authorize.',
];
