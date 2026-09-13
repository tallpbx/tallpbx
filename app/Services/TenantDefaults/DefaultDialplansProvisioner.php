<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use App\Services\DialplanContext;
use Modules\Dialplans\Models\Dialplan;

/**
 * Creates safe baseline dialplans supported by the current XML renderer.
 */
final class DefaultDialplansProvisioner
{
    /**
     * Create the provisioner instance.
     */
    public function __construct(
        private readonly DialplanContext $dialplanContext,
    ) {}

    /**
     * Provision missing dialplans for the tenant.
     *
     * @return array{created: int, skipped: int}
     */
    public function provision(Tenant $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->dialplans((string) $tenant->id) as $dialplanData) {
            $exists = Dialplan::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('context', $dialplanData['context'])
                ->where('name', $dialplanData['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $details = $dialplanData['details'];
            unset($dialplanData['details']);

            $dialplan = Dialplan::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                ...$dialplanData,
                'enabled' => true,
            ]);

            $dialplan->details()->createMany($details);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Return current-schema-compatible dialplan definitions.
     *
     * @return array<int, array{name: string, description: string, context: string, order: int, details: array<int, array{tag: string, field: string|null, expression: string|null, action: string|null, data: string|null, order: int}>}>
     */
    private function dialplans(string $tenantId): array
    {
        $internalContext = $this->dialplanContext->internal($tenantId);
        $publicContext = $this->dialplanContext->public($tenantId);
        $recordingSpool = rtrim((string) config('media-storage.spool_root'), '/').'/'.$tenantId.'/call-recording';

        return [
            $this->dialplan('domain-variables', 'Export tenant domain variables.', $internalContext, 10, [
                $this->condition('destination_number', '^(.*)$', 'export', 'domain_name=${domain_name}', 10),
            ]),
            $this->dialplan('echo', 'FreeSWITCH echo test audio loopback.', $internalContext, 15, [
                $this->condition('destination_number', '^\\*?9196$', 'answer', null, 10),
                $this->condition('destination_number', '^\\*?9196$', 'echo', null, 20),
            ]),
            $this->dialplan('is_local', 'Mark local extension dialing.', $internalContext, 20, [
                $this->condition('destination_number', '^([1-9][0-9]{2,5})$', 'set', 'is_local=true', 10),
            ]),
            $this->dialplan('local_extension', 'Route tenant extension-to-extension calls.', $internalContext, 30, $this->localExtensionDetails()),
            $this->dialplan('directory', 'Name-based directory access.', $internalContext, 40, [
                $this->condition('destination_number', '^411$', 'directory', 'default ${domain_name}', 10),
            ]),
            $this->dialplan('intercept', 'Pickup a ringing extension.', $internalContext, 50, [
                $this->condition('destination_number', '^\\*870([1-9][0-9]{2,5})$', 'intercept', '${hash(select/${domain_name}-$1)}', 10),
            ]),
            $this->dialplan('page', 'Intercom and paging access.', $internalContext, 60, [
                $this->condition('destination_number', '^\\*8([1-9][0-9]{2,5})$', 'bridge', 'user/$1@${domain_name}', 10),
            ]),
            $this->dialplan('agent-status', 'Call center agent status menu.', $internalContext, 70, [
                $this->condition('destination_number', '^\\*8[56]$', 'answer', null, 10),
            ]),
            $this->dialplan('call-forward', 'Call forwarding feature access.', $internalContext, 80, [
                $this->condition('destination_number', '^\\*2[23]$', 'answer', null, 10),
            ]),
            $this->dialplan('follow-me', 'Follow-me feature toggle.', $internalContext, 90, [
                $this->condition('destination_number', '^\\*72$', 'answer', null, 10),
            ]),
            $this->dialplan('call_screen', 'Call screening feature entry.', $internalContext, 100, [
                $this->condition('destination_number', '^\\*screen$', 'answer', null, 10),
            ]),
            $this->dialplan('call_block', 'Call blocking feature entry.', $internalContext, 110, [
                $this->condition('destination_number', '^\\*878$', 'answer', null, 10),
            ]),
            $this->dialplan('voicemail', 'Voicemail access.', $internalContext, 120, [
                $this->condition('destination_number', '^\\*9[78]$', 'voicemail', 'check default ${domain_name}', 10),
            ]),
            $this->dialplan('recording-start', 'Start full-call recording for the current channel.', $internalContext, 130, [
                $this->condition('destination_number', '^\\*732$', 'answer', null, 10),
                // Mono recording: forcing RECORD_STEREO makes record_session
                // fail instantly on a single-leg *732 call.
                $this->condition('destination_number', '^\\*732$', 'record_session', $recordingSpool.'/${uuid}.wav', 20),
            ]),
            $this->dialplan('recording-stop', 'Stop full-call recording for the current channel.', $internalContext, 131, [
                $this->condition('destination_number', '^\\*733$', 'stop_record_session', $recordingSpool.'/${uuid}.wav', 10),
            ]),
            $this->dialplan('cidlookup', 'Caller ID lookup placeholder.', $publicContext, 10, [
                $this->condition('destination_number', '^(.*)$', 'set', 'effective_caller_id_name=${caller_id_name}', 10),
            ]),
        ];
    }

    /**
     * Build local extension actions, including optional mod_hiredis probes.
     *
     * The Redis-backed actions are stored with default dialplans so enabling the
     * matching config later only requires rebuilding XML, not recreating tenant
     * dialplan rows. The XML handler decides whether to emit them at runtime.
     *
     * @return array<int, array{tag: string, field: string|null, expression: string|null, action: string|null, data: string|null, order: int}>
     */
    private function localExtensionDetails(): array
    {
        return [
            $this->condition(
                'destination_number',
                '^([1-9][0-9]{2,5})$',
                'limit',
                'hiredis default pbx:${domain_name}:local_extension:active '.((int) config('freeswitch.xml_handler.hiredis_limit_max', 100000)),
                10
            ),
            $this->condition(
                'destination_number',
                '^([1-9][0-9]{2,5})$',
                'hiredis_raw',
                'default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}->${destination_number}',
                20
            ),
            $this->condition('destination_number', '^([1-9][0-9]{2,5})$', 'bridge', '${sofia_contact($1@${domain_name})}', 30),
        ];
    }

    /**
     * Build a dialplan definition.
     *
     * @param  array<int, array{tag: string, field: string|null, expression: string|null, action: string|null, data: string|null, order: int}>  $details
     * @return array{name: string, description: string, context: string, order: int, details: array<int, array{tag: string, field: string|null, expression: string|null, action: string|null, data: string|null, order: int}>}
     */
    private function dialplan(string $name, string $description, string $context, int $order, array $details): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'context' => $context,
            'order' => $order,
            'details' => $details,
        ];
    }

    /**
     * Build a single condition/action detail row.
     *
     * @return array{tag: string, field: string|null, expression: string|null, action: string|null, data: string|null, order: int}
     */
    private function condition(?string $field, ?string $expression, ?string $action, ?string $data, int $order): array
    {
        return [
            'tag' => 'condition',
            'field' => $field,
            'expression' => $expression,
            'action' => $action,
            'data' => $data,
            'order' => $order,
        ];
    }
}
