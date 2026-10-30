<?php

declare(strict_types=1);

return [
    // ─── Auth ───────────────────────────────────────────────────────────────
    'login_title' => 'TallPBX Admin',
    'login_subtitle' => 'Sign in to your admin account',
    'sign_in' => 'Sign In to Portal',
    'email' => 'Email',
    'password' => 'Password',
    'password_visibility' => 'Show or hide password',
    'unknown' => 'Unknown',
    'remember_me' => 'Remember me',
    'authenticating' => 'Authenticating...',
    'logout' => 'Logout',
    'sign_out' => 'Sign out of the control panel.',
    'toggle_navigation' => 'Toggle navigation menu.',
    'close_sidebar' => 'Close sidebar',
    'change_language' => 'Change interface language.',

    // ─── Navigation ─────────────────────────────────────────────────────────
    'dashboard' => 'Dashboard',
    'users' => 'Users',
    'tenants' => 'Tenants',
    'domains' => 'Domains',
    'groups' => 'Groups',
    'permissions' => 'Permissions',
    'notifications' => 'Notifications',
    'settings' => 'Settings',
    'modules' => 'Modules',
    'pbx' => 'PBX',
    'pbx_accounts' => 'Accounts',
    'pbx_connectivity' => 'Connectivity',
    'pbx_routing' => 'Call Routing',
    'pbx_features' => 'Features',
    'pbx_media' => 'Media',
    'pbx_advanced' => 'Advanced',
    'pbx_monitoring' => 'Monitoring',
    'provision' => 'Device Provisioning',
    'create_template' => 'Create Template',
    'template' => 'Template',
    'no_templates_found' => 'No templates found.',
    'confirm_delete_template' => 'Are you sure you want to delete this template?',
    'extensions' => 'Extensions',
    'sip_accounts' => 'SIP Accounts',
    'sip_profiles' => 'SIP Profiles',
    'gateways' => 'Gateways',
    'inbound_routes' => 'Inbound Routes',
    'outbound_routes' => 'Outbound Routes',
    'voicemails' => 'Voicemails',
    'voicemail' => 'Voicemail',
    'leave_blank_keep_existing_greeting' => 'Leave blank to keep the existing greeting.',
    'leave_blank_keep_existing_audio' => 'Leave blank to keep the existing audio file.',
    'audio_file' => 'Audio File',
    'devices' => 'Devices',
    'destinations' => 'Destinations',
    'dialplans' => 'Dialplans',
    'feature_codes' => 'Feature Codes',
    'acl' => 'Access Control Lists',
    'ivr_menus' => 'IVR Menus',
    'tools' => 'Tools',

    // ─── Dashboard ──────────────────────────────────────────────────────────
    'dashboard_overview' => 'Dashboard Overview',
    'total_users' => 'Total Users',
    'total_users_tooltip' => 'Total registered users across all tenants.',
    'total_tenants' => 'Total Tenants',
    'total_tenants_tooltip' => 'Total tenant organizations in the system.',
    'system_status' => 'System Status',
    'healthy' => 'Healthy',
    'connected' => 'Connected',
    'disconnected' => 'Disconnected',
    'fs_connection' => 'FreeSWITCH',
    'fs_connection_tooltip' => 'Current FreeSWITCH telephony engine connection status.',
    'active_calls' => 'Active Calls',
    'recent_calls' => 'Recent Calls',
    'view_all' => 'View All',

    // ─── Users ──────────────────────────────────────────────────────────────
    'users_title' => 'Users',
    'create_user' => 'Create User',
    'create_user_tooltip' => 'Add a new user account to the system.',
    'edit_user' => 'Edit User',
    'delete_user_tooltip' => 'Permanently remove this user account.',
    'name' => 'Name',
    'description' => 'Description',
    'name_tooltip' => 'Full name of the user.',
    'email_tooltip' => 'Email address used for sign-in and notifications.',
    'groups_count_tooltip' => 'Number of permission groups assigned to this user.',
    'no_users_found' => 'No users found.',
    'impersonate' => 'Impersonate this user',
    'impersonate_tooltip' => 'Sign in as this user to see their account and troubleshoot issues.',
    'impersonation_logs' => 'Impersonation Logs',
    'impersonation_logs_title' => 'Impersonation Audit Logs',
    'impersonation_logs_tooltip' => 'View the audit trail of administrator user impersonation sessions.',
    'impersonation_logs_description' => 'Review historical audit records of administrator user impersonation sessions.',
    'back_to_users' => 'Back to Users',
    'search_logs_placeholder' => 'Search admin, user, or IP...',
    'all_actions' => 'All Actions',
    'action_start' => 'Started',
    'action_stop' => 'Stopped',
    'clear_filters' => 'Clear Filters',
    'administrator' => 'Administrator',
    'target_user' => 'Target User',
    'no_impersonation_logs' => 'No impersonation audit logs found.',
    'confirm_delete_user' => 'Are you sure you want to delete this user?',

    // ─── Tenants ────────────────────────────────────────────────────────────
    'tenant' => 'Tenant',
    'select_tenant' => 'Select tenant',
    'create_tenant_tooltip' => 'Add a new tenant organization to the system.',
    'delete_tenant_tooltip' => 'Permanently remove this tenant and all its data.',
    'none' => 'None',
    'domain' => 'Domain',
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'primary_user' => 'Primary User',
    'primary_user_tooltip' => 'The main contact person for this tenant.',
    'users_count_tooltip' => 'Number of users who belong to this tenant.',
    'switch_tenant' => 'Switch tenant',
    'no_tenant' => 'No tenant selected',

    // ─── Domains ────────────────────────────────────────────────────────────
    'domains_title' => 'Tenant Domains',
    'create_domain' => 'Create Domain',
    'edit_domain' => 'Edit Domain',
    'domain_url' => 'Domain',
    'purpose' => 'Purpose',
    'purpose_sip_realm' => 'SIP Realm',
    'purpose_provisioning' => 'Provisioning',
    'purpose_web' => 'Web',
    'purpose_alias' => 'Alias',
    'no_domains_found' => 'No domains found.',
    'confirm_delete_domain' => 'Are you sure you want to delete this domain?',

    // ─── Groups ─────────────────────────────────────────────────────────────
    'groups_title' => 'Groups',
    'create_group' => 'Create Group',
    'edit_group' => 'Edit Group',
    'group_name' => 'Group Name',
    'group_description' => 'Description',
    'protect_group' => 'Protect Group',
    'no_groups_found' => 'No groups found.',
    'confirm_delete_group' => 'Are you sure you want to delete this group?',

    // ─── Permissions ────────────────────────────────────────────────────────
    'permissions_title' => 'Permissions',
    'permission_name' => 'Permission',
    'permission_description' => 'Description',
    'permissions_description' => 'Permissions are defined in code by each module and synced automatically. Assign them to groups on the',
    'permissions_page' => 'page',
    'no_permissions_found' => 'No permissions found',

    // ─── Notifications ──────────────────────────────────────────────────────
    'notifications_title' => 'Notifications',
    'mark_all_read' => 'Mark all as read',
    'no_notifications' => 'No notifications yet.',
    'no_notifications_desc' => 'You\'re all caught up!',
    'unread_notifications' => '{1} :count unread notification|[2,*] :count unread notifications',
    'notification_default_title' => 'Notification',
    'mark_read' => 'Mark read',

    // ─── Queue Status ────────────────────────────────────────────────────
    'queue_status' => 'Queue Status',
    'queue_pending' => 'Pending',
    'queue_failed' => 'Failed',
    'queue_total' => 'Total',
    'queue_retry_all' => 'Retry All Failed',
    'queue_recent_failures' => 'Recent Failures',
    'queue_job' => 'Job',
    'queue_failed_at' => 'Failed At',
    'queue_no_failures' => 'No failed jobs — everything is running smoothly.',
    'queue_permission_denied' => 'You do not have permission to retry failed jobs.',
    'queue_invalid_id' => 'The job id is not valid.',
    'queue_retried' => 'Job :id pushed back to the queue.',
    'queue_retried_all' => 'All failed jobs pushed back to the queue.',
    'queue_unknown_job' => 'Unknown job',
    'queue_unknown_error' => 'Unknown error',
    'queue_failed_attention' => '{1} :count failed job requiring attention|[2,*] :count failed jobs requiring attention',
    'queue_review_failed' => 'Review Failed Jobs',
    'monitoring_backups' => 'Backups',
    'monitoring_days' => 'days',
    'monitoring_no_certificate' => 'No TLS certificate detected',
    'refresh' => 'Refresh',
    'retry' => 'Retry',

    // ─── Settings ───────────────────────────────────────────────────────────
    'settings_title' => 'Settings',
    'add_setting' => 'Add Setting',
    'setting_key' => 'Key',
    'setting_value' => 'Value',
    'setting_type' => 'Type',
    'setting_type_string' => 'string',
    'setting_type_integer' => 'integer',
    'setting_type_boolean' => 'boolean',
    'setting_type_json' => 'json',
    'setting_key_placeholder' => 'app.name',
    'setting_value_placeholder' => 'MyPBX',
    'setting_add' => 'Add',
    'settings_empty' => 'No settings configured.',
    'media_archive_destination' => 'Media archive destination',
    'media_archive_destination_help' => 'Completed call recordings and faxes are archived here. Other managed media remains local.',
    'media_archive_destination_save' => 'Save archive destination',

    // ─── Modules ────────────────────────────────────────────────────────────
    'modules_title' => 'Modules',
    'module_name' => 'Name',
    'module_display_name' => 'Display Name',
    'module_enabled' => 'Enabled',
    'module_priority' => 'Priority',
    'module_version' => 'Version',
    'module_protected' => 'Protected',
    'enable_module' => 'Enable',
    'disable_module' => 'Disable',
    'uninstalled' => 'Uninstalled',
    'module_restore_hint' => 'Restore over SSH: tpbx module:restore :name',
    'module_required' => 'Required',
    'no_modules_found' => 'No modules found.',
    'expand_all' => 'Expand All',
    'collapse_all' => 'Collapse All',

    // ─── SIP Accounts ───────────────────────────────────────────────────────
    'sip_username' => 'Username',
    'sip_domain' => 'Domain',
    'sip_type' => 'Type',
    'sip_type_global' => 'Global',
    'sip_type_domain' => 'Domain',
    'sip_type_hybrid' => 'Hybrid',

    // ─── Inbound Routes ─────────────────────────────────────────────────────
    'route_did' => 'DID Number',
    'route_action' => 'Action',
    'route_destination' => 'Destination',
    'route_priority' => 'Priority',
    'route_continue' => 'Continue',

    // ─── Outbound Routes ────────────────────────────────────────────────────
    'route_name' => 'Route Name',
    'route_dial_pattern' => 'Dial Pattern',
    'route_gateway' => 'Gateway',
    'route_pin' => 'PIN',
    'route_cid' => 'Caller ID',
    'route_default' => 'Default Outbound',
    'route_provider' => 'Provider',
    'route_provider_placeholder' => 'sip-provider',
    'route_prefix' => 'Prefix',

    // ─── Devices ────────────────────────────────────────────────────────────

    // ─── Destinations ───────────────────────────────────────────────────────

    // ─── Feature Codes ──────────────────────────────────────────────────────
    'feature_code' => 'Code',

    // ─── Access Control Lists ────────────────────────────────────────────────

    // ─── IVR Menus ─────────────────────────────────────────────────────────

    // ─── Call Forwards ────────────────────────────────────────────────────
    'call_forwards' => 'Call Forwards',
    'forward_type' => 'Forward Type',
    'unconditional' => 'Unconditional',
    'busy' => 'Busy',
    'no_answer' => 'No Answer',
    'not_found' => 'Not Found',
    'ring_timeout' => 'Ring Timeout',
    'seconds' => 'seconds',

    // ─── Call Block ───────────────────────────────────────────────────────
    'call_blocks' => 'Call Blocks',
    'caller_id_pattern' => 'Caller ID Pattern',

    // ─── PIN Numbers ──────────────────────────────────────────────────────
    'pin_numbers' => 'PIN Numbers',
    'pin_number' => 'PIN Number',

    // ─── Music on Hold ────────────────────────────────────────────────────
    'music_on_hold' => 'Music on Hold',

    // ─── Bridges ──────────────────────────────────────────────────────────
    'bridges' => 'Bridges',

    // ─── Ring Groups ────────────────────────────────────────────────────────
    'ring_groups' => 'Ring Groups',
    'add_extension' => 'Add Extension',

    // ─── Conferences ───────────────────────────────────────────────────────
    'conferences' => 'Conferences',

    // ─── Conference Centers ─────────────────────────────────────────────────
    'conference_centers' => 'Conference Centers',

    // ─── Time Conditions ─────────────────────────────────────────────────
    'time_conditions' => 'Time Conditions',

    // ─── Follow Me ────────────────────────────────────────────────────────
    'follow_me' => 'Follow Me',

    // ─── Call Flows ───────────────────────────────────────────────────────
    'call_flows' => 'Call Flows',

    // ─── Voicemail Messages ──────────────────────────────────────────────
    'voicemail_messages' => 'Voicemail Messages',

    // ─── Call Recordings ────────────────────────────────────────────────
    'call_recordings' => 'Call Recordings',

    // ─── Recordings ─────────────────────────────────────────────────────
    'recordings' => 'Recordings',

    // ─── Fax ────────────────────────────────────────────────────────────
    'fax' => 'Fax',
    'no_faxes_found' => 'No faxes found.',

    // ─── Call Broadcasts ─────────────────────────────────────────────────
    'call_broadcasts_title' => 'Call Broadcasts',
    'call_broadcasts' => 'Call Broadcasts',
    'call_broadcast' => 'Call Broadcast',
    'send_call_broadcast' => 'Send',
    'modal_send_broadcast_title' => 'Send Broadcast?',
    'modal_send_broadcast_message' => 'Send “:name” now? Every recipient will receive a call.',
    'modal_send_broadcast_confirm' => 'Send Broadcast',

    // ─── Number Translations ───────────────────────────────────────────
    'number_translations' => 'Number Translations',
    'direction_inbound' => 'Inbound',
    'direction_outbound' => 'Outbound',
    'direction_both' => 'Both',

    // ─── Tenant Limits ─────────────────────────────────────────────────
    'tenant_limits' => 'Tenant Limits',
    'create_tenant_limit' => 'Create Tenant Limit',
    'no_tenant_limits_found' => 'No limits found.',
    'confirm_delete_tenant_limit' => 'Are you sure you want to delete this limit?',

    // ─── Extension Settings ───────────────────────────────────────────
    'extension_settings' => 'Extension Settings',
    'extension_setting' => 'Extension Setting',
    'create_extension_setting' => 'Create Extension Setting',
    'no_extension_settings_found' => 'No settings found.',
    'confirm_delete_extension_setting' => 'Are you sure you want to delete this setting?',

    // ─── Event Rate Limits (FreeSWITCH Event Rate Limiting) ──────────
    'event_rate_limits' => 'Event Rate Limits',
    'settings_saved' => 'Settings saved successfully.',

    // ─── Email Templates ──────────────────────────────────────────────
    'email_templates' => 'Email Templates',
    'email_template' => 'Email Template',

    // ─── SIP Trunks ───────────────────────────────────────────────────
    'sip_trunks' => 'SIP Trunks',

    // ─── Emergency ──────────────────────────────────────────────────
    'emergency' => 'Emergency (E911)',
    'no_emergency_records_found' => 'No emergency records found.',

    // ─── Click to Call ──────────────────────────────────────────────
    'click_to_call' => 'Click to Call',

    // ─── Dialplan Tools ──────────────────────────────────────────────
    'dialplan_tools' => 'Dialplan Tools',

    // ─── Email Queue ──────────────────────────────────────────────────
    'email_queue' => 'Email Queue',
    'no_email_queue_items_found' => 'No queued emails found.',
    'confirm_delete_email_queue' => 'Are you sure you want to delete this email?',

    // ─── Email Connector ────────────────────────────────────────────────
    'email_connector' => 'Email Connector',

    // ─── SMTP Connector (Legacy Aliases) ────────────────────────────────
    'smtp_connector' => 'Email Connector',
    'smtp_connector_title' => 'Email Connector Configuration',
    'smtp_connector_description' => 'Configure outgoing email delivery using standard username/password SMTP authentication or OAuth 2.0 (Google, Microsoft, or custom provider).',
    'smtp_configured' => 'Configured',
    'smtp_not_configured' => 'Not Configured',
    'smtp_gmail_preset' => 'Gmail Preset',
    'smtp_generic_preset' => 'Generic Preset',
    'smtp_host' => 'SMTP Host',
    'smtp_port' => 'Port',
    'smtp_encryption' => 'Encryption',
    'smtp_username' => 'Username',
    'smtp_password' => 'Password',
    'smtp_password_encrypted' => '(stored encrypted)',
    'smtp_from_address' => 'From Address',
    'smtp_from_name' => 'From Name',
    'smtp_none' => 'None',
    'smtp_test_email' => 'Test Email',
    'smtp_test_recipient' => 'Send test to',
    'smtp_send_test' => 'Send Test Email',
    'smtp_oauth' => 'OAuth 2.0',
    'smtp_connector_tooltip' => 'Configure how the PBX sends email — password resets, voicemail notifications, backup reports, and system alerts. Supports standard username/password SMTP authentication as well as OAuth 2.0 (Google, Microsoft, or custom providers). For password auth: enter your SMTP username and password (or App Password). For OAuth 2.0: select your provider, enter your Client ID and Secret, and authorize.',
    'smtp_configured_tooltip' => 'SMTP is configured and ready to send mail.',
    'smtp_not_configured_tooltip' => 'SMTP has not been configured yet — emails will not be delivered until you fill in the form and save.',
    'smtp_host_tooltip' => 'The hostname of your SMTP server. Common values: smtp.gmail.com, smtp.office365.com, smtp.mailgun.org.',
    'smtp_port_tooltip' => 'SMTP port number. 587 with TLS is the industry standard. Use 465 for legacy SSL.',
    'smtp_encryption_tooltip' => 'TLS on port 587 is the modern standard. SSL on port 465 is legacy.',
    'smtp_password_tooltip' => 'Your SMTP password or Gmail App Password. Stored encrypted in the database — never written to .env or log files.',
    'smtp_from_address_tooltip' => 'The email address that appears in the From field of outgoing messages.',
    'smtp_from_name_tooltip' => 'The display name that appears alongside the From address, e.g. "TallPBX Notifications".',
    'smtp_test_email_tooltip' => 'Send a test message to verify your SMTP settings work before relying on them for production notifications.',

    // ─── SMTP Connector — OAuth 2.0 ───────────────────────────────────────
    'smtp_auth_type' => 'Authentication',
    'smtp_auth_type_password' => 'Password',
    'smtp_auth_type_oauth' => 'OAuth 2.0',
    'smtp_auth_type_tooltip' => 'Choose how the PBX authenticates with the SMTP server. Password: standard SMTP username and password authentication (or Gmail App Password). OAuth 2.0: token-based authentication via Google, Microsoft, or a custom OAuth2 provider — no app password needed.',
    'smtp_oauth_provider' => 'Provider',
    'smtp_oauth_provider_google' => 'Google (Gmail)',
    'smtp_oauth_provider_microsoft' => 'Microsoft (Outlook / Hotmail / Office 365)',
    'smtp_oauth_provider_custom' => 'Custom OAuth 2.0 provider',
    'smtp_oauth_provider_tooltip' => 'Select your email provider. Google and Microsoft have pre-configured endpoints — just enter your Client ID and Secret. For any other OAuth2-compliant SMTP provider, choose Custom and enter the endpoints manually.',
    'smtp_oauth_client_id' => 'Client ID',
    'smtp_oauth_client_id_tooltip' => 'The OAuth 2.0 Client ID from your provider\'s developer console. For Google: create an OAuth 2.0 Client ID at console.cloud.google.com/apis/credentials — choose "Web application" type and add the callback URL shown below to the Authorized redirect URIs. For Microsoft: register an app at portal.azure.com → App registrations → choose "Web" redirect URI type.',
    'smtp_oauth_client_secret' => 'Client Secret',
    'smtp_oauth_client_secret_tooltip' => 'The OAuth 2.0 Client Secret from your provider\'s developer console. Stored encrypted in the database — never written to .env or log files. For Google: found under the same OAuth 2.0 Client ID in the Cloud Console. For Microsoft: found under Certificates \\& secrets in your Azure app registration.',
    'smtp_oauth_auth_endpoint' => 'Authorization Endpoint',
    'smtp_oauth_auth_endpoint_tooltip' => 'The OAuth 2.0 authorization endpoint URL where the admin is redirected to grant consent. Example: https://provider.example.com/oauth2/authorize',
    'smtp_oauth_token_endpoint' => 'Token Endpoint',
    'smtp_oauth_token_endpoint_tooltip' => 'The OAuth 2.0 token endpoint URL where authorization codes are exchanged for access tokens. Example: https://provider.example.com/oauth2/token',
    'smtp_oauth_scopes' => 'Scopes',
    'smtp_oauth_scopes_tooltip' => 'Space-separated OAuth 2.0 scopes to request. Must include the SMTP send scope for your provider. Common patterns: "smtp.send offline_access" or "https://mail.google.com/".',
    'smtp_oauth_authorize' => 'Authorize with Provider',
    'smtp_oauth_reauth' => 'Re-authorize',
    'smtp_oauth_authorized' => 'Authorized',
    'smtp_oauth_not_authorized' => 'Not authorized',
    'smtp_oauth_disconnect' => 'Disconnect',
    'smtp_oauth_disconnect_confirm' => 'Revoke OAuth authorization? You will need to re-authorize before sending email. Client ID and Secret are preserved.',
    'smtp_oauth_instructions_google' => 'Create an OAuth 2.0 Client ID at console.cloud.google.com/apis/credentials. Use type "Web application". Add this callback URL to Authorized redirect URIs: :url. Make sure the Gmail API is enabled for your project.',
    'smtp_oauth_instructions_microsoft' => 'Register an app at portal.azure.com → App registrations. Under Authentication, add a Web platform with this redirect URI: :url. Under API permissions, add "SMTP.Send" (delegated). Set the supported account types to "Accounts in any organizational directory and personal Microsoft accounts" so Hotmail/Outlook users can authenticate.',
    'smtp_oauth_instructions_custom' => 'Enter the authorization endpoint, token endpoint, and required scopes for your OAuth 2.0 provider. Make sure the provider supports the authorization code grant type and that this redirect URI is whitelisted: :url. Use the scope required for SMTP access (e.g. "smtp.send").',

    // ─── Backups ────────────────────────────────────────────────────────
    'backups' => 'Backups',

    // ─── Monitoring ──────────────────────────────────────────────────────
    'monitoring' => 'Monitoring',
    'page_info_tooltip' => 'Hover over labels and headers throughout the admin panel for detailed explanations of each field and feature.',
    'monitoring_services' => 'Services',
    'monitoring_disk' => 'Disk Usage',
    'monitoring_memory' => 'Memory',
    'monitoring_freeswitch' => 'FreeSWITCH',
    'monitoring_queues' => 'Queue Workers',
    'monitoring_certificate' => 'Certificate',

    // ─── Git Update ───────────────────────────────────────────────────────
    'git_update_steps' => 'Update steps',
    'git_update' => 'Git Update',
    'git_fetch' => 'Fetch Latest',
    'git_select_target' => 'Select Branch or Tag',
    'git_update_now' => 'Update Now',
    'git_update_confirm' => 'This will pull the latest changes and update the application. Continue?',
    'git_update_success' => 'Update completed successfully. You may need to run migrations and clear caches.',
    'git_unclean_warning' => 'Cannot update — there are uncommitted changes in the working tree.',
    'git_unavailable' => 'Unavailable',
    'git_repo_inaccessible' => 'Git repository is not accessible or not configured.',
    'git_channel' => 'Release Channel',
    'git_stable_channel' => 'Stable Release',
    'git_stable_desc' => 'Vetted production releases and security patches (recommended)',
    'git_dev_channel' => 'Development (main)',
    'git_dev_desc' => 'Active development branch with latest features',
    'git_custom_channel' => 'Custom / Other',
    'git_custom_desc' => 'Select any specific remote branch or tag',
    'git_current_version' => 'Current Version',
    'git_up_to_date' => 'Up to date with remote',
    'git_update_available' => 'Update Available',
    'git_commits_behind' => ':count commit(s) behind :target',
    'git_incoming_commits' => 'Incoming Changes',
    'git_no_incoming_commits' => 'No new commits to apply.',
    'git_switch_branch_notice' => 'Switching from :current to :target. The update pipeline will switch branches, apply migrations, and rebuild assets.',
    'git_no_stable_branches' => 'No stable release branches found on remote. Tracking development branch.',
    'git_switch_and_update' => 'Switch Branch & Update',
    'git_view_commits' => 'Recent Commits',
    'git_session_cache_warning' => 'Session storage currently shares the application cache connection (SESSION_CONNECTION=cache). Set SESSION_CONNECTION=session in .env so updates and cache clears do not sign out active administrators.',

    // ─── Transcribe ───────────────────────────────────────────────────
    'transcribe' => 'Transcriptions',
    'no_transcriptions_found' => 'No transcriptions found.',
    'confirm_delete_transcribe' => 'Are you sure you want to delete this transcription?',

    // ─── Speech ───────────────────────────────────────────────────
    'speech' => 'Speech (TTS)',
    'create_speech_config' => 'Create Speech Config',
    'no_speech_configs_found' => 'No speech configs found.',
    'confirm_delete_speech' => 'Are you sure you want to delete this config?',

    // ─── CDR ────────────────────────────────────────────────────────────
    'cdr' => 'Call Detail Records',
    'cdr_title' => 'Call Detail Records',
    'cdr_detail_title' => 'Call Detail Record',
    'cdr_caller' => 'Caller',
    'cdr_destination' => 'Destination',
    'cdr_direction' => 'Direction',
    'cdr_duration' => 'Duration',
    'cdr_billsec' => 'Billsec',
    'cdr_date' => 'Date',
    'cdr_hangup_cause' => 'Hangup Cause',
    'cdr_call_uuid' => 'Call UUID',
    'cdr_start_stamp' => 'Start Time',
    'cdr_answer_stamp' => 'Answer Time',
    'cdr_end_stamp' => 'End Time',
    'no_cdrs_found' => 'No CDR records found.',
    'confirm_delete_cdr' => 'Are you sure you want to delete this CDR record?',

    // ─── Active Calls ───────────────────────────────────────────────────
    'fs_not_connected' => 'FreeSWITCH not connected. Live data is unavailable.',
    'live' => 'Live',
    'realtime_reconnecting' => 'Live connection interrupted. Reconnecting and synchronizing state...',

    // ─── Active Conferences ─────────────────────────────────────────────
    'active_conferences' => 'Active Conferences',
    'no_active_conferences' => 'No active conferences.',

    // ─── Registrations ──────────────────────────────────────────────────
    'registrations' => 'Registrations',

    // ─── SIP Status ─────────────────────────────────────────────────────
    'sip_status' => 'SIP Status',

    // ─── Log Viewer ──────────────────────────────────────────────────────
    'logs' => 'Log Viewer',

    // ─── Call Centers ───────────────────────────────────────────────────
    'call_centers' => 'Call Centers',
    'queue' => 'Queue',
    'create_queue' => 'Create Queue',
    'no_queues_found' => 'No queues found.',
    'confirm_delete_queue' => 'Are you sure you want to delete this queue?',

    // ─── Call Center Active ─────────────────────────────────────────────
    'call_center_active' => 'Call Center Active',
    'calls_waiting' => 'Waiting',
    'agents_logged_in' => 'Logged In',
    'no_active_queues' => 'No active queues.',

    // ─── Operator Panel ─────────────────────────────────────────────────
    'operator_panel' => 'Operator Panel',

    // ─── Groups (admin-managed) ─────────────────────────────────────────────
    'system_groups' => 'System Groups',

    // ─── Tenant Portal (admin-managed) ──────────────────────────────────────
    'add_domain' => 'Add Domain',

    // ─── Common UI ──────────────────────────────────────────────────────────
    'never' => 'Never',
    'light' => 'Light',
    'dark' => 'Dark',
    'system' => 'System',
    'display_settings' => 'Display Settings',
    'layout_style' => 'Layout Style',
    'layout_sidebar' => 'Sidebar',
    'layout_horizontal' => 'Top Navigation',
    'sidebar_size' => 'Sidebar Size',
    'sidebar_expanded' => 'Expanded',
    'sidebar_collapsed' => 'Mini Rail',
    'collapse_sidebar' => 'Collapse sidebar',
    'expand_sidebar' => 'Expand sidebar',
    'copyright' => '&copy; :year TallPBX. All rights reserved.',
    'app_version' => 'Laravel :version',
    'app_version_tooltip' => 'Current application version running in this environment.',
    'php_version' => 'PHP :version',
    'php_version_tooltip' => 'PHP runtime version powering the application.',
    'freeswitch' => 'FreeSWITCH',
    'freeswitch_tooltip' => 'Telephony engine providing SIP, WebRTC, and media handling.',

    // ─── PBX Module Tooltips ──────────────────────────────────────────────
    'sip_password_tooltip' => 'The SIP registration password phones use to authenticate.',
    'voicemail_password' => 'Voicemail Password (PIN)',
    'voicemail_password_tooltip' => 'Numeric PIN used to access voicemail via *97 or *98. Defaults to the extension number if left blank.',
    'voicemail_enabled_tooltip' => 'When enabled, unanswered calls to this extension go to voicemail after the ring timeout.',
    'route_destination_tooltip' => 'Where the call goes when this route matches. Can be an extension, bridge, IVR, ring group, or external number.',
    'route_condition_tooltip' => 'The dialplan condition that triggers this route, e.g. a DID number pattern like ^15551234567$.',
    'enabled_tooltip' => 'When disabled, this item is ignored by FreeSWITCH and does not affect call routing or behavior.',
    'tenant_select_tooltip' => 'The tenant organization this item belongs to. Only visible to system administrators managing multiple tenants.',
    // ─── Confirmation modal copy ─────────────────────────────────────────
    'modal_cancel' => 'Cancel',
    'modal_close' => 'Close',
    'modal_typed_placeholder' => 'Type the required text to confirm',
    'modal_delete_generic_question' => 'Delete “:name”? This cannot be undone.',
    'modal_delete_generic_statement' => 'Delete “:name”. This cannot be undone.',
    'modal_delete_call_detail_record_title' => 'Delete Call Detail Record?',
    'modal_delete_call_detail_record_confirm' => 'Delete Call Detail Record',
    'modal_delete_domain_title' => 'Delete Domain?',
    'modal_delete_domain_confirm' => 'Delete Domain',
    'modal_delete_domain_message' => 'Delete “:name”? Logins routed to this domain may stop working.',
    'modal_delete_domain_error_title' => 'Domain Cannot Be Deleted',
    'modal_delete_email_title' => 'Delete Email?',
    'modal_delete_email_confirm' => 'Delete Email',
    'modal_delete_extension_setting_title' => 'Delete Extension Setting?',
    'modal_delete_extension_setting_confirm' => 'Delete Extension Setting',
    'modal_delete_group_title' => 'Delete Group?',
    'modal_delete_group_confirm' => 'Delete Group',
    'modal_delete_group_message' => 'Delete “:name”? Assigned administrators lose its permissions.',
    'modal_delete_group_error_title' => 'Group Cannot Be Deleted',
    'modal_delete_queue_title' => 'Delete Queue?',
    'modal_delete_queue_confirm' => 'Delete Queue',
    'modal_delete_setting_title' => 'Delete Setting?',
    'modal_delete_setting_confirm' => 'Delete Setting',
    'modal_delete_setting_message' => 'Delete “:name”? The application may use this value.',
    'modal_delete_setting_error_title' => 'Setting Cannot Be Deleted',
    'modal_delete_speech_title' => 'Delete Speech Configuration?',
    'modal_delete_speech_confirm' => 'Delete Speech Configuration',
    'modal_delete_template_title' => 'Delete Template?',
    'modal_delete_template_confirm' => 'Delete Template',
    'modal_delete_transcription_title' => 'Delete Transcription?',
    'modal_delete_transcription_confirm' => 'Delete Transcription',
    'modal_delete_user_title' => 'Delete User?',
    'modal_delete_user_confirm' => 'Delete User',
    'modal_delete_user_message' => 'Delete “:name”? The user loses panel access.',
    'modal_delete_user_error_title' => 'User Cannot Be Deleted',
    'modal_git_update_title' => 'Update TallPBX?',
    'modal_git_update_confirm' => 'Update TallPBX',
    'modal_git_update_message' => 'The system may be briefly unavailable during the update. Type UPDATE to confirm.',
    'modal_git_update_error_title' => 'Update Not Confirmed',
    'modal_oauth_disconnect_title' => 'Disconnect OAuth?',
    'modal_oauth_disconnect_confirm' => 'Disconnect OAuth',
    'modal_oauth_disconnect_message' => 'Email sending will fail until OAuth is re-authorized.',
    'modal_oauth_disconnect_error_title' => 'OAuth Cannot Be Disconnected',
    'modal_run_backup_title' => 'Run Backup Now?',
    'modal_run_backup_confirm' => 'Run Backup Now',
    'routing_translation_order' => 'Rule order',
    'modal_run_backup_message' => 'Run “:name” now? A new archive will be created and stored.',
    'modal_restore_title' => 'Restore Backup?',
    'modal_restore_confirm' => 'Restore Backup',
    'modal_restore_message' => 'Restore “:name”? This replaces the selected database and file scopes on this PBX.',
    'modal_restore_error_title' => 'Restore Not Accepted',
    'error_page_403_title' => 'Access Denied.',
    'error_page_403_message' => 'You do not have permission to access this resource.',
    'error_page_500_title' => 'Something went wrong.',
    'error_page_500_message' => 'An unexpected error occurred. The team has been notified; please try again in a few minutes.',
    'error_page_503_title' => 'TallPBX is temporarily unavailable.',
    'error_page_503_message' => 'Please try again shortly. If the problem persists, contact your administrator.',
    'error_page_request_id' => 'Request ID',

    'route_action_transfer' => 'Transfer',
    'route_action_ivr' => 'IVR Menu',
    'route_action_voicemail' => 'Voicemail',

    // ─── Hot Desking ───────────────────────────────────────────────────
    'hot_desking' => 'Hot Desking',
    'hot_desk_session' => 'Hot Desk Session',
    'create_hot_desk_session' => 'Create Session',
    'no_hot_desking_sessions_found' => 'No hot desking sessions found.',
    'confirm_delete_hot_desk_session' => 'Are you sure you want to delete this hot desking record?',

    // ─── Administrators & Profile ───────────────────────────────────────
    'administrators' => 'Administrators',
    'password_requirements_min' => 'Minimum 8 characters',
    'confirm_password' => 'Confirm Password',
    'confirm_password_help' => 'Must match password',
    'confirm_password_hint' => 'Re-enter password to confirm.',
    'create_admin_tooltip' => 'Create a new system administrator',
    'my_profile' => 'My Profile',
    'profile_description' => 'Update your account details and password credentials.',
    'profile_information' => 'Profile Information',
    'profile_updated' => 'Profile information updated successfully.',
    'password_change' => 'Change Password',
    'current_password' => 'Current Password',
    'new_password' => 'New Password',
    'confirm_new_password' => 'Confirm New Password',
    'profile_new_password_hint' => 'Must be at least 8 characters and different from your current password.',
    'password_updated' => 'Password changed successfully.',
    'update_password' => 'Update Password',
    'save' => 'Save',
    'saving' => 'Saving...',
    'cancel' => 'Cancel',
    'delete' => 'Delete',
    'actions' => 'Actions',

    // ─── Session Expiration ──────────────────────────────────────────────
    'session_expired_title' => 'Session Expired',
    'session_expired_message' => 'Your session has expired. Please refresh the page or sign in again to continue.',
    'session_expired_action' => 'Continue',

    // ─── Security Module ────────────────────────────────────────────────
    'security' => 'Security',
    'vector_sip' => 'Phone (SIP)',
    'vector_web' => 'Web Login',
    'vector_ssh' => 'Server (SSH)',
    'vector_manual' => 'Manual Block',

    // ─── Certificates Module ─────────────────────────────────────────────
    'certificates' => 'HTTPS & TLS Certificates',
];
