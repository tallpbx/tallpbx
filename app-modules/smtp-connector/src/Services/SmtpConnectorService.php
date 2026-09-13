<?php

declare(strict_types=1);

namespace Modules\SmtpConnector\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Manages SMTP connector configuration stored in the database-backed
 * Setting model with encrypted password storage.
 *
 * SMTP credentials are stored as system-scoped settings (tenant_id = null)
 * so they are shared across all tenants. The password is encrypted with
 * Laravel's Crypt facade before storage and decrypted on read.
 *
 * Supports two authentication types:
 *  - password: Traditional username/password SMTP auth (default)
 *  - oauth:    OAuth 2.0 authorization code flow with XOAUTH2 SASL.
 *              Built-in provider configs for Google and Microsoft;
 *              custom provider option for any OAuth2-compliant SMTP host.
 */
class SmtpConnectorService implements SmtpConnectorServiceInterface
{
    /**
     * Setting keys managed by this service.
     */
    private const KEYS = [
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
        'smtp_auth_type',
        'smtp_oauth_provider',
        'smtp_oauth_client_id',
        'smtp_oauth_client_secret',
        'smtp_oauth_refresh_token',
        'smtp_oauth_access_token',
        'smtp_oauth_token_expires',
        'smtp_oauth_auth_endpoint',
        'smtp_oauth_token_endpoint',
        'smtp_oauth_scopes',
    ];

    /**
     * Setting keys whose values are stored encrypted.
     */
    private const ENCRYPTED_KEYS = [
        'smtp_password',
        'smtp_oauth_client_secret',
        'smtp_oauth_refresh_token',
        'smtp_oauth_access_token',
    ];

    /**
     * OAuth 2.0 provider configurations.
     *
     * Each provider maps to its authorization endpoint, token endpoint,
     * SMTP scope(s), and a suggested default SMTP host:port.
     */
    private const OAUTH_PROVIDERS = [
        'google' => [
            'name' => 'Google',
            'auth_endpoint' => 'https://accounts.google.com/o/oauth2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://mail.google.com/',
            'default_host' => 'smtp.gmail.com',
            'default_port' => '587',
        ],
        'microsoft' => [
            'name' => 'Microsoft',
            'auth_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
            'default_host' => 'smtp.office365.com',
            'default_port' => '587',
        ],
    ];

