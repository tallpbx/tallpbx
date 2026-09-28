<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InitialAdminProvisioner;
use App\Services\PanelLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Handles admin authentication (login/logout).
 */
class AuthController extends Controller
{
    /**
     * Show the admin login form or route pending browser setup to its form.
     */
    public function create(InitialAdminProvisioner $initialAdminProvisioner): View|RedirectResponse
    {
        // Browser setup can only create the first administrator through its
        // dedicated form. Sending visitors there prevents a fresh server from
        // presenting a login form for an account that cannot exist yet.
        if ($initialAdminProvisioner->browserSetupMode() !== null) {
            return redirect()->route('panel.initial-admin.setup');
        }

        return view('admin::auth.admin-login');
    }

    /**
     * Handle an incoming login request for the unified panel.
     *
     * Validates the submitted credentials and delegates the guard-specific
     * authentication work (admin guard first, then the web guard for tenant
     * users) to the panel login service, which also enforces the enabled
     * flag, session regeneration, and tenant-domain context switching.
     */
    public function store(Request $request, PanelLoginService $panelLogin): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $panelLogin->authenticate($request, $credentials, $request->boolean('remember'));

        return redirect()->intended(route('panel.dashboard'));
    }

    /**
     * Handle admin logout.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
