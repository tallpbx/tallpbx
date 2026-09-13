<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\InitialAdminProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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
     * Handle an incoming admin login request.
     *
     * Validates credentials, checks the admin account is enabled,
     * regenerates the session, and redirects to the dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        if (! $admin->enabled) {
            Auth::guard('admin')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('auth.disabled'),
            ]);
        }

        $request->session()->regenerate();

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
