<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
|--------------------------------------------------------------------------
| Confirmation Modal Component Tests
|--------------------------------------------------------------------------
*/

it('renders the modal without a typed input when no required text is set', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Setting?" message="This cannot be undone." confirm-label="Delete Setting" confirm-action="deleteSetting()" cancel-action="cancelSettingDeletion" />'
    );

    expect($html)
        ->toContain('Delete Setting?')
        ->not->toContain('type="text"');
});

it('renders a typed input and disabled confirm binding when required text is set', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Tenant?" message="Type the tenant name to confirm." confirm-label="Delete Tenant" confirm-action="deleteTenant()" cancel-action="cancelTenantDeletion" :required-text="$requiredText" />',
        ['requiredText' => 'Acme Corp']
    );

    expect($html)
        ->toContain('type="text"')
        ->toContain('x-model="typed"')
        ->toContain('wire:model="confirmTypedInput"')
        ->toContain("typed.trim() !== 'Acme Corp'");
});

it('escapes required text containing a single quote', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Tenant?" message="Type the tenant name to confirm." confirm-label="Delete Tenant" confirm-action="deleteTenant()" cancel-action="cancelTenantDeletion" :required-text="$requiredText" />',
        ['requiredText' => "O'Brien Corp"]
    );

    expect($html)
        ->toContain("'O\\u0027Brien Corp'")
        ->not->toContain("'O'Brien Corp'");
});

it('disables the confirm button and shows a spinner while the action runs', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete?" message="Sure?" confirm-label="Delete" confirm-action="deleteRecord()" cancel-action="cancel" />'
    );

    expect($html)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('loading loading-spinner');
});

/*
|--------------------------------------------------------------------------
| Operational Toast Component Tests
|--------------------------------------------------------------------------
*/

it('renders nothing when no operational message or session feedback exists', function (): void {
    $html = Blade::render('<x-operational-toast />');

    expect($html)->toBe('');
});

it('renders success toast with 5-second auto-dismiss and polite aria-live', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" />',
        ['message' => 'Firewall rule enabled', 'type' => 'success']
    );

    expect($html)
        ->toContain('Firewall rule enabled')
        ->toContain('alert-success')
        ->toContain('toast-top toast-end')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('role="alert"')
        ->toContain('wire:click="dismissFeedback"')
        ->toContain('@click="dismiss()"')
        ->toContain('toast-progress')
        ->toContain('5000')
        ->toContain('true');
});

it('renders error toast with persistent behavior and assertive aria-live', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" />',
        ['message' => 'Failed to apply rule', 'type' => 'error']
    );

    expect($html)
        ->toContain('Failed to apply rule')
        ->toContain('alert-error')
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('wire:click="dismissFeedback"')
        ->not->toContain('toast-progress')
        ->toContain('false');
});

it('renders custom timeout when provided', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" :timeout="3000" />',
        ['message' => 'Quick notice', 'type' => 'info']
    );

    expect($html)
        ->toContain('Quick notice')
        ->toContain('3000')
        ->toContain('toast-progress')
        ->toContain('true');
});

it('renders flashed session status with auto-dismiss and progress line', function (): void {
    session()->flash('status', 'Settings saved successfully');

    $html = Blade::render('<x-operational-toast />');

    expect($html)
        ->toContain('Settings saved successfully')
        ->toContain('alert-success')
        ->toContain('toast-progress')
        ->toContain('5000')
        ->toContain('true');
});

it('renders flashed session error with persistent behavior and no progress line', function (): void {
    session()->flash('error', 'Operation rejected by system');

    $html = Blade::render('<x-operational-toast />');

    expect($html)
        ->toContain('Operation rejected by system')
        ->toContain('alert-error')
        ->toContain('aria-live="assertive"')
        ->not->toContain('toast-progress')
        ->toContain('false');
});

/*
|--------------------------------------------------------------------------
| Confirmation Modal Translations Guard
|--------------------------------------------------------------------------
*/

