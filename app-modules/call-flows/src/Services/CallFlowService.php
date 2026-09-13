<?php

declare(strict_types=1);

namespace Modules\CallFlows\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Modules\CallFlows\Models\CallFlow;

/**
 * CRUD service for the CallFlow model with FreeSWITCH
 * dialplan XML generation.
 */
class CallFlowService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — call flows run after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct()
    {
        $this->modelClass = CallFlow::class;
    }

    /**
     * Generate dialplan XML for call flows.
     *
     * Each enabled call flow maps an extension to a destination
     * (extension, IVR, ring group, etc.). The dialplan extension
     * matches the flow's extension number and transfers to the
     * configured destination.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $flows = CallFlow::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('extension')
            ->get();

        if ($flows->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($flows as $flow) {
            $safeName = htmlspecialchars($flow->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeExt = htmlspecialchars($flow->extension, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"callflow_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeExt}$\">\n";

            if ($flow->destination_type !== null && $flow->destination_id !== null) {
                $safeType = htmlspecialchars($flow->destination_type, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $safeDest = htmlspecialchars($flow->destination_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"transfer\" data=\"{$safeDest} XML default\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
