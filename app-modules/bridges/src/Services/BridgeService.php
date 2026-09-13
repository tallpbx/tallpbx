<?php

declare(strict_types=1);

namespace Modules\Bridges\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Modules\Bridges\Models\Bridge;

/**
 * CRUD service for the Bridge model with FreeSWITCH dialplan
 * XML generation.
 */
class BridgeService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — conference bridges match after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct()
    {
        $this->modelClass = Bridge::class;
    }

    /**
     * Generate dialplan XML for bridge (conference room) destinations.
     *
     * Each enabled bridge contributes an extension that routes callers
     * into a FreeSWITCH conference bridge. When a PIN is configured,
     * the caller must enter it before joining.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $bridges = Bridge::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('bridge_name')
            ->get();

        if ($bridges->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($bridges as $bridge) {
            $safeName = htmlspecialchars($bridge->bridge_name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeDest = htmlspecialchars($bridge->destination_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"bridge_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeDest}$\">\n";
            $xml .= "          <action application=\"answer\"/>\n";
            $xml .= "          <action application=\"sleep\" data=\"1000\"/>\n";

            if ($bridge->pin_number !== null && $bridge->pin_number !== '') {
                $safePin = htmlspecialchars($bridge->pin_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $confData = htmlspecialchars("{$safeName}@default+pin_{$safePin}", ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"conference\" data=\"{$confData}\"/>\n";
            } else {
                $confData = htmlspecialchars("{$safeName}@default", ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"conference\" data=\"{$confData}\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
