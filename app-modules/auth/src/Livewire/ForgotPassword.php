<?php

declare(strict_types=1);

namespace Modules\Auth\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

/**
 * Livewire component for requesting a password reset link.
 *
 * Collects the user's email and sends a password reset
 * notification via Laravel's password broker.
 */
class ForgotPassword extends Component
{
    public string $email = '';

    /**
     * Send a password reset link to the given email address.
     */
    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $status = Password::sendResetLink(['email' => $this->email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->dispatch('reset-link-sent');
        } else {
            $this->addError('email', __($status));
        }
    }

    public function render(): View
    {
        return view('auth::forgot-password');
    }
}
