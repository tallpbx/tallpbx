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
     * Handle an incoming login request for the unified panel.
     *
     * Validates credentials and attempts authentication against the admin guard
     * first (system administrators), then falls back to the web guard (tenant users).
     * Ensures the matching account is enabled, regenerates the session, and redirects
     * to the unified panel dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        // 1. Attempt admin authentication (system administrators)
        if (Auth::guard('admin')->attempt($credentials, $remember)) {
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

        // 2. Attempt web authentication (tenant users)
        if (Auth::guard('web')->attempt($credentials, $remember)) {
            /** @var \App\Models\User $user */
            $user = Auth::guard('web')->user();

            if (! $user->enabled) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => __('auth.disabled'),
                ]);
            }

            $request->session()->regenerate();

            // When the user logs in through a tenant-specific domain, resolve
            // the tenant from the login host and set it as the active context.
            $loginDomain = $request->getHost();

            if ($loginDomain !== '' && filter_var($loginDomain, FILTER_VALIDATE_IP) === false) {
                $resolver = app(\App\Services\TenantIdentityResolver::class);
                $identity = $resolver->resolveFromDomain($loginDomain);

                if ($identity !== null) {
                    $belongs = $user->tenants()
                        ->where('tenant_id', $identity->tenantId)
                        ->exists();

                    if ($belongs) {
                        $tenant = \App\Models\Tenant::find($identity->tenantId);

                        if ($tenant !== null) {
                            app(\App\Services\TenantContext::class)->switch($tenant);
                        }
                    }
                }
            }

            return redirect()->intended(route('panel.dashboard'));
        }

        // 3. Neither admin nor tenant user matched the credentials
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
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
