<?php

declare(strict_types=1);

namespace Modules\Dialplans\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Dialplans\Models\Dialplan;

/**
 * Service implementation for managing dialplans with cascade deletion
 * of detail rules and XML config generation.
 */
class DialplanService implements DialplanServiceInterface
{
    /**
     * Create a new dialplan with its detail rules.
     */
    public function create(array $data): Dialplan
    {
        return DB::transaction(function () use ($data) {
            $details = $data['details'] ?? [];
            unset($data['details']);

            $dialplan = Dialplan::create($data);

            foreach ($details as $detail) {
                $dialplan->details()->create($detail);
            }

            return $dialplan->load('details');
        });
    }

    /**
     * Update an existing dialplan.
     */
    public function update(Dialplan $dialplan, array $data): Dialplan
    {
        $dialplan->update($data);

        return $dialplan->fresh();
    }

    /**
     * Delete a dialplan and its detail rules.
     */
    public function delete(Dialplan $dialplan): void
    {
        DB::transaction(function () use ($dialplan) {
            $dialplan->details()->delete();
            $dialplan->delete();
        });
    }

    /**
     * Get all dialplans for a tenant with their details.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Dialplan::withoutGlobalScope('tenant')->with('details')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Generate an XML configuration string for FreeSWITCH dialplan.
     */
    public function generateConfig(Dialplan $dialplan): string
    {
        $xml = '<context name="'.e($dialplan->context).'">'."\n";
        $xml .= '  <extension name="'.e($dialplan->name).'">'."\n";

        foreach ($dialplan->details as $detail) {
            $xml .= '    <'.e($detail->tag);
            if ($detail->field) {
                $xml .= ' field="'.e($detail->field).'"';
            }
            if ($detail->expression) {
                $xml .= ' expression="'.e($detail->expression).'"';
            }
            $xml .= '>'."\n";

            if ($detail->action) {
                $xml .= '      <action application="'.e($detail->action).'"';
                if ($detail->data) {
                    $xml .= ' data="'.e($detail->data).'"';
                }
                $xml .= '/>'."\n";
            }

            $xml .= '    </'.e($detail->tag).'>'."\n";
        }

        $xml .= '  </extension>'."\n";
        $xml .= '</context>';

        return $xml;
    }
}
