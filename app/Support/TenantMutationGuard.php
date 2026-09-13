<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ImpersonationServiceInterface;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Fail-closed guard that stops tenant users from mutating records that
 * belong to another tenant.
 *
 * The tenant global scope filters queries, but many panel components bypass
 * it with withoutGlobalScope('tenant') so admins can work across tenants.
 * This guard closes that gap for authenticated tenant (web guard) sessions:
 * any create, update, or delete touching a record outside the active tenant
 * context is rejected with HTTP 403.
 *
 * Admin sessions and non-web flows (console commands, queue workers, ESL
 * listeners, the XML handler) are not affected — they either operate
 * system-wide or manage the tenant identity themselves.
 */
class TenantMutationGuard
{
    /**
     * Abort with HTTP 403 when the current web-guard user tries to mutate a
     * record that belongs to a different tenant than the active context.
     *
     * @param  Model  $model  The record about to be created, updated, or deleted.
     */
    public static function assertCanMutate(Model $model): void
    {
        // Console commands, queue workers, ESL listeners, and XML handler
        // requests have no web-guard user; those flows set the tenant
        // context explicitly and are allowed through.
        if (! Auth::guard('web')->check()) {
            return;
        }

        // A real administrator (not impersonating) works system-wide and is
        // allowed to touch records of any tenant.
        if (Auth::guard('admin')->check()
            && ! app(ImpersonationServiceInterface::class)->isImpersonating()) {
            return;
        }

        // Impersonating admins are treated as tenant users, matching the
        // isAdminGuard() semantics used by the panel base components.
        $activeTenantId = app(TenantManager::class)->getTenantId();
        $recordTenantId = $model->tenant_id;

        $matches = $activeTenantId !== null
            && $recordTenantId !== null
            && (string) $recordTenantId === (string) $activeTenantId;

        if (! $matches) {
            // Fail closed: never allow a tenant user to write outside the
            // active tenant context, including tenant reassignment attempts.
            abort(403, 'Cross-tenant access denied.');
        }
    }
}
