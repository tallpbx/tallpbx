<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ImpersonationException;
use App\Models\Admin;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cross-cutting service for admin user impersonation.
 *
 * System admins can temporarily assume the identity of any tenant user
 * to troubleshoot tenant-specific issues. All impersonation actions
 * are permission-gated and fully audited.
 *
 * Session keys used during impersonation:
 * - impersonation.original_admin_id — the admin who initiated
 * - impersonation.target_user_id — the user being impersonated
 * - impersonation.started_at — timestamp of impersonation start
 */
class ImpersonationService implements ImpersonationServiceInterface
{
    private const SESSION_ORIGINAL_ADMIN_ID = 'impersonation.original_admin_id';

    private const SESSION_TARGET_USER_ID = 'impersonation.target_user_id';

    private const SESSION_STARTED_AT = 'impersonation.started_at';

    /**
     * Begin impersonating the given user as the current admin.
     *
     * Validates that the current admin has the impersonate permission,
     * is not already in an impersonation session, and the target is
     * a valid tenant user. Stores admin identity in session and logs
     * in as the target user on the web guard.
     */
    public function impersonate(User $user): void
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin) {
            throw ImpersonationException::unauthorized();
        }

        // Prevent chaining: cannot impersonate while already impersonating
        if ($this->isImpersonating()) {
            throw ImpersonationException::alreadyImpersonating();
        }

        // Permission gate
        if (! $admin->hasPermission('admin.impersonate')) {
            throw ImpersonationException::unauthorized();
        }

        DB::transaction(function () use ($admin, $user) {
            // Store admin identity in session before switching guards
            session()->put(self::SESSION_ORIGINAL_ADMIN_ID, $admin->id);
            session()->put(self::SESSION_TARGET_USER_ID, $user->id);
            session()->put(self::SESSION_STARTED_AT, now());

            // Log the impersonation start with snapshot information
            ImpersonationLog::create([
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'action' => 'start',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            Log::info(sprintf(
                'Admin [%s] (ID: %d) started impersonating user [%s] (ID: %d) from IP [%s].',
                $admin->name,
                $admin->id,
                $user->email,
                $user->id,
                request()->ip() ?? 'unknown'
            ), [
                'event' => 'impersonation.start',
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'ip' => request()->ip(),
            ]);

            // Switch to the web guard as the target user
            Auth::guard('web')->login($user);
        });
    }

    /**
     * End the current impersonation session and restore the original admin.
     *
     * Logs the stop action, clears impersonation session data, and
     * re-authenticates as the original admin — unless the admin was
     * disabled or deleted while the impersonation was active, in which
     * case the stop is logged (when possible) but re-authentication is
     * blocked and the impersonation session is still ended cleanly.
     */
    public function stop(): void
    {
        if (! $this->isImpersonating()) {
            throw ImpersonationException::notImpersonating();
        }

        $adminId = session()->get(self::SESSION_ORIGINAL_ADMIN_ID);
        $userId = session()->get(self::SESSION_TARGET_USER_ID);

        // Look the original admin up without failing: the account may have
        // been removed while the impersonation was active, and that must
        // not crash the stop flow.
        $admin = Admin::find($adminId);
        $user = User::find($userId);
        $userEmail = $user?->email;

        if ($admin instanceof Admin) {
            DB::transaction(function () use ($admin, $userId, $userEmail) {
                // Log the impersonation stop with snapshot information
                ImpersonationLog::create([
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'user_id' => $userId,
                    'user_email' => $userEmail,
                    'action' => 'stop',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                Log::info(sprintf(
                    'Admin [%s] (ID: %d) stopped impersonating user [%s] (ID: %d) from IP [%s].',
                    $admin->name,
                    $admin->id,
                    $userEmail ?? ('User #'.$userId),
                    $userId,
                    request()->ip() ?? 'unknown'
                ), [
                    'event' => 'impersonation.stop',
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'user_id' => $userId,
                    'user_email' => $userEmail,
                    'ip' => request()->ip(),
                ]);
            });
        } else {
            // The admin no longer exists: audit rows cascade away with the
            // admin, so a stop log against the missing id would violate the
            // foreign key. Record the event in the application log instead.
            Log::warning('Impersonation stopped after the original admin was deleted.', [
                'event' => 'impersonation.stop',
                'admin_id' => $adminId,
                'user_id' => $userId,
                'user_email' => $userEmail,
                'ip' => request()->ip(),
            ]);
        }

        // Clear impersonation session data
        session()->forget([
            self::SESSION_ORIGINAL_ADMIN_ID,
            self::SESSION_TARGET_USER_ID,
            self::SESSION_STARTED_AT,
        ]);

        // Logout the web guard (impersonated user)
        Auth::guard('web')->logout();

        if (! $admin instanceof Admin) {
            throw ImpersonationException::adminMissing();
        }

        // Prevent re-authentication of a disabled admin
        if (! $admin->enabled) {
            throw ImpersonationException::adminDisabled();
        }

        // Re-authenticate as the original admin
        Auth::guard('admin')->login($admin);
    }

    /**
     * Check whether the current session is an impersonation.
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_ORIGINAL_ADMIN_ID);
    }

    /**
     * Get the original admin who initiated the current impersonation, or null.
     */
    public function getOriginalAdmin(): ?Admin
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        return Admin::find(session()->get(self::SESSION_ORIGINAL_ADMIN_ID));
    }
}
