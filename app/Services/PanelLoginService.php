<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Unified panel login logic for system administrators and tenant users.
 *
 * Tries the admin guard first, then the web (tenant user) guard, and owns
 * the shared login rules in one place: the enabled-flag check, session
 * regeneration, and the tenant-domain context switch that keeps the single
 * login form correct for both account types.
 */
class PanelLoginService
{
    /**
     * Create the service with its tenant-resolution dependencies.
     */
    public function __construct(
        private readonly TenantIdentityResolver $identityResolver,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Authenticate a unified panel login attempt.
     *
     * Returns the guard name ("admin" or "web") that matched so callers can
     * branch on the account type, and throws a validation error for
     * disabled accounts and unmatched credentials.
     *
     * @param  array<string, string>  $credentials  Validated email and password
     */
    public function authenticate(Request $request, array $credentials, bool $remember): string
    {
        // 1. Attempt admin authentication (system administrators)
        if (Auth::guard('admin')->attempt($credentials, $remember)) {
            /** @var Admin $admin */
            $admin = Auth::guard('admin')->user();

            $this->finalizeLogin($request, 'admin', $admin);

            return 'admin';
        }

        // 2. Attempt web authentication (tenant users)
        if (Auth::guard('web')->attempt($credentials, $remember)) {
            /** @var User $user */
            $user = Auth::guard('web')->user();

            $this->finalizeLogin($request, 'web', $user);
            $this->applyTenantLoginContext($request, $user);

            return 'web';
        }

        // 3. Neither admin nor tenant user matched the credentials
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Reject disabled accounts and regenerate the session for valid ones.
     *
     * A disabled account is logged out and its session invalidated before
     * the standard "account disabled" validation error is surfaced.
     */
    private function finalizeLogin(Request $request, string $guard, Admin|User $account): void
    {
        if (! $account->enabled) {
            Auth::guard($guard)->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('auth.disabled'),
            ]);
        }

        $request->session()->regenerate();
    }

    /**
     * Switch to the tenant that matches the login host when the user
     * belongs to it. Logins on plain IP addresses or unknown hosts keep
     * the default tenant resolution untouched.
     */
    private function applyTenantLoginContext(Request $request, User $user): void
    {
        // When the user logs in through a tenant-specific domain, resolve
        // the tenant from the login host and set it as the active context.
        $loginDomain = $request->getHost();

        if ($loginDomain === '' || filter_var($loginDomain, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $identity = $this->identityResolver->resolveFromDomain($loginDomain);

        if ($identity === null) {
            return;
        }

        $belongs = $user->tenants()
            ->where('tenant_id', $identity->tenantId)
            ->exists();

        if (! $belongs) {
            return;
        }

        $tenant = Tenant::find($identity->tenantId);

        if ($tenant !== null) {
            $this->tenantContext->switch($tenant);
        }
    }
}
