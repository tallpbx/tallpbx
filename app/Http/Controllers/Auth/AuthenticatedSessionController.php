<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Controller;
use App\Services\PanelLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Handles tenant user authentication (login/logout).
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Show the unified panel login form.
     */
    public function create(): View
    {
        return view('admin::auth.admin-login');
    }

    /**
     * Handle an incoming login request via the unified authentication handler.
     */
    public function store(Request $request, AuthController $authController, PanelLoginService $panelLogin): RedirectResponse
    {
        return $authController->store($request, $panelLogin);
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
