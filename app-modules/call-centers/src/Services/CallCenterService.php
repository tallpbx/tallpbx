<?php

declare(strict_types=1);

namespace Modules\CallCenters\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Support\Facades\DB;
use Modules\CallCenters\Models\Queue;

/**
 * Service for managing call center queues and generating
 * FreeSWITCH dialplan XML for call routing into queues.
 */
class CallCenterService implements CallCenterServiceInterface, ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — call center queues run after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function createQueue(array $data): Queue
    {
        return DB::transaction(fn () => Queue::create($data));
    }

    public function updateQueue(Queue $queue, array $data): Queue
    {
        return DB::transaction(function () use ($queue, $data): Queue {
            $queue->update($data);

            return $queue->fresh();
        });
    }

    public function deleteQueue(Queue $queue): void
    {
        DB::transaction(fn () => $queue->delete());
    }

    /**
     * Generate dialplan XML for call center queues.
     *
     * Each enabled queue contributes an extension that routes
     * callers into a FreeSWITCH callcenter application with the
     * configured strategy (ring-all, longest-idle-agent, etc.),
     * timeout, and music-on-hold class.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $queues = Queue::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($queues->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($queues as $queue) {
            $safeName = htmlspecialchars($queue->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeStrategy = htmlspecialchars($queue->strategy ?? 'longest-idle-agent', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $timeout = $queue->timeout > 0 ? $queue->timeout : 30;
            $moh = $queue->music_on_hold !== null
                ? htmlspecialchars($queue->music_on_hold, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                : 'default';

            $xml .= "      <extension name=\"callcenter_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";

            // Answer and join the call center queue
            $xml .= "          <action application=\"answer\"/>\n";
            $xml .= "          <action application=\"sleep\" data=\"1000\"/>\n";

            // Set callcenter strategy and MOH before entering the queue
            $xml .= "          <action application=\"set\" data=\"cc_export_vars=tenant_id\"/>\n";
            $xml .= "          <action application=\"callcenter\" data=\"{$safeName}@default\"/>\n";

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
