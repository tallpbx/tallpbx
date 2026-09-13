<?php

declare(strict_types=1);

namespace Modules\ClickToCall\Livewire;

use App\Services\FreeSwitchServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin form for initiating click-to-call phone calls.
 *
 * Provides a simple form where an admin enters a phone number
 * and their extension number. When submitted, it uses the
 * FreeSWITCH ESL bgapi to originate a call that bridges the
 * extension with the target phone number.
 */
#[Layout('layouts.app')]
class ClickToCallForm extends Component
{
    use HasOperationalFeedback;

    /** The phone number to call. */
    public string $phoneNumber = '';

    /** The admin's extension to bridge from. */
    public string $extension = '';

    /** Whether a call is currently being initiated. */
    public bool $calling = false;

    /** The FreeSWITCH ESL service instance. */
    private FreeSwitchServiceInterface $fs;

    /**
     * Inject the FreeSWITCH service via Livewire's dependency injection.
     */
    public function boot(FreeSwitchServiceInterface $fs): void
    {
        $this->fs = $fs;
    }

    /**
     * Validate input and originate the call through FreeSWITCH ESL.
     *
     * Uses bgapi to send an originate command that bridges the
     * user's extension to the target phone number. Dispatches
     * a notification on success or error.
     */
    public function initiateCall(): void
    {
        $this->validate();
        $this->calling = true;

        if (! $this->fs->isConnected()) {
            $this->showWarning(__('admin.fs_not_connected'));
            $this->calling = false;

            return;
        }

        // Build the FreeSWITCH originate command to bridge the
        // extension with the target number.
        $command = sprintf(
            'originate {origination_caller_id_number=%s}user/%s %s bridge:user/%s inline',
            $this->extension,
            $this->extension,
            $this->phoneNumber,
            $this->extension
        );

        try {
            $this->fs->bgapi($command);
        } catch (\RuntimeException) {
            $this->showError('The call could not be started. Check FreeSWITCH and try again.');
            $this->calling = false;

            return;
        }

        $this->showSuccess(__('admin.click_to_call_initiated'));
        $this->calling = false;
    }

    /**
     * Validation rules for the click-to-call form.
     */
    public function rules(): array
    {
        return [
            'phoneNumber' => 'required|string|max:255',
            'extension' => 'required|string|max:255',
        ];
    }

    /**
     * Render the click-to-call form view.
     */
    public function render(): View
    {
        return view('click-to-call::click-to-call-form');
    }
}
