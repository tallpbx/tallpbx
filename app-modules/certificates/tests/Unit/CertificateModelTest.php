<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use App\Models\Admin;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Models\CertificateDnsCredential;

it('creates and casts certificate attributes correctly', function (): void {
    $cert = Certificate::create([
        'name' => 'Primary Let\'s Encrypt',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx.example.com',
        'san_domains' => ['pbx.example.com', 'sip.example.com'],
        'issuer' => 'Let\'s Encrypt Authority X3',
        'valid_from' => now()->subDays(10),
        'valid_to' => now()->addDays(80),
        'serial_number' => '03FA89B1002C',
        'fingerprint_sha256' => hash('sha256', 'dummy-cert'),
        'is_default_web' => true,
        'is_default_telephony' => false,
        'challenge_type' => Certificate::CHALLENGE_HTTP,
        'auto_renew' => true,
        'is_staging' => false,
        'storage_identifier' => 'pbx_example_com_test',
    ]);

    expect($cert->name)->toBe('Primary Let\'s Encrypt')
        ->and($cert->type)->toBe(Certificate::TYPE_LETS_ENCRYPT)
        ->and($cert->san_domains)->toBe(['pbx.example.com', 'sip.example.com'])
        ->and($cert->is_default_web)->toBeTrue()
        ->and($cert->is_default_telephony)->toBeFalse()
        ->and($cert->isExpired())->toBeFalse()
        ->and($cert->isExpiringSoon(30))->toBeFalse()
        ->and($cert->statusBadgeClass())->toBe('badge-success');
});

it('detects expiring soon and expired status correctly', function (): void {
    $expiringCert = Certificate::create([
        'name' => 'Expiring Soon Cert',
        'type' => Certificate::TYPE_CUSTOM,
        'common_name' => 'expiring.example.com',
        'valid_from' => now()->subDays(60),
        'valid_to' => now()->addDays(15),
        'storage_identifier' => 'expiring_test',
    ]);

    expect($expiringCert->isExpired())->toBeFalse()
        ->and($expiringCert->isExpiringSoon(30))->toBeTrue()
        ->and($expiringCert->daysUntilExpiration())->toBe(15)
        ->and($expiringCert->statusBadgeClass())->toBe('badge-warning');

    $expiredCert = Certificate::create([
        'name' => 'Expired Cert',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'expired.example.com',
        'valid_from' => now()->subDays(370),
        'valid_to' => now()->subDays(5),
        'storage_identifier' => 'expired_test',
    ]);

    expect($expiredCert->isExpired())->toBeTrue()
        ->and($expiredCert->daysUntilExpiration())->toBe(0)
        ->and($expiredCert->statusBadgeClass())->toBe('badge-error');
});

it('encrypts DNS credentials at rest and decrypts when accessed', function (): void {
    $cred = CertificateDnsCredential::create([
        'name' => 'Cloudflare Prod Token',
        'provider' => 'cloudflare',
        'credentials' => [
            'api_token' => 'secret-cf-token-1234567890',
        ],
    ]);

    expect($cred->credentials['api_token'])->toBe('secret-cf-token-1234567890');

    // Query raw DB value to verify it is encrypted
    $raw = \DB::table('certificate_dns_credentials')->where('id', $cred->id)->value('credentials');
    expect($raw)->not->toBe('secret-cf-token-1234567890')
        ->and($raw)->not->toContain('secret-cf-token-1234567890');
});

it('records audit log entries with relationships', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    $cert = Certificate::create([
        'name' => 'Audited Cert',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'audit.example.com',
        'storage_identifier' => 'audit_test',
    ]);

    $log = CertificateAuditLog::create([
        'certificate_id' => $cert->id,
        'admin_id' => $admin->id,
        'action' => 'deployed_web',
        'status' => 'success',
        'message' => 'Deployed certificate to Nginx web server.',
        'details' => ['previous' => null, 'target' => 'audit.example.com'],
    ]);

    expect($log->certificate->id)->toBe($cert->id)
        ->and($log->admin->id)->toBe($admin->id)
        ->and($cert->auditLogs)->toHaveCount(1)
        ->and($log->details)->toBe(['previous' => null, 'target' => 'audit.example.com']);
});
