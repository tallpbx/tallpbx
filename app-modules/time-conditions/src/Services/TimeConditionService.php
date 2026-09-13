<?php

declare(strict_types=1);

namespace Modules\TimeConditions\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Modules\TimeConditions\Models\TimeCondition;

/**
 * CRUD service for the TimeCondition model with FreeSWITCH
 * dialplan XML generation.
 */
class TimeConditionService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — time conditions run after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct()
    {
        $this->modelClass = TimeCondition::class;
    }

    /**
     * Generate dialplan XML for time-based call routing.
     *
     * Each enabled time condition contributes a condition that checks
     * the current time/day against the configured window. Calls inside
     * the window route to destination_on_match; calls outside route
     * to destination_on_no_match.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $conditions = TimeCondition::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($conditions->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($conditions as $cond) {
            $safeName = htmlspecialchars($cond->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $weekdays = $cond->weekdays !== '' ? $cond->weekdays : '1-5';
            $start = $cond->start_time !== '' ? $cond->start_time : '00:00';
            $end = $cond->end_time !== '' ? $cond->end_time : '23:59';

            $xml .= "      <extension name=\"time_cond_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\" wday=\"{$weekdays}\" hour=\"{$start}-{$end}\" break=\"on-true\">\n";

            if ($cond->destination_on_match !== null && $cond->destination_on_match !== '') {
                $safeMatch = htmlspecialchars($cond->destination_on_match, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"transfer\" data=\"{$safeMatch} XML \${context}\"/>\n";
            }

            $xml .= "        </condition>\n";

            if ($cond->destination_on_no_match !== null && $cond->destination_on_no_match !== '') {
                $safeNoMatch = htmlspecialchars($cond->destination_on_no_match, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "        <condition>\n";
                $xml .= "          <action application=\"transfer\" data=\"{$safeNoMatch} XML \${context}\"/>\n";
                $xml .= "        </condition>\n";
            }

            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