    /**
     * Get all SMTP settings, decrypting encrypted values.
     *
     * @return array<string, string|null>
     */
    public function getSettings(): array
    {
        $settings = [];

        foreach (self::KEYS as $key) {
            $value = $this->get($key);

            if ($value !== null && in_array($key, self::ENCRYPTED_KEYS, true)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Throwable) {
                    // If decryption fails (rotated key, corrupted data),
                    // return empty so the admin can re-enter the value.
                    $value = '';
                }
            }

            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * Update SMTP settings, encrypting the password before storage.
     */
    public function updateSettings(array $data): void
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($value === null || $value === '') {
                $this->delete($key);

                continue;
            }

            if (in_array($key, self::ENCRYPTED_KEYS, true)) {
                $value = Crypt::encryptString($value);
            }

            $this->set($key, $value);
        }
    }

    /**
     * Send a test email to verify SMTP configuration works.
     *
     * For OAuth-based configurations, builds an EsmtpTransport with
     * XOAuth2Authenticator using the current access token (refreshed
     * automatically if expired).
     */
    public function sendTestEmail(string $to): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SMTP is not configured. Save settings before sending a test email.');
        }

        try {
            $settings = $this->getSettings();

            $mailConfig = [
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $settings['smtp_host'],
                'mail.mailers.smtp.port' => (int) $settings['smtp_port'],
                'mail.mailers.smtp.encryption' => $settings['smtp_encryption'] ?: null,
                'mail.from.address' => $settings['smtp_from_address'] ?: 'noreply@tallpbx.org',
                'mail.from.name' => $settings['smtp_from_name'] ?: 'TallPBX',
            ];

            if ($this->hasOAuth()) {
                // OAuth path: build a custom EsmtpTransport with XOAUTH2.
                // EsmtpTransport uses STARTTLS when tls=true (port 587),
                // or plain SMTP when tls=false. For implicit SSL (port 465),
                // a different transport (SmtpsTransport) would be needed;
                // the existing password path has the same limitation.
                $accessToken = $this->getAccessToken();
                $transport = new EsmtpTransport(
                    host: $settings['smtp_host'],
                    port: (int) $settings['smtp_port'],
                    tls: $settings['smtp_encryption'] === 'tls' ? true : false,
                    authenticators: [new XOAuth2Authenticator],
                );
                $transport->setUsername($settings['smtp_username'] ?? '');
                $transport->setPassword($accessToken);

                $mailer = new Mailer($transport);

                $email = (new Email)
                    ->from(new Address($settings['smtp_from_address'] ?: 'noreply@tallpbx.org', $settings['smtp_from_name'] ?: 'TallPBX'))
                    ->to($to)
                    ->subject('TallPBX — SMTP Test Email')
                    ->text(
                        "This is a test email from your TallPBX server.\n\n"
                        ."SMTP Host: {$settings['smtp_host']}\n"
                        ."Auth: OAuth 2.0\n"
                        .'Sent at: '.now()->toDateTimeString()."\n\n"
                        .'If you received this, your SMTP configuration is working correctly.'
                    );

                $mailer->send($email);
            } else {
                // Password path: override config and use Laravel's Mail facade.
                $mailConfig['mail.mailers.smtp.username'] = $settings['smtp_username'];
                $mailConfig['mail.mailers.smtp.password'] = $settings['smtp_password'];

                config($mailConfig);

                Mail::raw(
                    "This is a test email from your TallPBX server.\n\n"
                    ."SMTP Host: {$settings['smtp_host']}\n"
                    .'Sent at: '.now()->toDateTimeString()."\n\n"
                    .'If you received this, your SMTP configuration is working correctly.',
                    fn ($message) => $message
                        ->to($to)
                        ->subject('TallPBX — SMTP Test Email'),
                );
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "SMTP test failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Check whether SMTP is minimally configured (host + port set).
     *
     * Reads directly from the database each time. The query is trivial
     * and this is not a hot path, so caching would add stale-data risk
     * without meaningful benefit.
     */
    public function isConfigured(): bool
    {
        $host = $this->get('smtp_host');
        $port = $this->get('smtp_port');

        return $host !== null && $host !== '' && $port !== null && $port !== '';
    }

    // ─── OAuth 2.0 Methods ────────────────────────────────────────────────

    /**
     * Build the OAuth 2.0 authorization URL for the selected provider.
     *
     * Generates a random CSRF state, stores it in the cache for callback
     * verification, and returns the full authorization endpoint URL with
     * query parameters: client_id, redirect_uri, scope, response_type=code,
     * access_type=offline, prompt=consent, and state.
     *
     * @throws RuntimeException When auth_type is not 'oauth' or
     *                          client_id/client_secret are not configured
     */
    public function getOAuthAuthorizationUrl(): string
    {
        $settings = $this->getSettings();

        if (($settings['smtp_auth_type'] ?? '') !== 'oauth') {
            throw new RuntimeException('OAuth is not configured. Set auth_type to "oauth" first.');
        }

        $provider = $settings['smtp_oauth_provider'] ?? '';
        $clientId = $settings['smtp_oauth_client_id'] ?? '';

        if ($clientId === '' || $settings['smtp_oauth_client_secret'] === '') {
            throw new RuntimeException('OAuth is not configured. Enter Client ID and Client Secret first.');
        }

        $config = $this->getProviderConfig($provider);

        $state = Str::random(40);
        cache()->put('oauth_state_'.$state, $state, 600);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('panel.smtp-connector.oauth-callback'),
            'response_type' => 'code',
            'scope' => $config['scope'],
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return $config['auth_endpoint'].'?'.$query;
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * Validates the CSRF state against the cached value, then POSTs to
     * the provider's token endpoint with the authorization code, client
     * credentials, and redirect URI. Stores the resulting refresh_token,
     * access_token, and expires_at encrypted in the database.
     *
     * @throws RuntimeException When state mismatch, provider unknown,
     *                          or token exchange HTTP request fails
     */
    public function handleOAuthCallback(string $code, string $state): void
    {
        // Validate CSRF state.
        $cachedState = cache()->pull('oauth_state_'.$state);

        if ($cachedState === null || $cachedState !== $state) {
            throw new RuntimeException('OAuth state mismatch. The authorization request may have been tampered with.');
        }

        $settings = $this->getSettings();
        $provider = $settings['smtp_oauth_provider'] ?? '';
        $config = $this->getProviderConfig($provider);

        // Exchange the authorization code for tokens.
        $response = Http::asForm()->post($config['token_endpoint'], [
            'code' => $code,
            'client_id' => $settings['smtp_oauth_client_id'],
            'client_secret' => $settings['smtp_oauth_client_secret'],
            'redirect_uri' => route('panel.smtp-connector.oauth-callback'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            $body = $response->body();
            throw new RuntimeException(
                "OAuth token exchange failed: HTTP {$response->status()} — {$body}"
            );
        }

        $data = $response->json();

        // Store tokens encrypted.
        $this->updateSettings([
            'smtp_oauth_access_token' => $data['access_token'],
            'smtp_oauth_token_expires' => (string) (now()->addSeconds($data['expires_in'] ?? 3599)->timestamp),
        ]);

        // Some providers (Google) only return a refresh_token on the first
        // authorization. Only overwrite if a new one was returned.
        if (! empty($data['refresh_token'])) {
            $this->updateSettings([
                'smtp_oauth_refresh_token' => $data['refresh_token'],
            ]);
        }
    }

    /**
     * Check whether OAuth is configured and authorized.
     *
     * Returns true when smtp_auth_type is 'oauth' and a refresh_token
     * is stored (meaning the consent flow has completed).
     */
    public function hasOAuth(): bool
    {
        $authType = $this->get('smtp_auth_type');
        $refreshToken = $this->get('smtp_oauth_refresh_token');

        return $authType === 'oauth' && $refreshToken !== null && $refreshToken !== '';
    }

    /**
     * Revoke OAuth tokens while preserving client credentials.
     *
     * Clears refresh_token, access_token, and token_expires so the admin
     * can re-authorize without re-entering Client ID and Client Secret.
     * Also clears the PKCE/state cache keys.
     */
    public function revokeOAuth(): void
    {
        $this->delete('smtp_oauth_refresh_token');
        $this->delete('smtp_oauth_access_token');
        $this->delete('smtp_oauth_token_expires');
    }

    /**
     * Get default host and port for a built-in OAuth provider.
     *
     * Returns null when the provider is 'custom' or unknown so the
     * Livewire component can decide whether to auto-fill fields.
     *
     * @return array{host: string, port: string}|null
     */
    public function getOAuthProviderDefaults(string $provider): ?array
    {
        $config = self::OAUTH_PROVIDERS[$provider] ?? null;

        if ($config === null) {
            return null;
        }

        return [
            'host' => $config['default_host'],
            'port' => $config['default_port'],
        ];
    }

    /**
     * Return the OAuth 2.0 provider configuration for built-in and custom
     * providers.
     *
     * Built-in providers (google, microsoft) have pre-configured endpoints
     * and scopes. A 'custom' provider reads its endpoints and scopes from
     * the database settings set by the admin.
     *
     * @return array{name: string, auth_endpoint: string, token_endpoint: string, scope: string}
     *
     * @throws RuntimeException When the provider slug is unknown
     */
    private function getProviderConfig(string $provider): array
    {
        if (isset(self::OAUTH_PROVIDERS[$provider])) {
            return self::OAUTH_PROVIDERS[$provider];
        }

        // Custom provider: endpoints and scopes are stored in settings.
        $authEndpoint = $this->get('smtp_oauth_auth_endpoint');
        $tokenEndpoint = $this->get('smtp_oauth_token_endpoint');
        $scope = $this->get('smtp_oauth_scopes');

        if ($authEndpoint === null || $tokenEndpoint === null || $scope === null) {
            throw new RuntimeException(
                'Unknown OAuth provider "'.$provider.'" and custom endpoint settings are incomplete.'
            );
        }

        return [
            'name' => 'Custom',
            'auth_endpoint' => $authEndpoint,
            'token_endpoint' => $tokenEndpoint,
            'scope' => $scope,
        ];
    }

    /**
     * Return a valid OAuth access token, refreshing it if expired.
     *
     * If the stored access token has expired (or no access token exists),
     * this method uses the refresh_token to obtain a new access token
     * from the provider's token endpoint and updates the database.
     *
     * @throws RuntimeException When no refresh_token is stored or
     *                          the refresh request fails
     */
    private function getAccessToken(): string
    {
        $settings = $this->getSettings();
        $expiresAt = $settings['smtp_oauth_token_expires'] ?? '';
        $accessToken = $settings['smtp_oauth_access_token'] ?? '';

        // Return cached access token if it's still valid (with 60s buffer).
        if ($accessToken !== '' && $expiresAt !== '' && (int) $expiresAt > now()->addMinute()->timestamp) {
            return $accessToken;
        }

        // Refresh the token.
        $refreshToken = $settings['smtp_oauth_refresh_token'] ?? '';

        if ($refreshToken === '') {
            throw new RuntimeException('Cannot refresh OAuth token: no refresh_token stored. Re-authorize the provider.');
        }

        $provider = $settings['smtp_oauth_provider'] ?? '';
        $config = $this->getProviderConfig($provider);

        $response = Http::asForm()->post($config['token_endpoint'], [
            'client_id' => $settings['smtp_oauth_client_id'],
            'client_secret' => $settings['smtp_oauth_client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $body = $response->body();
            throw new RuntimeException(
                "OAuth token refresh failed: HTTP {$response->status()} — {$body}"
            );
        }

        $data = $response->json();
        $newAccessToken = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3599;

        // Persist the new access token.
        $this->updateSettings([
            'smtp_oauth_access_token' => $newAccessToken,
            'smtp_oauth_token_expires' => (string) (now()->addSeconds($expiresIn)->timestamp),
        ]);

        // Some providers rotate the refresh_token on each use.
        if (! empty($data['refresh_token'])) {
            $this->updateSettings([
                'smtp_oauth_refresh_token' => $data['refresh_token'],
            ]);
        }

        return $newAccessToken;
    }

    // ─── Private Persistence Helpers ───────────────────────────────────────

    /**
     * Read a system-scoped setting value from the database.
     */
    private function get(string $key): ?string
    {
        $setting = Setting::system()->where('key', $key)->first();

        return $setting?->value;
    }

    /**
     * Persist a system-scoped setting (upsert).
     */
    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key, 'tenant_id' => null],
            ['value' => $value],
        );
    }

    /**
     * Delete a system-scoped setting.
     */
    private function delete(string $key): void
    {
        Setting::system()->where('key', $key)->delete();
    }
}
