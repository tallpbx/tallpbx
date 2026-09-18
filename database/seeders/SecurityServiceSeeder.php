<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;

/**
 * Seeds standard PBX network services and default security settings.
 *
 * Populates the PBX Port Catalog with standard services:
 *   - SIP Signaling (5060, 5061, 5080)
 *   - RTP Voice/Video Media (16384:32768)
 *   - Web Admin Portal (80, 443)
 *   - SSH Console (22)
 *   - FreeSWITCH ESL (8021)
 *   - Reverb WebSockets (8080)
 *   - WebRTC WSS (7443)
 *
 * Also ensures baseline security configuration defaults are populated.
 */
class SecurityServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed standard PBX services into the port catalog
        $standardServices = [
            [
                'name' => 'ICMP Ping Diagnostics',
                'description' => 'Network reachability ping (IPv4 echo-request with burstable rate limit) and essential IPv6 neighbor discovery',
                'protocol' => 'icmp',
                'port_range' => 'echo-request',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
                'rate_limit' => 5,
                'burst' => 5,
            ],
            [
                'name' => 'SIP Signaling',
                'description' => 'SIP phone registration and call signaling (FreeSWITCH internal and external profiles)',
                'protocol' => 'both',
                'port_range' => '5060,5061,5080',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'RTP Voice/Video Media',
                'description' => 'Audio and video media packet streams',
                'protocol' => 'udp',
                'port_range' => '16384:32768',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'Web Admin Portal',
                'description' => 'HTTP and HTTPS secure web administrative interface',
                'protocol' => 'tcp',
                'port_range' => '80,443',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'SSH Console',
                'description' => 'Secure Shell host administrative terminal access',
                'protocol' => 'tcp',
                'port_range' => '22',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'FreeSWITCH ESL',
                'description' => 'Event Socket Layer remote control interface',
                'protocol' => 'tcp',
                'port_range' => '8021',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'Reverb WebSockets',
                'description' => 'Real-time WebSocket event broadcasting for web panels',
                'protocol' => 'tcp',
                'port_range' => '8080',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
            [
                'name' => 'WebRTC WSS',
                'description' => 'Secure WebRTC SIP signaling for browser communicators',
                'protocol' => 'tcp',
                'port_range' => '7443',
                'is_system' => true,
                'enabled' => true,
                'source_ip' => 'any',
            ],
        ];

        foreach ($standardServices as $service) {
            SecurityService::updateOrCreate(
                ['name' => $service['name']],
                $service,
            );
        }

        // 2. Seed baseline security configuration settings
        $defaultSettings = [
            'firewall_enabled' => 'true',
            'firewall_default_policy' => 'drop',
            'intrusion_detection_enabled' => 'true',
            'ban_time' => '3600',
            'find_time' => '600',
            'max_retry' => '5',
            'protect_sip' => 'true',
            'protect_web' => 'true',
            'protect_ssh' => 'true',
        ];

        foreach ($defaultSettings as $key => $val) {
            SecuritySetting::firstOrCreate(
                ['key' => $key],
                ['value' => $val],
            );
        }
    }
}
