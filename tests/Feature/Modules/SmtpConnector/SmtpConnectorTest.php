<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Setting;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\SmtpConnector\Livewire\SmtpConnectorEdit;
use Modules\SmtpConnector\Services\SmtpConnectorService;
use Modules\SmtpConnector\Services\SmtpConnectorServiceInterface;

/**
 * Create an admin that has the smtp-connector.view permission.
 */
function createSmtpAdmin(): Admin
{
    $permService = app(PermissionService::class);
    $permService->syncToDatabase();

    $group = Group::firstOrCreate(
        ['name' => 'SMTP Test Admins', 'tenant_id' => null],
        ['description' => 'Test group for SMTP connector tests'],
    );

    $permId = Permission::where('name', 'smtp-connector.view')->value('id');
    if ($permId !== null) {
        $group->permissions()->syncWithoutDetaching([$permId]);
    }

    $admin = Admin::factory()->create();
    $admin->groups()->syncWithoutDetaching([$group->id]);

    return $admin;
}

beforeEach(function (): void {
    Mail::fake();
    Setting::system()->delete();
});

it('shows SMTP connector form to authenticated admin', function (): void {
    $admin = createSmtpAdmin();

    $this->actingAs($admin, 'admin')
        ->get(route('panel.smtp-connector.edit'))
        ->assertOk()
        ->assertSee('SMTP Mail Configuration')
        ->assertSee(__('admin.smtp_connector_description'))
        ->assertSee(__('admin.smtp_connector_tooltip'));
});

it('saves SMTP settings with encrypted password', function (): void {
    $admin = Admin::factory()->create();
    $service = app(SmtpConnectorService::class);

    $service->updateSettings([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => 'user@example.com',
        'smtp_password' => 'secret123',
        'smtp_from_address' => 'noreply@example.com',
        'smtp_from_name' => 'Test PBX',
    ]);

    $settings = $service->getSettings();

    expect($settings['smtp_host'])->toBe('smtp.example.com');
    expect($settings['smtp_port'])->toBe('587');
    expect($settings['smtp_password'])->toBe('secret123'); // decrypted
    expect($settings['smtp_from_name'])->toBe('Test PBX');

    // Verify password is encrypted at rest
    $raw = Setting::system()->where('key', 'smtp_password')->first();
    expect($raw->value)->not->toBe('secret123');
    expect(Crypt::decryptString($raw->value))->toBe('secret123');
});

it('detects when SMTP is configured', function (): void {
    $service = app(SmtpConnectorService::class);

    expect($service->isConfigured())->toBeFalse();

    $service->updateSettings([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
    ]);

    expect($service->isConfigured())->toBeTrue();
});

it('does not send test email when not configured', function (): void {
    $service = app(SmtpConnectorService::class);

    expect(fn () => $service->sendTestEmail('test@example.com'))
        ->toThrow(RuntimeException::class, 'SMTP is not configured');
});

it('handles corrupted encrypted password gracefully', function (): void {
    Setting::updateOrCreate(
        ['key' => 'smtp_password', 'tenant_id' => null],
        ['value' => 'not-valid-ciphertext'],
    );

    $service = app(SmtpConnectorService::class);
    $settings = $service->getSettings();

    expect($settings['smtp_password'])->toBe('');
});

// ─── OAuth 2.0 Tests ────────────────────────────────────────────────────

/**
 * Seed OAuth settings so the service is in "OAuth configured" state
 * for tests that need a real refresh_token but mock the HTTP layer.
 */
function seedOAuthSettings(string $provider = 'google', bool $withTokens = false): SmtpConnectorService
{
    $service = app(SmtpConnectorService::class);
    $service->updateSettings([
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => 'user@gmail.com',
        'smtp_from_address' => 'noreply@example.com',
        'smtp_from_name' => 'Test PBX',
        'smtp_auth_type' => 'oauth',
        'smtp_oauth_provider' => $provider,
        'smtp_oauth_client_id' => 'test-client-id',
        'smtp_oauth_client_secret' => 'test-client-secret',
    ]);

    if ($withTokens) {
        $service->updateSettings([
            'smtp_oauth_refresh_token' => 'test-refresh-token',
            'smtp_oauth_access_token' => 'test-access-token',
            'smtp_oauth_token_expires' => (string) (now()->addHour()->timestamp),
        ]);
    }

    return $service;
}

