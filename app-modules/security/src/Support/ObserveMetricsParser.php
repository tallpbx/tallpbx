<?php

declare(strict_types=1);

namespace Modules\Security\Support;

/**
 * Parses kernel packet filtering status and journal log output for Global Observe Mode.
 */
class ObserveMetricsParser
{
    /**
     * Parse live nftables kernel ruleset status into structured Observe Mode counters.
     *
     * @param  string  $kernelStatus  Raw ruleset output from SecurityExecutor::status()
     * @return array{
     *     total_packets: int,
     *     total_bytes: int,
     *     stages: array<string, array{stage: string, label: string, packets: int, bytes: int, details?: array<string, int>}>
     * }
     */
    public static function parseCounters(string $kernelStatus): array
    {
        $stages = [
            'bans' => [
                'stage' => 'bans',
                'label' => 'Banned Attackers',
                'packets' => 0,
                'bytes' => 0,
            ],
            'blacklist' => [
                'stage' => 'blacklist',
                'label' => 'Permanent Blacklist',
                'packets' => 0,
                'bytes' => 0,
            ],
            'threat_feeds' => [
                'stage' => 'threat_feeds',
                'label' => 'Threat Feeds (VoIPBL)',
                'packets' => 0,
                'bytes' => 0,
            ],
            'tftp' => [
                'stage' => 'tftp',
                'label' => 'TFTP Exploit Defense',
                'packets' => 0,
                'bytes' => 0,
                'details' => [
                    'uploads' => 0,
                    'traversal' => 0,
                    'probes' => 0,
                    'flood' => 0,
                ],
            ],
            'invalid' => [
                'stage' => 'invalid',
                'label' => 'Invalid Packet State',
                'packets' => 0,
                'bytes' => 0,
            ],
            'custom' => [
                'stage' => 'custom',
                'label' => 'Custom Drop Rules',
                'packets' => 0,
                'bytes' => 0,
            ],
        ];

        if (trim($kernelStatus) === '') {
            return [
                'total_packets' => 0,
                'total_bytes' => 0,
                'stages' => $stages,
            ];
        }

        $lines = explode("\n", $kernelStatus);

        foreach ($lines as $line) {
            $line = trim($line);

            if (! str_contains($line, 'tallpbx-observe:')) {
                continue;
            }

            // Extract packets and bytes from: counter packets N bytes M log prefix "tallpbx-observe:<stage> "
            if (preg_match('/counter packets (\d+) bytes (\d+) log prefix "tallpbx-observe:([a-z_]+)\s*"/', $line, $matches) !== 1) {
                continue;
            }

            $pkts = (int) $matches[1];
            $bytes = (int) $matches[2];
            $stageKey = $matches[3];

            if (isset($stages[$stageKey])) {
                $stages[$stageKey]['packets'] += $pkts;
                $stages[$stageKey]['bytes'] += $bytes;
            }
        }

        // Detailed TFTP breakdown
        $extract = static function (string $pattern) use ($kernelStatus): int {
            return preg_match($pattern, $kernelStatus, $matches) === 1 ? (int) $matches[1] : 0;
        };

        $tftpUploads = $extract('/@th,64,16 0x0*2 counter packets (\d+)/');
        $tftpTraversal = $extract('/@th,80,24 0x2e2e2f counter packets (\d+)/');
        $tftpProbes = $extract('/(?:@th,80,16 0x2f78|@th,64,32 0x12f78) counter packets (\d+)/');
        $floodV4 = $extract('/@tftp_flood4 .* counter packets (\d+)/');
        $floodV6 = $extract('/@tftp_flood6 .* counter packets (\d+)/');

        $stages['tftp']['details'] = [
            'uploads' => $tftpUploads,
            'traversal' => $tftpTraversal,
            'probes' => $tftpProbes,
            'flood' => $floodV4 + $floodV6,
        ];

        $totalPackets = array_sum(array_column($stages, 'packets'));
        $totalBytes = array_sum(array_column($stages, 'bytes'));

        return [
            'total_packets' => $totalPackets,
            'total_bytes' => $totalBytes,
            'stages' => $stages,
        ];
    }

