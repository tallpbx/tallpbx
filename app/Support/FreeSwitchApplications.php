<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validates FreeSWITCH dialplan application names against a
 * whitelist of known-safe applications.
 *
 * This prevents arbitrary or malicious application names from
 * being injected into generated dialplan XML. Unknown or
 * potentially dangerous applications are rejected.
 *
 * The whitelist is intentionally conservative. Add new
 * applications only after confirming they are safe for
 * dynamic dialplan generation.
 */
final class FreeSwitchApplications
{
    /**
     * Known-safe FreeSWITCH dialplan applications.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'answer',
        'att_xfer',
        'bind_digit_action',
        'bind_meta_app',
        'bridge',
        'conference',
        'db',
        'deflect',
        'delay_echo',
        'detect_speech',
        'displace_session',
        'eavesdrop',
        'echo',
        'endless_playback',
        'eval',
        'event',
        'execute_extension',
        'export',
        'fifo',
        'gentones',
        'group',
        'hangup',
        'hiredis_raw',
        'info',
        'intercept',
        'ivr',
        'limit',
        'limit_execute',
        'limit_hash',
        'log',
        'multiset',
        'park',
        'phrase',
        'pickup',
        'play_and_detect_speech',
        'play_and_get_digits',
        'playback',
        'pre_answer',
        'privacy',
        'read',
        'record',
        'record_session',
        'redirect',
        'respond',
        'ring_ready',
        'say',
        'sched_broadcast',
        'sched_hangup',
        'sched_transfer',
        'send_display',
        'send_dtmf',
        'session_loglevel',
        'set',
        'set_audio_level',
        'set_name',
        'set_profile_var',
        'set_user',
        'sleep',
        'sofia_contact',
        'speak',
        'start_dtmf',
        'stop_dtmf',
        'stop_displace_session',
        'stop_record_session',
        'strftime',
        'three_way',
        'transfer',
        'unbind_meta_app',
        'unset',
        'verbose_events',
        'voicemail',
    ];

    /**
     * Check whether an application name is in the allowed list.
     */
    public static function isAllowed(string $application): bool
    {
        return in_array($application, self::ALLOWED, true);
    }

    /**
     * Get the list of allowed application names.
     *
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }
}
