<?php

declare(strict_types=1);

namespace Modules\SmtpConnector\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Livewire\Component;
use Modules\SmtpConnector\Services\SmtpConnectorServiceInterface;

/**
 * Livewire component for configuring the SMTP connector.
 *
 * Provides a form where superadmins can set SMTP host, port,
 * encryption, credentials, and from-address. Includes Gmail and
 * generic provider preset buttons plus a test-email action.
 *
 * Supports two authentication types:
 *  - password: Traditional SMTP username/password auth.
 *  - oauth:    OAuth 2.0 via provider (Google, Microsoft, Custom).
 *              Client ID / Secret fields replace the password field,
 *              and an "Authorize" button initiates the consent flow.
 */
class SmtpConnectorEdit extends Component
{
    use HasOperationalFeedback;

    public string $smtp_host = '';

    public string $smtp_port = '587';

    public string $smtp_encryption = 'tls';

    public string $smtp_username = '';

    public string $smtp_password = '';

    public string $smtp_from_address = '';

    public string $smtp_from_name = '';

    public string $testEmailAddress = '';

    public bool $isConfigured = false;

    public ?string $testResult = null;

    public bool $testSuccess = false;

    public string $preset = '';

    // ─── OAuth 2.0 properties ────────────────────────────────────────────
    public string $smtp_auth_type = 'password';

    public string $smtp_oauth_provider = 'google';

    public string $smtp_oauth_client_id = '';

    public string $smtp_oauth_client_secret = '';

    public string $smtp_oauth_auth_endpoint = '';

    public string $smtp_oauth_token_endpoint = '';

    public string $smtp_oauth_scopes = '';

    public bool $oauthAuthorized = false;

    /** Whether the OAuth disconnect confirmation dialog is open. */
    public bool $confirmingOAuthDisconnect = false;

    /** Expected disconnection failure shown inside the confirmation dialog. */
    public ?string $oauthDisconnectError = null;

    private SmtpConnectorServiceInterface $service;

