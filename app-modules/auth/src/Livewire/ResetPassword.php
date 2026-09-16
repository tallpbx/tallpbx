<?php

declare(strict_types=1);

namespace Modules\Auth\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

/**
 * Livewire component for resetting a user's password.
 *
 * Accepts a password reset token and email address,
 * then allows the user to set a new password.
 */
class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Reset the user's password using the broker.
     */
    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $this->only(['email', 'password', 'password_confirmation', 'token']),
            function ($user, $password) {
                $user->forceFill([
                    'password' => $password,
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $this->redirect(route('panel.login'));
        } else {
            $this->addError('email', __($status));
        }
    }

    public function render(): View
    {
        return view('auth::reset-password');
    }
}
