<?php

declare(strict_types=1);

namespace Modules\FollowMe\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Modules\FollowMe\Models\FollowMe;

/**
 * CRUD service for the FollowMe model with FreeSWITCH
 * dialplan XML generation.
 */
class FollowMeService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — follow-me rules run after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct()
    {
        $this->modelClass = FollowMe::class;
    }

    /**
     * Generate dialplan XML for follow-me call forwarding.
     *
     * Each enabled follow-me rule bridges calls to the source
     * extension, and if unanswered within the ring timeout,
     * forwards to the configured destination number.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $rules = FollowMe::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('extension')
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($rules as $rule) {
            $safeName = htmlspecialchars($rule->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeExt = htmlspecialchars($rule->extension, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeDest = htmlspecialchars($rule->destination, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $timeout = $rule->ring_timeout > 0 ? $rule->ring_timeout : 30;

            $xml .= "      <extension name=\"followme_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeExt}$\">\n";
            $xml .= "          <action application=\"set\" data=\"call_timeout={$timeout}\"/>\n";
            $xml .= "          <action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";
            $xml .= "          <action application=\"set\" data=\"continue_on_fail=true\"/>\n";
            $xml .= "          <action application=\"bridge\" data=\"\${sofia_contact({$safeExt}@\${domain_name})}\"/>\n";
            $xml .= "          <action application=\"bridge\" data=\"{$safeDest}\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
