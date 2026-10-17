<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when authentication to the FreeSWITCH XML handler endpoint fails.
 *
 * Emitted by XmlHandlerController when a request fails shared-secret validation
 * (missing token or token mismatch). Used by the Security module to track brute-force
 * attempts and trigger automated kernel bans.
 */
class XmlHandlerFailedAuth
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $ip  Offending remote network IP address
     * @param  string  $reason  Failure reason ('missing_token' or 'token_mismatch')
     * @param  string  $section  Requested section ('directory', 'dialplan', 'configuration', etc.)
     */
    public function __construct(
        public string $ip,
        public string $reason = 'token_mismatch',
        public string $section = 'unknown',
    ) {}
}
