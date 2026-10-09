<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Support\ObserveMetricsParser;

/**
 * Tests for the ObserveMetricsParser support helper.
 */
it('parses structured packet and byte counters from nftables ruleset status', function (): void {
    $ruleset = <<<NFT
table inet tallpbx_filter {
    chain input {
        type filter hook input priority filter - 10; policy accept;
        iif "lo" accept
        ct state invalid counter packets 4 bytes 320 log prefix "tallpbx-observe:invalid " limit rate 100/minute burst 5 packets
        ip saddr @blacklist_ips counter packets 12 bytes 960 log prefix "tallpbx-observe:blacklist " limit rate 100/minute burst 5 packets
        ip saddr @banned_ips counter packets 33 bytes 1665 log prefix "tallpbx-observe:bans " limit rate 100/minute burst 5 packets
        ip saddr @threat_feed_ips counter packets 8 bytes 640 log prefix "tallpbx-observe:threat_feeds " limit rate 100/minute burst 5 packets
        udp dport 69 @th,64,16 0x2 counter packets 1 bytes 57 log prefix "tallpbx-observe:tftp " limit rate 100/minute burst 5 packets
        udp dport 69 @th,64,16 0x1 @th,80,24 0x2e2e2f counter packets 5 bytes 265 log prefix "tallpbx-observe:tftp " limit rate 100/minute burst 5 packets
        udp dport 69 @th,64,32 0x12f78 counter packets 2 bytes 100 log prefix "tallpbx-observe:tftp " limit rate 100/minute burst 5 packets
        udp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter packets 10 bytes 470 log prefix "tallpbx-observe:tftp " limit rate 100/minute burst 5 packets
        ip saddr 192.0.2.0/24 counter packets 7 bytes 420 log prefix "tallpbx-observe:custom " limit rate 100/minute burst 5 packets
    }
}
NFT;

    $parsed = ObserveMetricsParser::parseCounters($ruleset);

    expect($parsed['total_packets'])->toBe(82)
        ->and($parsed['total_bytes'])->toBe(4897)
        ->and($parsed['stages']['invalid']['packets'])->toBe(4)
        ->and($parsed['stages']['blacklist']['packets'])->toBe(12)
        ->and($parsed['stages']['bans']['packets'])->toBe(33)
        ->and($parsed['stages']['threat_feeds']['packets'])->toBe(8)
        ->and($parsed['stages']['custom']['packets'])->toBe(7)
        ->and($parsed['stages']['tftp']['packets'])->toBe(18) // 1 + 5 + 2 + 10
        ->and($parsed['stages']['tftp']['details'])->toBe([
            'uploads' => 1,
            'traversal' => 5,
            'probes' => 2,
            'flood' => 10,
        ]);
});

it('handles empty or blank kernel ruleset gracefully', function (): void {
    $parsed = ObserveMetricsParser::parseCounters('');

    expect($parsed['total_packets'])->toBe(0)
        ->and($parsed['total_bytes'])->toBe(0)
        ->and($parsed['stages']['bans']['packets'])->toBe(0);
});

it('parses raw journal lines into structured observe events', function (): void {
    $rawLog = <<<LOG
2026-10-08T22:44:33-07:00 fspbx1 kernel: tallpbx-observe:tftp IN=veth-host OUT= MAC=72:60:bd:27:e6:a7:aa:f6:33:10:23:dc:08:00 SRC=10.254.254.2 DST=10.254.254.1 LEN=47 TOS=0x00 PREC=0x00 TTL=64 ID=38512 DF PROTO=UDP SPT=43032 DPT=69 LEN=27
2026-10-08T22:44:33-07:00 fspbx1 kernel: tallpbx-observe:bans IN=veth-host OUT= MAC=72:60:bd:27:e6:a7:aa:f6:33:10:23:dc:08:00 SRC=10.254.254.2 DST=10.254.254.1 LEN=84 TOS=0x00 PREC=0x00 TTL=64 ID=57966 DF PROTO=ICMP TYPE=8 CODE=0 ID=54294 SEQ=1
LOG;

    $events = ObserveMetricsParser::parseEvents($rawLog);

    expect($events)->toHaveCount(2)
        ->and($events[0]['stage'])->toBe('tftp')
        ->and($events[0]['stage_label'])->toBe('TFTP Defense')
        ->and($events[0]['interface'])->toBe('veth-host')
        ->and($events[0]['src_ip'])->toBe('10.254.254.2')
        ->and($events[0]['dst_ip'])->toBe('10.254.254.1')
        ->and($events[0]['proto'])->toBe('UDP')
        ->and($events[0]['spt'])->toBe('43032')
        ->and($events[0]['dpt'])->toBe('69')
        ->and($events[1]['stage'])->toBe('bans')
        ->and($events[1]['proto'])->toBe('ICMP');
});
