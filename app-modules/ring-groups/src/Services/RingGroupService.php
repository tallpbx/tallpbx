<?php

declare(strict_types=1);

namespace Modules\RingGroups\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;

/**
 * Service implementing ring group CRUD with atomic extension management
 * and FreeSWITCH dialplan XML generation.
 *
 * Ring groups and their assigned extensions are managed in a database
 * transaction to prevent partial updates. Old extensions are replaced
 * on update (delete-all-then-insert).
 */
class RingGroupService implements ContextWideDialplanXmlContributor, RingGroupServiceInterface
{
    /**
     * Feature-level routing — ring groups run after emergency/blocks/DID matching.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    /**
     * Create a new ring group with extensions inside a transaction.
     */
    public function create(array $data, array $extensions): RingGroup
    {
        return DB::transaction(function () use ($data, $extensions): RingGroup {
            $group = RingGroup::create($data);

            $this->syncExtensions($group, $extensions);

            return $group->fresh('extensions');
        });
    }

    /**
     * Update a ring group and replace all its extensions inside a transaction.
     */
    public function update(RingGroup $group, array $data, array $extensions): RingGroup
    {
        return DB::transaction(function () use ($group, $data, $extensions): RingGroup {
            $group->update($data);

            // Replace all extensions: delete existing, then insert new ones
            $group->extensions()->delete();
            $this->syncExtensions($group, $extensions);

            return $group->fresh('extensions');
        });
    }

    /**
     * Delete a ring group. Extension cascade is handled by the database FK.
     */
    public function delete(RingGroup $group): void
    {
        $group->delete();
    }

    /**
     * Get all ring groups with their extensions, ordered by name.
     *
     * Uses withoutGlobalScope to bypass tenant scoping (visible to admin).
     *
     * @return Collection<int, RingGroup>
     */
    public function getAll(): Collection
    {
        return RingGroup::withoutGlobalScope('tenant')
            ->with('extensions')
            ->orderBy('name')
            ->get();
    }

    /**
     * Batch-create extension records for a ring group.
     *
     * Generates auto-incrementing position values when not provided.
     *
     * @param  array<int, array<string, mixed>>  $extensions
     */
    private function syncExtensions(RingGroup $group, array $extensions): void
    {
        foreach ($extensions as $index => $ext) {
            RingGroupExtension::create([
                'ring_group_id' => $group->id,
                'extension_uuid' => $ext['extension_uuid'] ?? '',
                'position' => $ext['position'] ?? $index + 1,
            ]);
        }
    }

    /**
     * Generate dialplan XML for ring groups.
     *
     * Each enabled ring group contributes an extension that bridges
     * to all assigned destinations using the configured strategy
     * (simultaneous, sequential, or enterprise). Extensions are
     * loaded eagerly via the relationship to avoid N+1 queries.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $groups = RingGroup::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with(['extensions' => function ($q) {
                $q->withoutGlobalScope('tenant');
            }, 'extensions.extension' => function ($q) {
                $q->withoutGlobalScope('tenant');
            }])
            ->orderBy('name')
            ->get();

        if ($groups->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($groups as $group) {
            $safeName = htmlspecialchars($group->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeStrategy = htmlspecialchars($group->strategy ?? 'simultaneous', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $timeout = $group->ring_timeout > 0 ? $group->ring_timeout : 30;

            $xml .= "      <extension name=\"ring_group_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";

            // Build bridge string from extension numbers (FreeSWITCH user/ lookups)
            if ($group->extensions->isNotEmpty()) {
                $destinations = [];

                foreach ($group->extensions as $ringExt) {
                    // Resolve the actual extension number via the extension relationship.
                    // Use sofia_contact to resolve the registered SIP contact for each extension.
                    $extNumber = $ringExt->extension?->extension_number
                        ?? $ringExt->extension_uuid;
                    $safeExt = htmlspecialchars((string) $extNumber, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $destinations[] = "\${sofia_contact({$safeExt}@\${domain_name})}";
                }

                $bridgeString = implode(',', $destinations);
                $bridgeData = htmlspecialchars($bridgeString, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if ($safeStrategy === 'enterprise') {
                    // Enterprise: ring all at once, answer first
                    $xml .= "          <action application=\"set\" data=\"ringback=\${us-ring}\"/>\n";
                    $xml .= "          <action application=\"bridge\" data=\"{origination_caller_id_name=\${caller_id_name},origination_caller_id_number=\${caller_id_number},ignore_early_media=true}{$bridgeData}\"/>\n";
                } elseif ($safeStrategy === 'sequential') {
                    // Sequential: ring one at a time
                    $xml .= "          <action application=\"set\" data=\"call_timeout={$timeout}\"/>\n";
                    $xml .= "          <action application=\"bridge\" data=\"{$bridgeData}\"/>\n";
                } else {
                    // Simultaneous (default): ring all at once
                    $xml .= "          <action application=\"set\" data=\"call_timeout={$timeout}\"/>\n";
                    $xml .= "          <action application=\"bridge\" data=\"{$bridgeData}\"/>\n";
                }
            } else {
                // No destinations — hangup with cause
                $xml .= "          <action application=\"hangup\" data=\"NO_ANSWER\"/>\n";
            }

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }
}