    /**
     * Parse raw journal log lines into structured event objects.
     *
     * @param  string  $rawJournalOutput  Log output from journalctl
     * @return array<int, array{
     *     timestamp: string,
     *     raw_timestamp: string,
     *     stage: string,
     *     stage_label: string,
     *     interface: string,
     *     src_ip: string,
     *     dst_ip: string,
     *     proto: string,
     *     spt: string|null,
     *     dpt: string|null,
     *     raw: string
     * }>
     */
    public static function parseEvents(string $rawJournalOutput): array
    {
        $events = [];

        if (trim($rawJournalOutput) === '') {
            return $events;
        }

        $stageLabels = [
            'bans' => 'Banned Attacker',
            'blacklist' => 'Blacklist IP',
            'threat_feeds' => 'Threat Feed',
            'tftp' => 'TFTP Defense',
            'invalid' => 'Invalid Packet',
            'custom' => 'Custom Rule',
        ];

        $lines = explode("\n", trim($rawJournalOutput));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, 'tallpbx-observe:')) {
                continue;
            }

            // Example line:
            // 2026-10-08T22:44:33-07:00 host kernel: tallpbx-observe:tftp IN=veth-host OUT= MAC=... SRC=10.254.254.2 DST=10.254.254.1 LEN=47 ... PROTO=UDP SPT=43032 DPT=69
            $timestamp = '';
            $rawTimestamp = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})/', $line, $tsMatch) === 1) {
                $rawTimestamp = $tsMatch[1];
                $timestamp = str_replace('T', ' ', substr($rawTimestamp, 0, 19));
            } elseif (preg_match('/^([A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2})/', $line, $tsMatch) === 1) {
                $rawTimestamp = $tsMatch[1];
                $timestamp = $rawTimestamp;
            }

            $stage = 'unknown';
            if (preg_match('/tallpbx-observe:([a-z_]+)/', $line, $sMatch) === 1) {
                $stage = $sMatch[1];
            }

            $interface = '';
            if (preg_match('/\bIN=([a-zA-Z0-9_\.-]+)/', $line, $inMatch) === 1) {
                $interface = $inMatch[1];
            }

            $srcIp = '';
            if (preg_match('/\bSRC=([a-fA-F0-9\.:]+)/', $line, $srcMatch) === 1) {
                $srcIp = $srcMatch[1];
            }

            $dstIp = '';
            if (preg_match('/\bDST=([a-fA-F0-9\.:]+)/', $line, $dstMatch) === 1) {
                $dstIp = $dstMatch[1];
            }

            $proto = 'IP';
            if (preg_match('/\bPROTO=([a-zA-Z0-9]+)/', $line, $prMatch) === 1) {
                $proto = strtoupper($prMatch[1]);
            }

            $spt = null;
            if (preg_match('/\bSPT=(\d+)/', $line, $sptMatch) === 1) {
                $spt = $sptMatch[1];
            }

            $dpt = null;
            if (preg_match('/\bDPT=(\d+)/', $line, $dptMatch) === 1) {
                $dpt = $dptMatch[1];
            }

            $events[] = [
                'timestamp' => $timestamp !== '' ? $timestamp : now()->toDateTimeString(),
                'raw_timestamp' => $rawTimestamp,
                'stage' => $stage,
                'stage_label' => $stageLabels[$stage] ?? ucfirst($stage),
                'interface' => $interface,
                'src_ip' => $srcIp,
                'dst_ip' => $dstIp,
                'proto' => $proto,
                'spt' => $spt,
                'dpt' => $dpt,
                'raw' => $line,
            ];
        }

        return $events;
    }
}
