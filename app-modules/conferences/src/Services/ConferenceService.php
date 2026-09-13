<?php

declare(strict_types=1);

namespace Modules\Conferences\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Support\CrudService;
use Modules\Conferences\Models\Conference;

/**
 * CRUD service for the Conference model with FreeSWITCH
 * dialplan XML generation.
 */
class ConferenceService extends CrudService implements ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — conferences match after emergency/blocks/DID.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function __construct()
    {
        $this->modelClass = Conference::class;
    }

    /**
     * Generate dialplan XML for conference rooms.
     *
     * Each enabled conference room contributes an extension that
     * routes callers into a FreeSWITCH conference bridge with the
     * configured profile, optional PIN authentication, and
     * member limit.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $conferences = Conference::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($conferences->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($conferences as $conf) {
            $safeName = htmlspecialchars($conf->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeProfile = htmlspecialchars($conf->profile ?? 'default', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $maxMembers = $conf->max_members > 0 ? $conf->max_members : 100;

            $xml .= "      <extension name=\"conference_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";

            // Answer the call
            $xml .= "          <action application=\"answer\"/>\n";

            // Set conference flags
            $xml .= "          <action application=\"set\" data=\"conference_flags=wait-mod|moderator-notify\"/>\n";

            $confData = "{$safeName}@{$safeProfile}+flags_{wait-mod|moderator-notify}";

            if ($conf->pin !== null && $conf->pin !== '') {
                $safePin = htmlspecialchars($conf->pin, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $confData .= "+pin_{$safePin}";
            }

            $confData .= "+max-members_{$maxMembers}";
            $safeConfData = htmlspecialchars($confData, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "          <action application=\"conference\" data=\"{$safeConfData}\"/>\n";

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
