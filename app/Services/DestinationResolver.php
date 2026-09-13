<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use Modules\Bridges\Models\Bridge;
use Modules\CallCenters\Models\Queue;
use Modules\Extensions\Models\Extension;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\RingGroups\Models\RingGroup;
use Modules\Voicemails\Models\Voicemail;

/**
 * Resolves typed PBX destinations into FreeSWITCH dialplan
 * application and data pairs.
 *
 * Each PBX feature module defines a destination "kind" (extension,
 * ring group, IVR, etc.). This resolver translates a kind + identifier
 * + tenant ID into the FreeSWITCH application name and data string
 * needed for call routing. All lookups enforce tenant ownership.
 *
 * Contributors that need to generate bridge or transfer targets
 * should use this resolver instead of building destination strings
 * directly — this ensures consistent dial string formatting and
 * tenant-scope verification across modules.
 *
 * Example:
 *   $resolver->resolve('extension', '100', $tenantId)
 *   // → ['application' => 'bridge', 'data' => 'user/100']
 */
class DestinationResolver
{
    /** Route to a specific extension. Identifier is the extension number or UUID. */
    public const KIND_EXTENSION = 'extension';

    /** Route to a ring group. Identifier is the ring group UUID. */
    public const KIND_RING_GROUP = 'ring_group';

    /** Transfer to an IVR menu. Identifier is the IVR menu name or UUID. */
    public const KIND_IVR = 'ivr';

    /** Send to voicemail. Identifier is the mailbox number or UUID. */
    public const KIND_VOICEMAIL = 'voicemail';

    /** Bridge to an external number through the default route. */
    public const KIND_EXTERNAL = 'external';

    /** Enter a conference bridge. Identifier is the bridge name or UUID. */
    public const KIND_CONFERENCE = 'conference';

    /** Enter a call center queue. Identifier is the queue name. */
    public const KIND_QUEUE = 'queue';

    /** Custom FreeSWITCH application with raw data. */
    public const KIND_CUSTOM = 'custom';

    /**
     * Destination kinds safe for user-facing route selection.
     *
     * The custom kind is intentionally excluded because it bypasses the
     * normal destination checks and returns raw application data.
     *
     * @return array<int, string>
     */
    public static function routeSelectableKinds(): array
    {
        return [
            self::KIND_EXTENSION,
            self::KIND_RING_GROUP,
            self::KIND_IVR,
            self::KIND_VOICEMAIL,
            self::KIND_EXTERNAL,
            self::KIND_CONFERENCE,
            self::KIND_QUEUE,
        ];
    }

    /**
     * Destination kinds that older route code can treat as resolver-backed.
     *
     * @return array<int, string>
     */
    public static function tenantScopedKinds(): array
    {
        return self::routeSelectableKinds();
    }

    /**
     * Resolve a typed destination into a FreeSWITCH action+data pair.
     *
     * Each kind queries the appropriate model with tenant-scope
     * enforcement. Returns the application name and data string
     * ready for dialplan XML generation.
     *
     * @param  string  $kind  One of the KIND_* constants
     * @param  string  $identifier  The destination identifier (UUID, number, or name)
     * @param  int  $tenantId  The owning tenant ID
     * @return array{application: string, data: string}
     *
     * @throws InvalidArgumentException When the kind is unknown or the destination is not found
     */
    public function resolve(string $kind, string $identifier, int $tenantId): array
    {
        return match ($kind) {
            self::KIND_EXTENSION => $this->resolveExtension($identifier, $tenantId),
            self::KIND_RING_GROUP => $this->resolveRingGroup($identifier, $tenantId),
            self::KIND_IVR => $this->resolveIvr($identifier, $tenantId),
            self::KIND_VOICEMAIL => $this->resolveVoicemail($identifier, $tenantId),
            self::KIND_EXTERNAL => $this->resolveExternal($identifier),
            self::KIND_CONFERENCE => $this->resolveConference($identifier, $tenantId),
            self::KIND_QUEUE => $this->resolveQueue($identifier, $tenantId),
            self::KIND_CUSTOM => $this->resolveCustom($identifier),
            default => throw new InvalidArgumentException("Unknown destination kind: {$kind}"),
        };
    }

