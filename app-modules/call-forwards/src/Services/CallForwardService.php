<?php

declare(strict_types=1);

namespace Modules\CallForwards\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallForwards\Models\CallForward;
use Modules\Extensions\Models\Extension;

class CallForwardService implements CallForwardServiceInterface, ContextWideDialplanXmlContributor
{
    /**
     * Feature-level routing — call forwards run after emergency/blocks/DID matching.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function create(array $data): CallForward
    {
        return CallForward::create($data);
    }

    public function update(CallForward $forward, array $data): CallForward
    {
        $forward->update($data);

        return $forward->fresh();
    }

    public function delete(CallForward $forward): void
    {
        $forward->delete();
    }

    public function getByTenant(int $tenantId): Collection
    {
        return CallForward::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->orderBy('forward_type')
            ->get();
    }

    /**
     * Generate dialplan XML for call forward rules.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $forwards = CallForward::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('extension_uuid')
            ->get();

        if ($forwards->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($forwards as $fw) {
            // The condition must match the dialed extension number; the
            // internal extension uuid is not what callers dial.
            $extension = Extension::withoutGlobalScope('tenant')->find($fw->extension_uuid);
            if ($extension === null || $extension->extension_number === null) {
                continue;
            }

            // The extension name stays keyed on the internal uuid (stable and
            // unique), while the condition must match the dialed number.
            $safeName = htmlspecialchars($fw->extension_uuid, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeExt = htmlspecialchars($extension->extension_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeType = htmlspecialchars($fw->forward_type, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeDest = htmlspecialchars($fw->destination, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $timeout = $fw->ring_timeout > 0 ? $fw->ring_timeout : 60;

            // Re-enter the tenant dialplan so local extensions and external
            // numbers both resolve through the normal routing rules; a bare
            // destination number is not originatible.
            $bridgeTarget = '{dialplan=XML,context=${context}}'.$safeDest;

            $xml .= "      <extension name=\"forward_{$safeType}_{$safeName}\">\n";

            if ($fw->forward_type === 'unconditional') {
                $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeExt}$\">\n";
                $xml .= "          <action application=\"bridge\" data=\"{$bridgeTarget}\"/>\n";
            } elseif ($fw->forward_type === 'no-answer') {
                $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeExt}$\">\n";
                $xml .= "          <action application=\"set\" data=\"call_timeout={$timeout}\"/>\n";
                $xml .= "          <action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";
                $xml .= "          <action application=\"bridge\" data=\"{$bridgeTarget}\"/>\n";
            } elseif ($fw->forward_type === 'busy') {
                $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeExt}$\">\n";
                $xml .= "          <action application=\"set\" data=\"continue_on_fail=USER_BUSY\"/>\n";
                $xml .= "          <action application=\"bridge\" data=\"{$bridgeTarget}\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml !== '' ? $xml : null;
    }
}
