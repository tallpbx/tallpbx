<?php

declare(strict_types=1);

namespace Modules\EmailConnector\Services;

/**
 * Contract for managing email connector configuration.
 */
interface EmailConnectorServiceInterface
{
    /**
     * Get all SMTP settings as a key-value array.
     *
     * @return array<string, string|null>
     */
    public function getSettings(): array;

    /**
     * Update SMTP settings from a validated array.
     *
     * The password is encrypted before storage. Other values
     * are stored as plain text.
     *
     * @param  array<string, string|null>  $data
     */
    public function updateSettings(array $data): void;

    /**
     * Send a test email to verify SMTP configuration works.
     *
     * @param  string  $to  Recipient email address
     *
     * @throws \RuntimeException When SMTP delivery fails
     */
    public function sendTestEmail(string $to): void;

    /**
     * Check whether SMTP is configured and ready to send.
     */
    public function isConfigured(): bool;

    /**
     * Build the OAuth 2.0 authorization URL for the configured provider.
     *
     * Includes client_id, redirect_uri, scope, state (CSRF), and
     * response_type=code. The state is stored in cache for callback
     * verification.
     *
     * @throws \RuntimeException When OAuth provider is not configured
     */
    public function getOAuthAuthorizationUrl(): string;

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * POSTs to the provider's token endpoint with code, client_id,
     * client_secret, and redirect_uri. Stores refresh_token, access_token,
     * and expires_at encrypted in the database.
     *
     * @param  string  $code  Authorization code from the provider callback
     * @param  string  $state  CSRF state parameter to validate
     *
     * @throws \RuntimeException When state mismatch or token exchange fails
     */
    public function handleOAuthCallback(string $code, string $state): void;

    /**
     * Check whether OAuth 2.0 is configured and authorized.
     *
     * Returns true when smtp_auth_type is 'oauth', a provider is
     * selected, and a refresh_token is stored.
     */
    public function hasOAuth(): bool;

    /**
     * Revoke all OAuth tokens and clear provider configuration.
     *
     * Deletes refresh_token, access_token, token_expires, and
     * resets the authorization state. Does not delete Client ID/Secret
     * so the admin can re-authorize without re-entering them.
     */
    public function revokeOAuth(): void;

    /**
     * Get default host and port for a built-in OAuth provider.
     *
     * Returns null when the provider is 'custom' or unknown.
     *
     * @return array{host: string, port: string}|null
     */
    public function getOAuthProviderDefaults(string $provider): ?array;
}