    /**
     * Resolve an extension destination.
     *
     * Looks up the extension by number within the tenant and returns
     * a bridge to user/{extension_number}.
     */
    private function resolveExtension(string $identifier, int $tenantId): array
    {
        $extension = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($identifier) {
                $query->where('extension_number', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();

        if ($extension === null) {
            throw new InvalidArgumentException("Extension not found: {$identifier} in tenant {$tenantId}");
        }

        return [
            'application' => 'bridge',
            'data' => "user/{$extension->extension_number}",
        ];
    }

    /**
     * Resolve a ring group destination.
     *
     * Looks up the ring group by UUID and bridges to all member
     * extensions using the configured strategy.
     */
    private function resolveRingGroup(string $identifier, int $tenantId): array
    {
        $group = RingGroup::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with('extensions')
            ->find($identifier);

        if ($group === null) {
            throw new InvalidArgumentException("Ring group not found: {$identifier} in tenant {$tenantId}");
        }

        if ($group->extensions->isEmpty()) {
            return [
                'application' => 'hangup',
                'data' => 'NO_ANSWER',
            ];
        }

        $destinations = [];
        foreach ($group->extensions as $ext) {
            $destinations[] = 'user/'.$ext->extension_uuid;
        }

        return [
            'application' => 'bridge',
            'data' => implode(',', $destinations),
        ];
    }

    /**
     * Resolve an IVR menu destination.
     *
     * Transfers the call to the IVR by name so the IVR contributor
     * can handle digit collection.
     */
    private function resolveIvr(string $identifier, int $tenantId): array
    {
        $ivr = IvrMenu::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($identifier) {
                $query->where('name', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();

        if ($ivr === null) {
            throw new InvalidArgumentException("IVR menu not found: {$identifier} in tenant {$tenantId}");
        }

        return [
            'application' => 'transfer',
            'data' => $ivr->name.' XML '.$ivr->name,
        ];
    }

    /**
     * Resolve a voicemail destination.
     *
     * Routes to the voicemail application with the mailbox number.
     */
    private function resolveVoicemail(string $identifier, int $tenantId): array
    {
        $vm = Voicemail::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($identifier) {
                $query->where('voicemail_id', $identifier)
                    ->orWhere('mailbox', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();

        if ($vm === null) {
            throw new InvalidArgumentException("Voicemail not found: {$identifier} in tenant {$tenantId}");
        }

        return [
            'application' => 'voicemail',
            'data' => "default \${domain} {$vm->mailbox}",
        ];
    }

    /**
     * Resolve an external number destination.
     *
     * Bridges to the external number through the default SIP route.
     */
    private function resolveExternal(string $identifier): array
    {
        return [
            'application' => 'bridge',
            'data' => "sofia/external/{$identifier}",
        ];
    }

    /**
     * Resolve a conference bridge destination.
     */
    private function resolveConference(string $identifier, int $tenantId): array
    {
        $bridge = Bridge::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($identifier) {
                $query->where('bridge_name', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();

        if ($bridge === null) {
            throw new InvalidArgumentException("Conference bridge not found: {$identifier} in tenant {$tenantId}");
        }

        $confData = $bridge->pin_number !== null && $bridge->pin_number !== ''
            ? "{$bridge->bridge_name}@default+pin_{$bridge->pin_number}"
            : "{$bridge->bridge_name}@default";

        return [
            'application' => 'conference',
            'data' => $confData,
        ];
    }

    /**
     * Resolve a call center queue destination.
     */
    private function resolveQueue(string $identifier, int $tenantId): array
    {
        $queue = Queue::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($identifier) {
                $query->where('name', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();

        if ($queue === null) {
            throw new InvalidArgumentException("Call center queue not found: {$identifier} in tenant {$tenantId}");
        }

        return [
            'application' => 'callcenter',
            'data' => $queue->name,
        ];
    }

    /**
     * Resolve a custom application destination.
     *
     * The identifier is treated as "application::data" where the
     * application and data are separated by double-colons.
     */
    private function resolveCustom(string $identifier): array
    {
        $parts = explode('::', $identifier, 2);

        return [
            'application' => $parts[0],
            'data' => $parts[1] ?? '',
        ];
    }
}
