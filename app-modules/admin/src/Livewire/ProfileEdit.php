<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Admin;
use App\Models\User;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component for managing the authenticated user's profile and credentials.
 *
 * Supports both Admin and User authenticatable models across guards.
 * Provides separate update actions for personal details and password changes.
 */
#[Layout('layouts.app')]
class ProfileEdit extends Component
{
    use HasOperationalFeedback;

    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $profileSuccess = null;

    public ?string $passwordSuccess = null;

    /**
     * Mount the component with the currently authenticated user details.
     */
    public function mount(): void
    {
        $user = $this->currentUser();

        if ($user !== null) {
            $this->name = $user->name ?? '';
            $this->email = $user->email ?? '';
        }
    }

    /**
     * Update the authenticated user's name and email.
     */
    public function updateProfile(): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $table = $user instanceof Admin ? 'admins' : 'users';

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique($table, 'email')->ignore($user->id),
            ],
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'email' => trim($validated['email']),
        ]);

        $this->profileSuccess = __('admin.profile_updated');
        $this->passwordSuccess = null;
    }

    /**
     * Update the authenticated user's password after validating current password.
     */
    public function updatePassword(): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed', 'different:currentPassword'],
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('auth.password'));

            return;
        }

        $user->update([
            'password' => $this->newPassword,
        ]);

        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->passwordSuccess = __('admin.password_updated');
        $this->profileSuccess = null;
    }

    /**
     * Get the currently authenticated authenticatable model.
     */
    private function currentUser(): Admin|User|null
    {
        return Auth::guard('admin')->user() ?? Auth::guard('web')->user();
    }

    /**
     * Render the profile edit view.
     */
    public function render(): View
    {
        return view('admin::profile-edit');
    }
}
