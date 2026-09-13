<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SwitchTenantRequest;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * Changes the active tenant for an authenticated tenant user.
 */
class TenantSwitchController extends Controller
{
    /**
     * Persist an authorized tenant selection and reload the panel.
     */
    public function __invoke(SwitchTenantRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenantContext->switch($request->authorizedTenant());

        return redirect()->route('panel.dashboard');
    }
}
