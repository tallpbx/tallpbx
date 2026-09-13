<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantIdentityResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Handles tenant user authentication (login/logout).
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Show the portal login form.
     */
    public function create(): View
    {
        return view('tenant::auth.tenant-login');
    }

    /**
     * Handle an incoming login request.
     *
     * Validates credentials, checks the user account is enabled,
     * regenerates the session, and redirects to the dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
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
        // Users who belong to a single tenant always get that tenant; users
        // with multiple tenants get the domain-matched tenant if they belong
        // to it, otherwise they keep their default tenant context.
        $loginDomain = $request->getHost();

        if ($loginDomain !== '' && filter_var($loginDomain, FILTER_VALIDATE_IP) === false) {
            $resolver = app(TenantIdentityResolver::class);
            $identity = $resolver->resolveFromDomain($loginDomain);

            if ($identity !== null) {
                // Verify the user belongs to the resolved tenant.
                $belongs = $user->tenants()
                    ->where('tenant_id', $identity->tenantId)
                    ->exists();

                if ($belongs) {
                    $tenant = Tenant::find($identity->tenantId);

                    if ($tenant !== null) {
                        app(TenantContext::class)->switch($tenant);
                    }
                }
            }
        }

        return redirect()->intended(route('panel.dashboard'));
    }

    /**
     * Handle logout.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
