<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\FeatureCodes\Models\FeatureCode;

/**
 * Service implementation for managing feature codes with tenant-scoped
 * code uniqueness and FreeSWITCH dialplan XML generation.
 */
class FeatureCodeService implements ContextWideDialplanXmlContributor, FeatureCodeServiceInterface
{
    /**
     * Built-in feature codes match before generic inbound patterns.
     */
    public function getDialplanPriority(): int
    {
        return 50;
    }

    public function create(array $data): FeatureCode
    {
        $this->validateUniqueCode($data['tenant_id'], $data['code']);

        return FeatureCode::create($data);
    }

    public function update(FeatureCode $code, array $data): FeatureCode
    {
        if (isset($data['code']) && $data['code'] !== $code->code) {
            $this->validateUniqueCode($data['tenant_id'] ?? $code->tenant_id, $data['code'], $code->id);
        }

        $code->update($data);

        return $code->fresh();
    }

    public function delete(FeatureCode $code): void
    {
        $code->delete();
    }

    public function getByTenant(int $tenantId): Collection
    {
        return FeatureCode::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get();
    }

    private function validateUniqueCode(int $tenantId, string $code, ?string $excludeId = null): void
    {
        $query = FeatureCode::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('code', $code);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['This feature code is already in use within this tenant.'],
            ]);
        }
    }

    /**
     * Generate dialplan XML for feature codes (star codes).
     *
     * Each enabled feature code matches the dialed code (e.g., *97)
     * and executes the corresponding FreeSWITCH application. When
     * a code has an explicit application set, it generates real
     * behavior. Codes without an application fall back to log-only
     * (documentation / custom codes not yet mapped).
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $codes = FeatureCode::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('code')
            ->get();

        if ($codes->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($codes as $code) {
            $safeName = htmlspecialchars($code->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeCode = htmlspecialchars($code->code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeCodePattern = htmlspecialchars(preg_quote($code->code, '/'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

            // Markers must not shadow real implementations: the tenant context
            // is continue=false, and this contributor's extensions are emitted
            // before the standard dialplans, so the marker has to hand control
            // back to the dialplan engine for codes that have a built-in
            // implementation (for example *732 recording-start).
            $xml .= "      <extension name=\"feature_{$safeName}\" continue=\"true\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeCodePattern}$\">\n";

            // Generate actual FreeSWITCH application when configured,
            // otherwise fall back to log-only for custom/unknown codes.
            if ($code->application !== null && $code->application !== '') {
                $safeApp = htmlspecialchars($code->application, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if ($code->application_data !== null && $code->application_data !== '') {
                    $safeData = htmlspecialchars($code->application_data, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $xml .= "          <action application=\"{$safeApp}\" data=\"{$safeData}\"/>\n";
                } else {
                    $xml .= "          <action application=\"{$safeApp}\"/>\n";
                }
            } else {
                // Log-only: feature code has no mapped application yet
                $xml .= "          <action application=\"log\" data=\"INFO Feature code {$safeCode}: {$safeName}\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
