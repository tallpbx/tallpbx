<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A FreeSWITCH call detail record owned by a tenant.
 */
class Cdr extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * Memoized tenant-relative direction.
     *
     * Livewire serializes public properties as model identifiers, so this
     * value is never part of the payload and is recomputed after rehydration.
     */
    private ?string $tenantDirection = null;

    protected $table = 'cdrs';

    protected $fillable = [
        'tenant_id',
        'call_uuid',
        'b_leg_uuid',
        'leg',
        'caller_id',
        'caller_id_name',
        'destination',
        'duration',
        'billsec',
        'hangup_cause',
        'direction',
        'record_path',
        'start_stamp',
        'answer_stamp',
        'end_stamp',
        'json_data',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'billsec' => 'integer',
            'start_stamp' => 'datetime',
            'answer_stamp' => 'datetime',
            'end_stamp' => 'datetime',
            'json_data' => 'array',
        ];
    }

    /**
     * Direction relative to this record's own tenant.
     *
     * The stored `direction` column is FreeSWITCH's channel direction, which
     * cannot express a call that leaves one tenant and arrives at another.
     * This view is derived from the paired leg: internal when both legs share
     * a tenant, outbound for the caller and inbound for the callee otherwise.
     */
    public function tenantDirection(): string
    {
        return $this->tenantDirection ??= $this->resolveTenantDirection();
    }

    /**
     * Batch-resolve peer tenant IDs for a collection/chunk of CDRs so list
     * renders and CSV exports execute one peer lookup instead of N+1 queries.
     *
     * @param  iterable<int, self>  $records
     */
    public static function preloadTenantDirections(iterable $records): void
    {
        $records = collect($records);
        $peerUuids = $records->pluck('b_leg_uuid')->filter()->unique()->values()->all();
        $peerTenants = $peerUuids === []
            ? []
            : static::withoutGlobalScope('tenant')
                ->whereIn('call_uuid', $peerUuids)
                ->pluck('tenant_id', 'call_uuid')
                ->all();

        foreach ($records as $record) {
            $peerTenantId = $record->b_leg_uuid !== null ? ($peerTenants[$record->b_leg_uuid] ?? null) : null;
            $record->tenantDirection = $record->directionFromPeerTenant($peerTenantId !== null ? (int) $peerTenantId : null);
        }
    }

    /**
     * Resolve the direction for one record with a single peer lookup.
     */
    private function resolveTenantDirection(): string
    {
        $peerTenantId = $this->b_leg_uuid === null ? null : static::withoutGlobalScope('tenant')
            ->where('call_uuid', $this->b_leg_uuid)
            ->value('tenant_id');

        return $this->directionFromPeerTenant($peerTenantId !== null ? (int) $peerTenantId : null);
    }

    /**
     * Map the peer's tenant onto a tenant-relative direction.
     */
    private function directionFromPeerTenant(?int $peerTenantId): string
    {
        if ($peerTenantId !== null && $peerTenantId === (int) $this->tenant_id) {
            return 'internal';
        }

        if ($peerTenantId !== null) {
            // The A-leg created the bridge, so its tenant placed the call; the
            // B-leg received it. This is what makes an A-to-B call outbound in
            // A and inbound in B, which a per-leg direction column cannot
            // express.
            return $this->leg === 'a' ? 'outbound' : 'inbound';
        }

        // No bridge partner (gateway call, voicemail deposit, failed call).
        return in_array($this->direction, ['inbound', 'outbound'], true)
            ? $this->direction
            : ($this->leg === 'a' ? 'outbound' : 'inbound');
    }
}
