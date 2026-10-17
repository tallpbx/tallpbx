<?php

declare(strict_types=1);

namespace Modules\ClickToCall\Livewire;

use App\Services\DialplanContext;
use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantContext;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin form for initiating click-to-call phone calls.
 *
 * Provides a simple form where an admin enters a phone number
 * and their extension number. When submitted, it originates a call
 * bridging the extension with the target phone number through
 * FreeSwitchControlService with strict parameter sanitization.
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

    /** The FreeSWITCH control service instance. */
    private FreeSwitchControlService $control;

    /**
     * Inject services via Livewire's dependency injection.
     */
    public function boot(FreeSwitchServiceInterface $fs, FreeSwitchControlService $control): void
    {
        $this->fs = $fs;
        $this->control = $control;
    }

    /**
     * Validate input and originate the call through FreeSwitchControlService.
     *
     * Sanitizes inputs and dispatches an originate command that bridges
     * the user's extension to the target phone number.
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

        $tenant = app(TenantContext::class)->current();
        $context = $tenant !== null
            ? app(DialplanContext::class)->internal((string) $tenant->id)
            : 'default';

        $result = $this->control->originate($this->extension, $this->phoneNumber, $context);

        if (! $result['success']) {
            $this->showError($result['message'] ?: 'The call could not be started. Check FreeSWITCH and try again.');
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
        $tenant = app(TenantContext::class)->current();

        $extensionRules = [
            'required',
            'string',
            'regex:/^[0-9A-Za-z*#+_.@-]{1,32}$/',
        ];

        if ($tenant !== null) {
            $extensionRules[] = Rule::exists('extensions', 'extension_number')->where('tenant_id', $tenant->id);
        }

        return [
            'phoneNumber' => [
                'required',
                'string',
                'regex:/^\+?[0-9*#]{3,20}$/',
            ],
            'extension' => $extensionRules,
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
