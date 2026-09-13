<?php

declare(strict_types=1);

namespace Modules\Admin\Http\Controllers;

use App\Exceptions\ImpersonationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controller handling admin impersonation start and stop actions.
 *
 * All actions are permission-gated through the ImpersonationService
 * and require authentication via the admin guard.
 */
class ImpersonationController extends Controller
{
    /**
     * Begin impersonating a tenant user.
     *
     * Validates the admin has the impersonate permission, stores
     * the admin's identity in session, and swaps to the web guard
     * as the target user. Redirects to the user's dashboard.
     */
    public function impersonate(User $user, ImpersonationServiceInterface $service, Request $request): RedirectResponse
    {
        if ($request->session()->has('impersonation.original_admin_id')) {
            abort(403, 'Already impersonating.');
        }

        try {
            $service->impersonate($user);
        } catch (ImpersonationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('panel.dashboard');
    }

    /**
     * Stop the current impersonation and restore the original admin session.
     *
     * Clears impersonation session keys, logs the stop action,
     * and logs the original admin back in on the admin guard.
     */
    public function stop(ImpersonationServiceInterface $service): RedirectResponse
    {
        try {
            $service->stop();
        } catch (ImpersonationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('panel.dashboard');
    }
}
