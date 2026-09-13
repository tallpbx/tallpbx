<?php

declare(strict_types=1);

namespace Modules\Emergency\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Emergency\Models\Emergency;
use Modules\Gateways\Models\Gateway;

/**
 * Service implementation for emergency (E911) configuration
 * with FreeSWITCH dialplan XML generation.
 */
class EmergencyService implements ContextWideDialplanXmlContributor, EmergencyServiceInterface
{
    /**
     * Emergency rules must always run before any other routing.
     */
    public function getDialplanPriority(): int
    {
        return 10;
    }

    public function find(string $id): Emergency
    {
        return Emergency::withoutGlobalScope('tenant')->findOrFail($id);
    }

    public function all(): Collection
    {
        return Emergency::withoutGlobalScope('tenant')->orderBy('address')->get();
    }

    public function create(array $data): Emergency
    {
        return DB::transaction(fn () => Emergency::create($data));
    }

    public function update(Emergency $emergency, array $data): Emergency
    {
        return DB::transaction(function () use ($emergency, $data): Emergency {
            $emergency->update($data);

            return $emergency->fresh();
        });
    }

    public function delete(Emergency $emergency): void
    {
        DB::transaction(fn () => $emergency->delete());
    }

    /**
     * Generate dialplan XML for emergency (911) routing.
     *
     * When emergency config exists for a tenant, adds a high-priority
     * extension that matches emergency numbers (911, 112) and routes
     * with emergency caller ID and location data for PSAP dispatch.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $emergency = Emergency::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($emergency === null) {
            return null;
        }

        $xml = '';

        $xml .= "      <extension name=\"emergency_911\">\n";
        $xml .= "        <condition field=\"destination_number\" expression=\"^(911|112)$\" break=\"on-true\">\n";

        if ($emergency->caller_id !== null && $emergency->caller_id !== '') {
            $safeCid = htmlspecialchars($emergency->caller_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= "          <action application=\"set\" data=\"effective_caller_id_number={$safeCid}\"/>\n";
            $xml .= "          <action application=\"set\" data=\"emergency_caller_id_number={$safeCid}\"/>\n";
        }

        $safeAddr = htmlspecialchars($emergency->address, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml .= "          <action application=\"set\" data=\"emergency_caller_id_address={$safeAddr}\"/>\n";

        if ($emergency->latitude !== null && $emergency->longitude !== null) {
            $xml .= "          <action application=\"set\" data=\"geo-location={$emergency->latitude},{$emergency->longitude}\"/>\n";
        }

        // Look up a gateway named 'emergency' for this tenant.
        // Bridge through the external profile to reach the gateway.
        // Uses sofia/external/<dest>@<host>:<port> to bypass the profile
        // scope issue where sofia/gateway/ looks in the originating profile.
        $gateway = Gateway::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('name', 'emergency')
            ->where('enabled', true)
            ->first();

        if ($gateway !== null) {
            $safeHost = htmlspecialchars($gateway->host, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safePort = $gateway->port;
            $xml .= "          <action application=\"bridge\" data=\"sofia/external/\${destination_number}@{$safeHost}:{$safePort}\"/>\n";
        } else {
            $xml .= "          <action application=\"bridge\" data=\"sofia/external/\${destination_number}\"/>\n";
        }
        $xml .= "        </condition>\n";
        $xml .= "      </extension>\n";

        return $xml;
    }
}