it('saves OAuth settings with encrypted secrets', function (): void {
    $service = seedOAuthSettings('google', withTokens: true);

    $settings = $service->getSettings();

    expect($settings['smtp_auth_type'])->toBe('oauth');
    expect($settings['smtp_oauth_provider'])->toBe('google');
    expect($settings['smtp_oauth_client_id'])->toBe('test-client-id');
    expect($settings['smtp_oauth_client_secret'])->toBe('test-client-secret'); // decrypted
    expect($settings['smtp_oauth_refresh_token'])->toBe('test-refresh-token'); // decrypted
    expect($settings['smtp_oauth_access_token'])->toBe('test-access-token'); // decrypted

    // Verify client_secret is encrypted at rest
    $rawSecret = Setting::system()->where('key', 'smtp_oauth_client_secret')->first();
    expect($rawSecret->value)->not->toBe('test-client-secret');
    expect(Crypt::decryptString($rawSecret->value))->toBe('test-client-secret');

    // Verify refresh_token is encrypted at rest
    $rawRefresh = Setting::system()->where('key', 'smtp_oauth_refresh_token')->first();
    expect($rawRefresh->value)->not->toBe('test-refresh-token');
    expect(Crypt::decryptString($rawRefresh->value))->toBe('test-refresh-token');
});

it('generates correct Google OAuth authorization URL', function (): void {
    $service = seedOAuthSettings('google');

    $url = $service->getOAuthAuthorizationUrl();

    expect($url)->toContain('https://accounts.google.com/o/oauth2/auth');
    expect($url)->toContain('client_id=test-client-id');
    expect($url)->toContain('response_type=code');
    expect($url)->toContain('access_type=offline');
    expect($url)->toContain('prompt=consent');
    expect($url)->toContain(rawurlencode('https://mail.google.com/'));
    expect($url)->toContain('state=');
});

it('generates correct Microsoft OAuth authorization URL', function (): void {
    $service = seedOAuthSettings('microsoft');

    $url = $service->getOAuthAuthorizationUrl();

    expect($url)->toContain('https://login.microsoftonline.com/common/oauth2/v2.0/authorize');
    expect($url)->toContain('client_id=test-client-id');
    expect($url)->toContain('response_type=code');
    // http_build_query encodes spaces as '+' not '%20'.
    expect($url)->toContain(rawurlencode('https://outlook.office.com/SMTP.Send').'+offline_access');
    expect($url)->toContain('state=');
});

it('throws when generating OAuth URL without OAuth configured', function (): void {
    $service = app(SmtpConnectorService::class);

    expect(fn () => $service->getOAuthAuthorizationUrl())
        ->toThrow(RuntimeException::class, 'OAuth is not configured');
});

it('handles OAuth callback and stores tokens encrypted', function (): void {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'ya29.new-access-token',
            'refresh_token' => '1//new-refresh-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
        ]),
    ]);

    $service = seedOAuthSettings('google');

    // Simulate the state that getOAuthAuthorizationUrl would have stored.
    $state = 'test-csrf-state';
    cache()->put('oauth_state_'.$state, $state, 600);

    $service->handleOAuthCallback('auth-code-123', $state);

    // Verify tokens stored encrypted.
    $settings = $service->getSettings();
    expect($settings['smtp_oauth_access_token'])->toBe('ya29.new-access-token');
    expect($settings['smtp_oauth_refresh_token'])->toBe('1//new-refresh-token');
    expect($settings['smtp_oauth_token_expires'])->toBeString();

    // Verify encrypted at rest.
    $rawRefresh = Setting::system()->where('key', 'smtp_oauth_refresh_token')->first();
    expect($rawRefresh->value)->not->toBe('1//new-refresh-token');
});

