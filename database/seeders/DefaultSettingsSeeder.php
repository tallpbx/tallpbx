<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\SettingServiceInterface;
use Illuminate\Database\Seeder;

/**
 * Seeds architecture-compatible global defaults adapted from FusionPBX.
 */
class DefaultSettingsSeeder extends Seeder
{
    /**
     * Create the seeder instance.
     */
    public function __construct(
        private readonly SettingServiceInterface $settings,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->defaults() as $key => $setting) {
            $this->settings->set($key, $setting['value'], $setting['type']);
        }
    }

    /**
     * Return filtered FusionPBX defaults that fit this application's model.
     *
     * @return array<string, array{value: scalar, type: string}>
     */
    private function defaults(): array
    {
        return [
            'domain.template' => ['value' => 'default', 'type' => 'string'],
            'domain.language_code' => ['value' => 'en-us', 'type' => 'string'],
            'domain.time_zone' => ['value' => 'UTC', 'type' => 'string'],
            'domain.country_code' => ['value' => 'us', 'type' => 'string'],
            'domain.bridge' => ['value' => 'loopback', 'type' => 'string'],
            'domain.paging' => ['value' => '100', 'type' => 'string'],
            'domain.time_format' => ['value' => '24h', 'type' => 'string'],
            'domain.dial_string' => ['value' => '{presence_id=${dialed_user}@${dialed_domain}}${sofia_contact(${dialed_user}@${dialed_domain})}', 'type' => 'string'],
            'email.method' => ['value' => 'smtp', 'type' => 'string'],
            'email.address_type' => ['value' => 'primary', 'type' => 'string'],
            'email.smtp_auth' => ['value' => true, 'type' => 'boolean'],
            'email.smtp_port' => ['value' => 587, 'type' => 'integer'],
            'email.smtp_from' => ['value' => 'no-reply@example.com', 'type' => 'string'],
            'email.smtp_from_name' => ['value' => 'TallPBX', 'type' => 'string'],
            'email.smtp_hostname' => ['value' => '', 'type' => 'string'],
            'email.smtp_host' => ['value' => '', 'type' => 'string'],
            'email.smtp_username' => ['value' => '', 'type' => 'string'],
            'email.smtp_password' => ['value' => '', 'type' => 'string'],
            'email.smtp_secure' => ['value' => 'tls', 'type' => 'string'],
            'email.smtp_crypto_method' => ['value' => 'tls', 'type' => 'string'],
            'email.smtp_validate_certificate' => ['value' => true, 'type' => 'boolean'],
            'extension.password_length' => ['value' => 20, 'type' => 'integer'],
            'extension.password_number' => ['value' => true, 'type' => 'boolean'],
            'extension.password_lowercase' => ['value' => true, 'type' => 'boolean'],
            'extension.password_uppercase' => ['value' => true, 'type' => 'boolean'],
            'extension.password_special' => ['value' => true, 'type' => 'boolean'],
            'extension.password_strength' => ['value' => 4, 'type' => 'integer'],
            'extension.session_rotate' => ['value' => true, 'type' => 'boolean'],
            'voicemail.voicemail_file' => ['value' => 'attach', 'type' => 'string'],
            'voicemail.keep_local' => ['value' => true, 'type' => 'boolean'],
            'voicemail.storage_type' => ['value' => 'file', 'type' => 'string'],
            'voicemail.message_max_length' => ['value' => 300, 'type' => 'integer'],
            'voicemail.message_silence_threshold' => ['value' => 200, 'type' => 'integer'],
            'voicemail.message_silence_seconds' => ['value' => 3, 'type' => 'integer'],
            'voicemail.password_length' => ['value' => 8, 'type' => 'integer'],
            'voicemail.greeting_max_length' => ['value' => 90, 'type' => 'integer'],
            'voicemail.greeting_silence_threshold' => ['value' => 200, 'type' => 'integer'],
            'voicemail.greeting_silence_seconds' => ['value' => 3, 'type' => 'integer'],
            'voicemail.display_domain_name' => ['value' => false, 'type' => 'boolean'],
            'voicemail.remote_access' => ['value' => false, 'type' => 'boolean'],
            'voicemail.message_order' => ['value' => 'asc', 'type' => 'string'],
            'voicemail.message_caller_id_number' => ['value' => 'before', 'type' => 'string'],
            'voicemail.message_date_time' => ['value' => 'before', 'type' => 'string'],
            'limit.call_center_queues' => ['value' => 3, 'type' => 'integer'],
            'call_center.agent_add_rows' => ['value' => 5, 'type' => 'integer'],
            'call_center.agent_edit_rows' => ['value' => 1, 'type' => 'integer'],
            'call_center.agent_contact_method' => ['value' => 'user', 'type' => 'string'],
            'call_center.refresh' => ['value' => 1500, 'type' => 'integer'],
            'call_center.queue_login' => ['value' => 'static', 'type' => 'string'],
            'ring_group.destination_add_rows' => ['value' => 5, 'type' => 'integer'],
            'ring_group.destination_edit_rows' => ['value' => 1, 'type' => 'integer'],
            'ring_group.destination_delay_max' => ['value' => 999, 'type' => 'integer'],
            'ring_group.destination_timeout_max' => ['value' => 999, 'type' => 'integer'],
            'ring_group.destination_range_enabled' => ['value' => true, 'type' => 'boolean'],
            'ring_group.extension_range' => ['value' => '700-799', 'type' => 'string'],
            'limit.ring_groups' => ['value' => 3, 'type' => 'integer'],
            'cdr.format' => ['value' => 'json', 'type' => 'string'],
            'cdr.storage' => ['value' => 'db', 'type' => 'string'],
            'cdr.limit' => ['value' => 800, 'type' => 'integer'],
            'cdr.stat_hours_limit' => ['value' => 24, 'type' => 'integer'],
            'cdr.http_enabled' => ['value' => false, 'type' => 'boolean'],
            'conference.extension_range' => ['value' => '200-299', 'type' => 'string'],
            'call_flow.extension_range' => ['value' => '30-39', 'type' => 'string'],
            'follow_me.strategy' => ['value' => 'enterprise', 'type' => 'string'],
            'dialplan.destination' => ['value' => 'destination_number', 'type' => 'string'],
            'limit.extensions' => ['value' => 3, 'type' => 'integer'],
            'limit.gateways' => ['value' => 3, 'type' => 'integer'],
            'time_conditions.region' => ['value' => 'usa', 'type' => 'string'],
            'recordings.storage_type' => ['value' => 'file', 'type' => 'string'],
            'recordings.recording_max_length' => ['value' => 300, 'type' => 'integer'],
            'call_block.enabled' => ['value' => true, 'type' => 'boolean'],
            'call_forward.enabled' => ['value' => true, 'type' => 'boolean'],
            'call_return.enabled' => ['value' => true, 'type' => 'boolean'],
            'fax.storage_type' => ['value' => 'file', 'type' => 'string'],
            'fax.tone_detect' => ['value' => true, 'type' => 'boolean'],
            'contacts.enabled' => ['value' => true, 'type' => 'boolean'],
            'contacts.default_group' => ['value' => 'default', 'type' => 'string'],
            'conference.pin_length' => ['value' => 6, 'type' => 'integer'],
            'operator.extension' => ['value' => '1000', 'type' => 'string'],
            'parking.extension_range' => ['value' => '5900-5999', 'type' => 'string'],
        ];
    }
}
