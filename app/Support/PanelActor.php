<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Unified resolver for the currently active unified-panel actor.
 *
 * Provides impersonation-safe actor resolution and authorization checks across
 * panel middleware, Livewire action guards, edit/list components, and control feedback.
 */
class PanelActor
{
    /**
     * Resolve the currently active unified-panel actor.
     *
     * Impersonated sessions resolve the tenant user (web guard)
     * rather than the underlying admin who initiated the impersonation.
     */
    public static function resolve(): Admin|User|null
    {
        if (self::isImpersonating()) {
            $user = Auth::guard('web')->user();

            return $user instanceof User ? $user : null;
        }

        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        return ($actor instanceof Admin || $actor instanceof User) ? $actor : null;
    }

    /**
     * Determine whether the current session is an active impersonation session.
     */
    public static function isImpersonating(): bool
    {
        return session()->has('impersonation.original_admin_id') && Auth::guard('web')->check();
    }

    /**
     * Determine whether the resolved actor is a real administrator (not impersonating).
     */
    public static function isAdmin(): bool
    {
        $actor = self::resolve();

        return $actor instanceof Admin && ! self::isImpersonating();
    }

    /**
     * Determine whether the resolved actor is a Super Administrator.
     *
     * Returns true only when isAdmin() is true and the actor belongs to the
     * system-level 'Super Administrators' group (tenant_id === null).
     */
    public static function isSuperAdmin(): bool
    {
        if (! self::isAdmin()) {
            return false;
        }

        $actor = self::resolve();

        if (! $actor instanceof Admin) {
            return false;
        }

        if (method_exists($actor, 'isSuperAdmin')) {
            return (bool) $actor->isSuperAdmin();
        }

        if ($actor->relationLoaded('groups')) {
            return $actor->groups->contains(
                fn ($group) => $group->name === 'Super Administrators' && $group->tenant_id === null
            );
        }

        return $actor->groups()
            ->where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->exists();
    }

    /**
     * Check if the active actor has the specified permission ability.
     */
    public static function can(string $ability): bool
    {
        $actor = self::resolve();

        return (bool) ($actor?->hasPermission($ability) ?? false);
    }
}