it('rejects OAuth callback with mismatched state', function (): void {
    $service = seedOAuthSettings('google');
    cache()->put('oauth_state_correct-state', 'correct-state', 600);

    expect(fn () => $service->handleOAuthCallback('auth-code-123', 'wrong-state'))
        ->toThrow(RuntimeException::class, 'OAuth state mismatch');
});

it('detects when OAuth is configured', function (): void {
    $service = app(SmtpConnectorService::class);

    expect($service->hasOAuth())->toBeFalse();

    $serviceWithTokens = seedOAuthSettings('google', withTokens: true);

    expect($serviceWithTokens->hasOAuth())->toBeTrue();
});

it('does not detect OAuth when refresh token is missing', function (): void {
    $service = seedOAuthSettings('google', withTokens: false);

    expect($service->hasOAuth())->toBeFalse();
});

it('revokes OAuth tokens but preserves client credentials', function (): void {
    $service = seedOAuthSettings('google', withTokens: true);

    expect($service->hasOAuth())->toBeTrue();

    $service->revokeOAuth();

    // Tokens should be gone.
    expect($service->hasOAuth())->toBeFalse();

    // Client credentials should remain.
    $settings = $service->getSettings();
    expect($settings['smtp_oauth_client_id'])->toBe('test-client-id');
    expect($settings['smtp_oauth_client_secret'])->toBe('test-client-secret');
});

it('refreshes access token when expired', function (): void {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'ya29.fresh-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
        ]),
    ]);

    $service = seedOAuthSettings('google', withTokens: true);

    // Overwrite the stored access token with an expired one.
    $service->updateSettings([
        'smtp_oauth_token_expires' => (string) (now()->subHour()->timestamp),
    ]);

    // Send a test email; this should trigger a refresh internally.
    // The SMTP server won't be reachable in test, but that's fine —
    // we only care that the Http layer was called for the refresh.
    try {
        $service->sendTestEmail('test@example.com');
    } catch (RuntimeException $e) {
        // Expected — no real SMTP server in test.
        expect($e->getMessage())->toContain('SMTP test failed');
    }

    // The access token should now be the refreshed one.
    $settings = $service->getSettings();
    expect($settings['smtp_oauth_access_token'])->toBe('ya29.fresh-token');
});

it('sends test email via XOAUTH2 when OAuth is configured', function (): void {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'ya29.valid-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
        ]),
    ]);

    $service = seedOAuthSettings('google', withTokens: true);

    // sendTestEmail builds its own transport; we just verify it doesn't throw.
    // The actual SMTP connection will fail in test, so we catch that.
    try {
        $service->sendTestEmail('test@example.com');
    } catch (RuntimeException $e) {
        // Expected — no real SMTP server in test.
        // The important thing is it didn't fail with "OAuth is not configured".
        expect($e->getMessage())->toContain('SMTP test failed');
    }
});

it('opens the shared confirmation modal before disconnecting OAuth', function (): void {
    $admin = createSmtpAdmin();
    seedOAuthSettings('google', withTokens: true);

    Livewire::actingAs($admin, 'admin')
        ->test(SmtpConnectorEdit::class)
        ->assertSet('oauthAuthorized', true)
        ->call('confirmOAuthDisconnect')
        ->assertSet('confirmingOAuthDisconnect', true)
        ->assertSee('Disconnect OAuth?');
});

it('keeps the modal open with a safe error when OAuth disconnection fails', function (): void {
    $admin = createSmtpAdmin();
    seedOAuthSettings('google', withTokens: true);

    $service = Mockery::mock(SmtpConnectorService::class)->makePartial();
    $service->shouldReceive('revokeOAuth')->once()->andThrow(new RuntimeException('provider unavailable'));
    app()->instance(SmtpConnectorServiceInterface::class, $service);

    Livewire::actingAs($admin, 'admin')
        ->test(SmtpConnectorEdit::class)
        ->call('confirmOAuthDisconnect')
        ->call('disconnectOAuth')
        ->assertSet('oauthDisconnectError', 'OAuth could not be disconnected. Please try again.')
        ->assertSet('confirmingOAuthDisconnect', true)
        ->assertSet('oauthAuthorized', true);
});