it('resolves every modal confirmation key in all locales', function (string $key): void {
    foreach (['en', 'es', 'fr'] as $locale) {
        $value = __("admin.$key", ['name' => 'Test'], $locale);

        expect($value)
            ->not->toBe("admin.$key")
            ->not->toBeEmpty();
    }
})->with([
    // Titles and confirm labels (per entity).
    'modal_delete_acl_title',
    'modal_delete_acl_confirm',
    'modal_delete_backup_title',
    'modal_delete_backup_confirm',
    'modal_delete_bridge_title',
    'modal_delete_bridge_confirm',
    'modal_delete_call_block_title',
    'modal_delete_call_block_confirm',
    'modal_delete_call_broadcast_title',
    'modal_delete_call_broadcast_confirm',
    'modal_delete_call_detail_record_title',
    'modal_delete_call_detail_record_confirm',
    'modal_delete_call_flow_title',
    'modal_delete_call_flow_confirm',
    'modal_delete_call_forward_title',
    'modal_delete_call_forward_confirm',
    'modal_delete_conference_title',
    'modal_delete_conference_confirm',
    'modal_delete_conference_center_title',
    'modal_delete_conference_center_confirm',
    'modal_delete_destination_title',
    'modal_delete_destination_confirm',
    'modal_delete_device_title',
    'modal_delete_device_confirm',
    'modal_delete_dialplan_title',
    'modal_delete_dialplan_confirm',
    'modal_delete_domain_title',
    'modal_delete_domain_confirm',
    'modal_delete_email_title',
    'modal_delete_email_confirm',
    'modal_delete_email_template_title',
    'modal_delete_email_template_confirm',
    'modal_delete_emergency_title',
    'modal_delete_emergency_confirm',
    'modal_delete_extension_title',
    'modal_delete_extension_confirm',
    'modal_delete_extension_setting_title',
    'modal_delete_extension_setting_confirm',
    'modal_delete_fax_title',
    'modal_delete_fax_confirm',
    'modal_delete_feature_code_title',
    'modal_delete_feature_code_confirm',
    'modal_delete_file_store_title',
    'modal_delete_file_store_confirm',
    'modal_delete_follow_me_title',
    'modal_delete_follow_me_confirm',
    'modal_delete_gateway_title',
    'modal_delete_gateway_confirm',
    'modal_delete_group_title',
    'modal_delete_group_confirm',
    'modal_delete_inbound_route_title',
    'modal_delete_inbound_route_confirm',
    'modal_delete_ivr_menu_title',
    'modal_delete_ivr_menu_confirm',
    'modal_delete_music_on_hold_title',
    'modal_delete_music_on_hold_confirm',
    'modal_delete_number_translation_title',
    'modal_delete_number_translation_confirm',
    'modal_delete_outbound_route_title',
    'modal_delete_outbound_route_confirm',
    'modal_delete_pin_number_title',
    'modal_delete_pin_number_confirm',
    'modal_delete_queue_title',
    'modal_delete_queue_confirm',
    'modal_delete_recording_title',
    'modal_delete_recording_confirm',
    'modal_delete_ring_group_title',
    'modal_delete_ring_group_confirm',
    'modal_delete_setting_title',
    'modal_delete_setting_confirm',
    'modal_delete_sip_account_title',
    'modal_delete_sip_account_confirm',
    'modal_delete_sip_profile_title',
    'modal_delete_sip_profile_confirm',
    'modal_delete_sip_trunk_title',
    'modal_delete_sip_trunk_confirm',
    'modal_delete_speech_title',
    'modal_delete_speech_confirm',
    'modal_delete_template_title',
    'modal_delete_template_confirm',
    'modal_delete_tenant_title',
    'modal_delete_tenant_confirm',
    'modal_delete_tenant_domain_title',
    'modal_delete_tenant_domain_confirm',
    'modal_delete_tenant_limit_title',
    'modal_delete_tenant_limit_confirm',
    'modal_delete_time_condition_title',
    'modal_delete_time_condition_confirm',
    'modal_delete_transcription_title',
    'modal_delete_transcription_confirm',
    'modal_delete_user_title',
    'modal_delete_user_confirm',
    'modal_delete_voicemail_mailbox_title',
    'modal_delete_voicemail_mailbox_confirm',
    'modal_delete_voicemail_message_title',
    'modal_delete_voicemail_message_confirm',
    'modal_git_update_title',
    'git_update_steps',
    'active_calls_hangup',
    'active_calls_transfer',
    'active_calls_transfer_placeholder',
    'active_conferences_mute',
    'active_conferences_unmute',
    'active_conferences_kick',
    'call_center_pause',
    'call_center_unpause',
    'call_center_logout',
    'operator_panel_source',
    'operator_panel_originate',
    'operator_panel_hangup',
    'modal_git_update_confirm',
    'modal_oauth_disconnect_title',
    'modal_oauth_disconnect_confirm',
    'device_sip_account',
    'modal_run_backup_title',
    'modal_run_backup_confirm',
    // Shared message patterns.
    'modal_delete_generic_question',
    'modal_delete_generic_statement',
    // Entity-specific messages.
    'modal_delete_backup_message',
    'modal_delete_call_forward_message',
    'modal_delete_call_recording_message',
    'modal_delete_device_message',
    'modal_delete_dialplan_message',
    'modal_delete_domain_message',
    'modal_delete_emergency_message',
    'modal_delete_fax_message',
    'modal_delete_file_store_message',
    'modal_delete_group_message',
    'modal_delete_ring_group_message',
    'modal_delete_setting_message',
    'modal_delete_tenant_message',
    'modal_delete_user_message',
    'modal_delete_voicemail_mailbox_message',
    'modal_delete_voicemail_message_message',
    'modal_git_update_message',
    'modal_oauth_disconnect_message',
    'modal_run_backup_message',
    'send_call_broadcast',
    'call_broadcast_failed',
    'call_broadcast_answered',
    'modal_send_broadcast_title',
    'modal_send_broadcast_message',
    'modal_send_broadcast_confirm',
    'routing_translation_order',
    'modal_restore_title',
    'modal_restore_confirm',
    'modal_restore_message',
    'modal_restore_error_title',
    'error_page_500_title',
    'error_page_500_message',
    'error_page_503_title',
    'error_page_503_message',
    'error_page_request_id',
    // Error titles.
    'modal_delete_backup_error_title',
    'modal_delete_domain_error_title',
    'modal_delete_fax_error_title',
    'modal_delete_file_store_error_title',
    'modal_delete_group_error_title',
    'modal_delete_ivr_menu_error_title',
    'modal_delete_recording_error_title',
    'modal_delete_setting_error_title',
    'modal_delete_tenant_error_title',
    'modal_delete_user_error_title',
    'modal_delete_voicemail_mailbox_error_title',
    'modal_delete_voicemail_message_error_title',
    'modal_git_update_error_title',
    'modal_oauth_disconnect_error_title',
    // Shared component copy.
    'modal_cancel',
    'modal_close',
    'modal_typed_placeholder',
]);
