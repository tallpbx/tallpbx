<?php

declare(strict_types=1);

namespace App\Observers;

use App\Support\RoutingCacheVersion;
use Illuminate\Database\Eloquent\Model;
use Modules\CallCenters\Models\Queue;
use Modules\Dialplans\Models\Dialplan;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\RingGroups\Models\RingGroup;

/**
 * Observer that bumps the tenant's RoutingCacheVersion whenever any
 * dialplan-contributing or routing model changes.
 *
 * This guarantees immediate zero-delay invalidation of:
 * - Full dialplan XML caches
 * - Standard dialplan XML caches
 * - Dialplan contributor fragment caches
 *
 * Eliminates stale routing across all PBX feature modules without manual
 * cache flushing or waiting for TTL expiry.
 */
class RoutingCacheObserver
{
    /**
     * Handle the Model "saved" event (created or updated).
     */
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    /**
     * Handle the Model "restored" event.
     */
    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    /**
     * Invalidate routing cache for the model's tenant.
     */
    protected function invalidate(Model $model): void
    {
        $tenantId = $this->resolveTenantId($model);

        if ($tenantId !== null && $tenantId > 0) {
            RoutingCacheVersion::bump($tenantId);
        }

        // If the tenant_id itself changed on update, invalidate the original tenant as well
        if ($model->isDirty('tenant_id') && $model->getOriginal('tenant_id')) {
            $originalTenantId = (int) $model->getOriginal('tenant_id');
            if ($originalTenantId > 0 && $originalTenantId !== $tenantId) {
                RoutingCacheVersion::bump($originalTenantId);
            }
        }
    }

    /**
     * Resolve the tenant ID from the model or its parent relationship.
     */
    protected function resolveTenantId(Model $model): ?int
    {
        if (isset($model->tenant_id) && $model->tenant_id !== null) {
            return (int) $model->tenant_id;
        }

        // DialplanDetail -> Dialplan
        if (isset($model->dialplan_id) && class_exists(Dialplan::class)) {
            $dialplan = Dialplan::withoutGlobalScope('tenant')->find($model->dialplan_id);
            if ($dialplan !== null) {
                return (int) $dialplan->tenant_id;
            }
        }

        // RingGroupExtension -> RingGroup
        if (isset($model->ring_group_id) && class_exists(RingGroup::class)) {
            $ringGroup = RingGroup::withoutGlobalScope('tenant')->find($model->ring_group_id);
            if ($ringGroup !== null) {
                return (int) $ringGroup->tenant_id;
            }
        }

        // IvrMenuOption -> IvrMenu
        if (isset($model->ivr_menu_id) && class_exists(IvrMenu::class)) {
            $ivrMenu = IvrMenu::withoutGlobalScope('tenant')->find($model->ivr_menu_id);
            if ($ivrMenu !== null) {
                return (int) $ivrMenu->tenant_id;
            }
        }

        // Tier -> Queue
        if (isset($model->queue_id) && class_exists(Queue::class)) {
            $queue = Queue::withoutGlobalScope('tenant')->find($model->queue_id);
            if ($queue !== null) {
                return (int) $queue->tenant_id;
            }
        }

        return null;
    }
}
