<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\InitialAdminProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInitialAdminRequest;
use App\Services\InitialAdminProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Shows and submits the one-time browser flow for TallPBX's first administrator.
 */
class InitialAdminSetupController extends Controller
{
    /**
     * Show the browser setup form when the selected mode permits it.
     */
    public function create(InitialAdminProvisioner $provisioner): View
    {
        $mode = $provisioner->browserSetupMode();

        abort_unless($mode !== null, 404);

        return view('admin::auth.initial-admin-setup', [
            'requiresActivationCode' => $mode === InitialAdminProvisioner::MODE_ACTIVATION_CODE,
        ]);
    }

    /**
     * Create and authenticate the first administrator after passing the selected setup gate.
     */
    public function store(StoreInitialAdminRequest $request, InitialAdminProvisioner $provisioner): RedirectResponse
    {
        $values = $request->validated();

        try {
            $admin = $provisioner->provisionFromBrowser(
                email: $values['email'],
                password: $values['password'],
                activationCode: $values['activation_code'] ?? null,
            );
        } catch (InitialAdminProvisioningException $exception) {
            throw ValidationException::withMessages([
                'activation_code' => $exception->getMessage(),
            ]);
        }

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('panel.dashboard');
    }
}
