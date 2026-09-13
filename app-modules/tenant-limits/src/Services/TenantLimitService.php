<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Services;

use App\Support\CrudService;
use App\Support\RoutingCacheVersion;
use Illuminate\Database\Eloquent\Model;
use Modules\TenantLimits\Models\TenantLimit;

/**
 * CRUD service for the TenantLimit model.
 *
 * Every write bumps the tenant's routing revision so the cached
 * dialplan rebuilds with the new limit values immediately.
 */
class TenantLimitService extends CrudService
{
    public function __construct()
    {
        $this->modelClass = TenantLimit::class;
    }

    /**
     * Create a limit record and invalidate the cached dialplan.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TenantLimit
    {
        $limit = parent::create($data);

        RoutingCacheVersion::bump((int) $data['tenant_id']);

        return $limit;
    }

    /**
     * Update a limit record and invalidate the cached dialplan.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $limit, array $data): Model
    {
        $limit = parent::update($limit, $data);

        RoutingCacheVersion::bump((int) $limit->tenant_id);

        return $limit;
    }

    /**
     * Delete a limit record and invalidate the cached dialplan.
     */
    public function delete(Model $limit): void
    {
        parent::delete($limit);

        RoutingCacheVersion::bump((int) $limit->tenant_id);
    }
}
