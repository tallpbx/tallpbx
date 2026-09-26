<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Provides a high-speed authentication bridge for automated and browser testing.
 *
 * This controller allows test runners to authenticate as an admin or tenant user
 * directly into the session in sub-10ms, eliminating the overhead of submitting
 * the UI login form repeatedly during browser test suites.
 *
 * For security, this endpoint is strictly available only when the application
 * environment is 'testing'. In all other environments, it aborts with a 404.
 */
final class TestAuthController extends Controller
{
    /**
     * Authenticate the requested user or admin directly into the session.
     *
     * @param string $guard The authentication guard ('admin' or 'web')
     * @param string $id The database primary key of the Admin or User
     * @return RedirectResponse Redirect to the unified panel dashboard
     */
    public function login(string $guard, string $id): RedirectResponse
    {
        // Enforce that this endpoint can only ever execute in the testing environment
        if (! app()->environment('testing')) {
            abort(404);
        }

        // Resolve the authenticatable model based on the target guard
        $user = match ($guard) {
            'admin' => Admin::query()->findOrFail((int) $id),
            'web' => User::query()->findOrFail((int) $id),
            default => abort(400, 'Invalid guard specified'),
        };

        // Log the user into the specified guard and persist session
        Auth::guard($guard)->login($user);
        request()->session()->regenerate();
        request()->session()->save();

        return redirect('/panel');
    }
}
