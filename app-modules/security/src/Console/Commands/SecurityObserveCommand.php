<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\ObserveTrafficLogged;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Support\ObserveMetricsParser;

/**
 * Artisan command to inspect Global Observe Mode status, packet counters,
 * and recent or live-streamed kernel observe events.
 */
class SecurityObserveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:observe
                            {--f|follow : Stream observe log events in real time}
                            {--b|broadcast : Broadcast fresh observe events to Security Center UI over Laravel Reverb}
                            {--lines=20 : Number of recent observed events to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect Global Observe Mode hit counters, recent kernel events, or stream real-time logs';

    /**
     * Execute the console command.
     */
    public function handle(SecurityExecutorInterface $executor): int
    {
        $observeMode = SecuritySetting::getBoolean('firewall_observe_mode', false);

        $this->info('=== TallPBX Host Firewall: Global Observe Mode ===');
        $this->newLine();

        if ($observeMode) {
            $this->line('<fg=yellow;options=bold>● STATUS: ACTIVE (Non-Blocking Observation Mode)</>');
            $this->line('Every rule evaluates and logs would-be drops to the kernel journal without blocking inbound traffic.');
        } else {
            $this->line('<fg=gray;options=bold>○ STATUS: DISABLED (Enforcing Policy Mode)</>');
            $this->line('The firewall is actively blocking matching packets. Turn Observe Mode on to test rules without drops.');
        }
        $this->newLine();

        // 1. Live Counters Table
        $status = $executor->status();
        $metrics = ObserveMetricsParser::parseCounters($status);

        $rows = [];
        foreach ($metrics['stages'] as $stage) {
            $bytesFormatted = $this->formatBytes($stage['bytes']);
            $label = $stage['label'];

            if ($stage['stage'] === 'tftp' && isset($stage['details']) && $stage['packets'] > 0) {
                $d = $stage['details'];
                $label .= sprintf(' (Up: %d, Trav: %d, Probe: %d, Flood: %d)', $d['uploads'], $d['traversal'], $d['probes'], $d['flood']);
            }

            $rows[] = [
                $label,
                number_format($stage['packets']),
                $bytesFormatted,
            ];
        }

        $rows[] = ['----------------------------------------', '---------', '---------'];
        $rows[] = [
            '<options=bold>TOTAL OBSERVED WOULD-BE DROPS</>',
            '<options=bold>'.number_format($metrics['total_packets']).'</>',
            '<options=bold>'.$this->formatBytes($metrics['total_bytes']).'</>',
        ];

        $this->table(['Firewall Stage', 'Packets', 'Bytes'], $rows);

        // 2. Recent Events
        $lines = max(1, min(200, (int) $this->option('lines')));
        $events = $executor->observeEvents($lines);

        $this->newLine();
        $this->info("=== Recent Observed Packet Events (Last {$lines}) ===");

        if (empty($events)) {
            $this->line('No recent observe events recorded in the system journal.');
        } else {
            $eventRows = array_map(function (array $event): array {
                $dst = $event['dst_ip'];
                if ($event['dpt'] !== null) {
                    $dst .= ':'.$event['dpt'];
                }

                return [
                    $event['timestamp'],
                    $event['stage_label'],
                    $event['interface'] !== '' ? $event['interface'] : '—',
                    $event['src_ip'],
                    $dst,
                    $event['proto'],
                ];
            }, $events);

            $this->table(['Timestamp', 'Rule Stage', 'Interface', 'Source IP', 'Destination', 'Proto'], $eventRows);
        }

        // 3. Live Stream Follow & Reverb Broadcasting
        $follow = (bool) $this->option('follow');
        $broadcast = (bool) $this->option('broadcast');

        if ($follow || $broadcast) {
            $this->newLine();
            if ($broadcast) {
                $this->info('Streaming observe mode events and broadcasting to Security Center over Laravel Reverb (Press Ctrl+C to exit)...');
            } else {
                $this->info('Streaming live observe mode events (Press Ctrl+C to exit)...');
            }

            $seenRaws = [];
            foreach ($events as $e) {
                $seenRaws[$e['raw']] = true;
            }

            // Bound seen cache size so it doesn't leak memory over days
            while (true) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                usleep(1000000); // 1 second poll

                $freshEvents = $executor->observeEvents(25);
                foreach ($freshEvents as $fresh) {
                    if (isset($seenRaws[$fresh['raw']])) {
                        continue;
                    }

                    $seenRaws[$fresh['raw']] = true;
                    if (count($seenRaws) > 1000) {
                        $seenRaws = array_slice($seenRaws, -500, null, true);
                    }

                    if ($broadcast) {
                        ObserveTrafficLogged::dispatch($fresh);
                    }

                    $dst = $fresh['dst_ip'];
                    if ($fresh['dpt'] !== null) {
                        $dst .= ':'.$fresh['dpt'];
                    }

                    $broadcastTag = $broadcast ? ' <fg=green>[Reverb Broadcast]</>' : '';

                    $this->line(sprintf(
                        '<fg=cyan>[%s]</> <fg=yellow>[%s]</> <fg=white>%s</> -> <fg=white>%s</> (%s via %s)%s',
                        $fresh['timestamp'],
                        $fresh['stage_label'],
                        $fresh['src_ip'],
                        $dst,
                        $fresh['proto'],
                        $fresh['interface'] !== '' ? $fresh['interface'] : 'local',
                        $broadcastTag
                    ));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Format byte count into human-readable representation.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = (float) $bytes;

        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }

        return sprintf($i === 0 ? '%d %s' : '%.2f %s', $val, $units[$i]);
    }
}
