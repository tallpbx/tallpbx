<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Services\DestinationResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\InboundRoutes\Models\InboundRoute;

/**
 * Service for managing inbound routes and generating their
 * FreeSWITCH dialplan XML.
 */
class InboundRouteService implements ContextWideDialplanXmlContributor, InboundRouteServiceInterface
{
    /**
     * Resolves typed destinations into FreeSWITCH dialplan actions.
     */
    private DestinationResolver $destinationResolver;

    public function __construct(DestinationResolver $destinationResolver)
    {
        $this->destinationResolver = $destinationResolver;
    }

    /**
     * DID matching should run before outbound routing and after
     * emergency/block/feature-code contributors.
     */
    public function getDialplanPriority(): int
    {
        return 60;
    }

    /**
     * Create a new inbound route.
     */
    public function create(array $data): InboundRoute
    {
        return InboundRoute::create($data);
    }

    /**
     * Update an existing inbound route.
     */
    public function update(InboundRoute $route, array $data): InboundRoute
    {
        $route->update($data);

        return $route->fresh();
    }

    /**
     * Delete an inbound route.
     */
    public function delete(InboundRoute $route): void
    {
        $route->delete();
    }

    /**
     * Get all inbound routes for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return InboundRoute::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->orderBy('priority')
            ->get();
    }

    /**
     * Generate dialplan XML for inbound routes matching the destination.
     *
     * Queries enabled inbound routes for the given tenant, ordered by
     * priority. Each route contributes an extension with a condition
     * matching the destination_number field and an action element
     * routing the call to the configured destination.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        // Inbound routes apply in the public context (calls from outside)
        if (! str_ends_with($context, '_public')) {
            return null;
        }

        $routes = InboundRoute::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        if ($routes->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($routes as $route) {
            $resolvedAction = $this->resolveRouteAction($route, $tenantId);

            if ($resolvedAction === null) {
                Log::warning('Inbound route has unknown action, skipping.', [
                    'route_id' => $route->id,
                    'action' => $route->action,
                ]);

                continue;
            }

            $safeName = htmlspecialchars($route->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeDest = htmlspecialchars($route->destination_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeAction = htmlspecialchars($resolvedAction['application'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"inbound_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeDest}$\">\n";

            if ($resolvedAction['data'] !== '') {
                $safeData = htmlspecialchars($resolvedAction['data'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"{$safeAction}\" data=\"{$safeData}\"/>\n";
            } else {
                $xml .= "          <action application=\"{$safeAction}\"/>\n";
            }

            // Set effective caller ID if configured
            if ($route->caller_id_name !== null && $route->caller_id_name !== '') {
                $safeCidName = htmlspecialchars($route->caller_id_name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"set\" data=\"effective_caller_id_name={$safeCidName}\"/>\n";
            }

            if ($route->caller_id_number !== null && $route->caller_id_number !== '') {
                $safeCidNum = htmlspecialchars($route->caller_id_number, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"set\" data=\"effective_caller_id_number={$safeCidNum}\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }

    /**
     * Known-safe FreeSWITCH applications for inbound route actions.
     *
     * Restricts inbound route actions to a validated allowlist to
     * prevent arbitrary XML injection from user-editable route data.
     * Actions not in this list are skipped with a warning.
     *
     * @return string[]
     */
    private function allowedInboundActions(): array
    {
        return [
            'bridge',
            'transfer',
            'voicemail',
            'conference',
            'callcenter',
            'playback',
            'hangup',
            'answer',
            'sleep',
            'set',
            'log',
            'bind_digit_action',
            'read',
        ];
    }

    /**
     * Resolve an inbound route action to a FreeSWITCH application/data pair.
     *
     * Typed destination actions are resolved through DestinationResolver so
     * tenant ownership and enabled state are checked before XML is emitted.
     * Existing raw FreeSWITCH application actions continue to use the
     * allowlist for backward compatibility.
     *
     * @return array{application: string, data: string}|null
     */
    private function resolveRouteAction(InboundRoute $route, int $tenantId): ?array
    {
        if (in_array($route->action, DestinationResolver::routeSelectableKinds(), true)) {
            if ($route->action_data === null || $route->action_data === '') {
                return null;
            }

            try {
                return $this->destinationResolver->resolve($route->action, $route->action_data, $tenantId);
            } catch (\InvalidArgumentException $e) {
                Log::warning('Inbound route destination could not be resolved, skipping.', [
                    'route_id' => $route->id,
                    'action' => $route->action,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if (! in_array($route->action, $this->allowedInboundActions(), true)) {
            return null;
        }

        return [
            'application' => $route->action,
            'data' => $route->action_data ?? '',
        ];
    }
}
