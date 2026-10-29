<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\TenantContextMissingException;
use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\TenantMutationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds automatic tenant scoping to an Eloquent model.
 *
 * When applied, this trait registers a global scope that filters all queries
 * to the currently active tenant (via TenantManager). It also auto-assigns
 * the tenant_id on new models when a tenant context is active.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait and register the tenant global scope, the creating
     * hook that auto-assigns tenant_id, and the mutation guards that stop
     * tenant users from writing records of another tenant.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantManager::class)->getTenantId();

            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where('tenant_id', $tenantId);
        });

        static::creating(function (Model $model): void {
            $tenantId = app(TenantManager::class)->getTenantId();
            if ($tenantId !== null && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            }

            if (empty($model->tenant_id)) {
                throw new TenantContextMissingException(
                    'Cannot persist tenant-scoped model ['.get_class($model).'] without an active tenant context or explicit tenant_id.'
                );
            }

            TenantMutationGuard::assertCanMutate($model);
        });

        static::saving(function (Model $model): void {
            if (! $model->exists) {
                return;
            }

            if (empty($model->tenant_id)) {
                throw new TenantContextMissingException(
                    'Cannot update tenant-scoped model ['.get_class($model).'] without an active tenant context or explicit tenant_id.'
                );
            }

            TenantMutationGuard::assertCanMutate($model);
        });

        static::deleting(function (Model $model): void {
            if (empty($model->tenant_id)) {
                throw new TenantContextMissingException(
                    'Cannot delete tenant-scoped model ['.get_class($model).'] without an active tenant context or explicit tenant_id.'
                );
            }

            TenantMutationGuard::assertCanMutate($model);
        });
    }

    /**
     * Define the Eloquent relationship to the owning Tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