    /**
     * Inject the SMTP connector service via Livewire's dependency injection.
     */
    public function boot(SmtpConnectorServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load current SMTP settings from the database.
     *
     * If the request contains an OAuth callback code, the authorization
     * code is exchanged for tokens before loading settings so the UI
     * immediately reflects the authorized state.
     */
    public function mount(): void
    {
        // Handle OAuth callback: if the URL contains ?code= and ?state=,
        // exchange them for tokens before rendering the form.
        $code = request()->query('code');
        $state = request()->query('state');

        if ($code !== null && $state !== null) {
            try {
                $this->service->handleOAuthCallback($code, $state);
                $this->showSuccess('OAuth authorization successful. SMTP is ready to send.');
            } catch (\RuntimeException $exception) {
                $this->showError('OAuth authorization failed. Review the provider settings and try again.');
            }
        }

        $settings = $this->service->getSettings();

        $this->smtp_host = $settings['smtp_host'] ?? '';
        $this->smtp_port = $settings['smtp_port'] ?? '587';
        $this->smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
        $this->smtp_username = $settings['smtp_username'] ?? '';
        $this->smtp_password = $settings['smtp_password'] ?? '';
        $this->smtp_from_address = $settings['smtp_from_address'] ?? '';
        $this->smtp_from_name = $settings['smtp_from_name'] ?? '';
        $this->isConfigured = $this->service->isConfigured();

        // Load OAuth settings.
        $this->smtp_auth_type = $settings['smtp_auth_type'] ?? 'password';
        $this->smtp_oauth_provider = $settings['smtp_oauth_provider'] ?? 'google';
        $this->smtp_oauth_client_id = $settings['smtp_oauth_client_id'] ?? '';
        $this->smtp_oauth_client_secret = $settings['smtp_oauth_client_secret'] ?? '';
        $this->smtp_oauth_auth_endpoint = $settings['smtp_oauth_auth_endpoint'] ?? '';
        $this->smtp_oauth_token_endpoint = $settings['smtp_oauth_token_endpoint'] ?? '';
        $this->smtp_oauth_scopes = $settings['smtp_oauth_scopes'] ?? '';
        $this->oauthAuthorized = $this->service->hasOAuth();
    }

    /**
     * Save SMTP settings to the database.
     *
     * For password auth, saves username and password. For OAuth,
     * saves provider, client credentials, and custom endpoint fields.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption ?: null,
            'smtp_username' => $this->smtp_username ?: null,
            'smtp_from_address' => $this->smtp_from_address ?: null,
            'smtp_from_name' => $this->smtp_from_name ?: null,
            'smtp_auth_type' => $this->smtp_auth_type,
        ];

        if ($this->smtp_auth_type === 'oauth') {
            $data['smtp_password'] = null;
            $data['smtp_oauth_provider'] = $this->smtp_oauth_provider;
            $data['smtp_oauth_client_id'] = $this->smtp_oauth_client_id;
            $data['smtp_oauth_client_secret'] = $this->smtp_oauth_client_secret;
            $data['smtp_oauth_auth_endpoint'] = $this->smtp_oauth_auth_endpoint ?: null;
            $data['smtp_oauth_token_endpoint'] = $this->smtp_oauth_token_endpoint ?: null;
            $data['smtp_oauth_scopes'] = $this->smtp_oauth_scopes ?: null;
        } else {
            $data['smtp_password'] = $this->smtp_password ?: null;
        }

        $this->service->updateSettings($data);

        $this->isConfigured = $this->service->isConfigured();

        $this->showSuccess('SMTP settings saved.');
    }

    /**
     * Apply the Gmail preset with Gmail-specific defaults.
     */
    public function applyGmailPreset(): void
    {
        $this->preset = 'gmail';
        $this->smtp_host = 'smtp.gmail.com';
        $this->smtp_port = '587';
        $this->smtp_encryption = 'tls';
    }

    /**
     * Apply the generic provider preset with neutral defaults.
     *
     * Clears Gmail-specific values so the form shows generic
     * placeholders suitable for any SMTP provider.
     */
    public function applyGenericPreset(): void
    {
        $this->preset = 'generic';
        $this->smtp_host = '';
        $this->smtp_port = '587';
        $this->smtp_encryption = 'tls';
    }

    /**
     * Send a test email to verify configuration.
     */
    public function sendTest(): void
    {
        $this->validate([
            'testEmailAddress' => ['required', 'email'],
        ]);

        try {
            $this->service->sendTestEmail($this->testEmailAddress);
            $this->testResult = "Test email sent to {$this->testEmailAddress}. Check your inbox.";
            $this->testSuccess = true;
        } catch (\RuntimeException $exception) {
            $this->testResult = 'Test email could not be sent. Check the SMTP settings and try again.';
            $this->testSuccess = false;
        }
    }

    /**
     * Validation rules for the SMTP form.
     *
     * When auth_type is 'oauth', provider, client_id, and client_secret
     * are required. When 'password', username and password are required.
     */
    public function rules(): array
    {
        $rules = [
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
            'smtp_auth_type' => ['required', 'in:password,oauth'],
        ];

        if ($this->smtp_auth_type === 'oauth') {
            $rules['smtp_oauth_provider'] = ['required', 'string', 'in:google,microsoft,custom'];
            $rules['smtp_oauth_client_id'] = ['required', 'string', 'max:255'];
            $rules['smtp_oauth_client_secret'] = ['required', 'string', 'max:1024'];

            if ($this->smtp_oauth_provider === 'custom') {
                $rules['smtp_oauth_auth_endpoint'] = ['required', 'url', 'max:1024'];
                $rules['smtp_oauth_token_endpoint'] = ['required', 'url', 'max:1024'];
                $rules['smtp_oauth_scopes'] = ['required', 'string', 'max:1024'];
            }
        } else {
            $rules['smtp_password'] = ['nullable', 'string', 'max:1024'];
        }

        return $rules;
    }

    // ─── OAuth 2.0 Actions ───────────────────────────────────────────────

    /**
     * Initiate the OAuth 2.0 authorization code flow.
     *
     * Saves current settings first (so client credentials are persisted),
     * then redirects the browser to the provider's authorization endpoint.
     */
    public function startOAuth(): void
    {
        // Always save first so the service has the Client ID / Secret.
        $this->save();

        try {
            $url = $this->service->getOAuthAuthorizationUrl();
        } catch (\RuntimeException $exception) {
            $this->showError('OAuth authorization could not be started. Save valid provider settings and try again.');

            return;
        }

        $this->redirect($url);
    }

    /** Open the shared confirmation modal for disconnecting OAuth. */
    public function confirmOAuthDisconnect(): void
    {
        $this->confirmingOAuthDisconnect = true;
        $this->oauthDisconnectError = null;
    }

    /** Close the OAuth disconnection confirmation without disconnecting. */
    public function cancelOAuthDisconnect(): void
    {
        $this->confirmingOAuthDisconnect = false;
        $this->oauthDisconnectError = null;
    }

    /**
     * Revoke the current OAuth authorization.
     *
     * Clears refresh_token, access_token, and token_expires but preserves
     * Client ID and Secret so re-authorization doesn't require re-entry.
     * Keeps the confirmation dialog open when the provider is unavailable.
     */
    public function disconnectOAuth(): void
    {
        try {
            $this->service->revokeOAuth();
        } catch (\RuntimeException) {
            // Expected operational failure: keep the dialog open with a safe message.
            $this->oauthDisconnectError = 'OAuth could not be disconnected. Please try again.';

            return;
        }

        $this->cancelOAuthDisconnect();
        $this->oauthAuthorized = false;
        $this->showSuccess('OAuth connection disconnected.');
    }

    /**
     * Auto-fill SMTP host and port when the OAuth provider changes.
     *
     * Built-in providers (Google, Microsoft) have known defaults.
     * Custom providers leave the host/port unchanged.
     */
    public function updatedSmtpOauthProvider(string $value): void
    {
        $defaults = $this->service->getOAuthProviderDefaults($value);

        if ($defaults !== null) {
            $this->smtp_host = $defaults['host'];
            $this->smtp_port = $defaults['port'];
        }
    }

    // ─── Render ───────────────────────────────────────────────────────────

    /**
     * Render the SMTP connector configuration view.
     */
    public function render()
    {
        return view('smtp-connector::smtp-connector-edit')
            ->layout('layouts.app');
    }
}
