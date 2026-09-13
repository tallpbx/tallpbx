<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Models\OutboundRoute;

/**
 * Service for managing outbound routes and generating their
 * FreeSWITCH dialplan XML.
 */
class OutboundRouteService implements ContextWideDialplanXmlContributor, OutboundRouteServiceInterface
{
    /**
     * Catch-all outbound patterns run last after all inbound/feature matching.
     */
    public function getDialplanPriority(): int
    {
        return 90;
    }

    /**
     * Create a new outbound route.
     */
    public function create(array $data): OutboundRoute
    {
        return OutboundRoute::create($data);
    }

    /**
     * Update an existing outbound route.
     */
    public function update(OutboundRoute $route, array $data): OutboundRoute
    {
        $route->update($data);

        return $route->fresh();
    }

    /**
     * Delete an outbound route.
     */
    public function delete(OutboundRoute $route): void
    {
        $route->delete();
    }

    /**
     * Get all outbound routes for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return OutboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->orderBy('priority')
            ->get();
    }

    /**
     * Generate dialplan XML for outbound route pattern matching.
     *
     * Queries enabled outbound routes for the tenant, ordered by
     * priority. Each route contributes a condition matching its
     * dial_pattern (prefix regex) and bridging through the configured
     * gateway with optional caller ID manipulation.
     *
     * When gateway_id is set, the route bridges through the Sofia
     * gateway using the gateway UUID as the gateway name. When only
     * the legacy gateway string is set, backward-compatible behavior
     * is preserved with a deprecation log entry.
     *
     * Dial patterns must be valid regex. Bridge data using $1
     * requires the dial pattern to include a capturing group.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $routes = OutboundRoute::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with(['gatewayRelation' => function ($query): void {
                $query->withoutGlobalScope('tenant');
            }])
            ->orderBy('priority')
            ->get();

        if ($routes->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($routes as $route) {
            // Validate the dial pattern is a valid regex before generating XML
            if (! $this->isValidDialPattern($route->dial_pattern)) {
                Log::warning('Outbound route has invalid dial_pattern, skipping.', [
                    'route_id' => $route->id,
                    'dial_pattern' => $route->dial_pattern,
                ]);

                continue;
            }

            if (! $this->hasCapturingGroup($route->dial_pattern)) {
                Log::warning('Outbound route bridge uses capture substitution but dial_pattern has no capture group, skipping.', [
                    'route_id' => $route->id,
                    'dial_pattern' => $route->dial_pattern,
                ]);

                continue;
            }

            // Resolve bridge data — prefer gateway_id over legacy string
            $bridgeData = $this->resolveGatewayBridge($route);

            if ($bridgeData === null) {
                continue;
            }

            $safeName = htmlspecialchars($route->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safePattern = htmlspecialchars($route->dial_pattern, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"outbound_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safePattern}\">\n";

            if ($route->caller_id_name !== null && $route->caller_id_name !== '') {
                $safeCidName = htmlspecialchars($route->caller_id_name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"set\" data=\"effective_caller_id_name={$safeCidName}\"/>\n";
            }

            if ($route->caller_id_number !== null && $route->caller_id_number !== '') {
                $safeCidNum = htmlspecialchars($route->caller_id_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"set\" data=\"effective_caller_id_number={$safeCidNum}\"/>\n";
            }

            $xml .= "          <action application=\"bridge\" data=\"{$bridgeData}\"/>\n";
            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }

    /**
     * Resolve the bridge destination for an outbound route.
     *
     * Prefers gateway_id (stable UUID reference) over the legacy
     * gateway string. Validates that $1 substitution requires a
     * capturing group in the dial pattern.
     */
    private function resolveGatewayBridge(OutboundRoute $route): ?string
    {
        // Determine the gateway reference for the bridge string
        if ($route->gateway_id !== null) {
            $gateway = $route->gatewayRelation;

            if ($gateway instanceof Gateway && $gateway->tenant_id === $route->tenant_id && $gateway->enabled) {
                $safeGwName = htmlspecialchars($gateway->id, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                return "sofia/gateway/{$safeGwName}/\$1";
            }

            // gateway_id set but gateway is unavailable or cross-tenant.
            Log::warning('Outbound route references unavailable gateway.', [
                'route_id' => $route->id,
                'gateway_id' => $route->gateway_id,
            ]);

            if ($route->gateway === null || $route->gateway === '') {
                return null;
            }
        }

        if ($route->gateway !== null && $route->gateway !== '') {
            $safeGateway = htmlspecialchars($route->gateway, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            if (str_starts_with($route->gateway, 'sofia/')) {
                return $safeGateway;
            }

            return "sofia/gateway/{$safeGateway}/\$1";
        }

        // No gateway configured — bridge to external SIP directly
        return 'sofia/external/\$1';
    }

    /**
     * Determine whether a dial pattern has at least one capturing group.
     *
     * This intentionally ignores escaped parentheses and non-capturing /
     * lookaround groups. The generated bridge strings use $1, so a real
     * numbered capture is required.
     */
    public function hasCapturingGroup(string $pattern): bool
    {
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            if ($pattern[$index] !== '(') {
                continue;
            }

            if ($this->isEscaped($pattern, $index)) {
                continue;
            }

            $next = $pattern[$index + 1] ?? '';

            if ($next !== '?') {
                return true;
            }

            $modifier = $pattern[$index + 2] ?? '';
            if (Str::startsWith(substr($pattern, $index + 1), '?P<')) {
                return true;
            }

            if ($modifier === '<') {
                $lookaroundModifier = $pattern[$index + 3] ?? '';

                if ($lookaroundModifier !== '=' && $lookaroundModifier !== '!') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate a dial pattern as a PHP-compatible regular expression.
     */
    public function isValidDialPattern(string $pattern): bool
    {
        return @preg_match($this->wrapPattern($pattern), '') !== false;
    }

    /**
     * Wrap a pattern with a delimiter that does not conflict with its content.
     */
    private function wrapPattern(string $pattern): string
    {
        foreach (['~', '#', '%', '!'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter;
            }
        }

        return '/'.str_replace('/', '\\/', $pattern).'/';
    }

    /**
     * Check whether a character at the given offset is escaped.
     */
    private function isEscaped(string $pattern, int $offset): bool
    {
        $slashCount = 0;

        for ($index = $offset - 1; $index >= 0 && $pattern[$index] === '\\'; $index--) {
            $slashCount++;
        }

        return $slashCount % 2 === 1;
    }
}
