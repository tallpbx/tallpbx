<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Illuminate\Support\Facades\DB;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Exceptions\CertificateDeploymentException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;

/**
 * Service for deploying certificates to Nginx Web and FreeSWITCH Telephony.
 */
class CertificateDeploymentService implements CertificateDeploymentServiceInterface
{
    /**
     * Create a new certificate deployment service instance.
     */
    public function __construct(
        private readonly CertificateExecutorInterface $executor,
    ) {}

    /**
     * Deploy a certificate to Nginx Web (HTTPS :443 and Reverb WebSockets).
     */
    public function deployWeb(Certificate $certificate, ?int $adminId = null): bool
    {
        $result = $this->executor->deployWeb($certificate->storage_identifier);

        if (! $result['success']) {
            $errorMsg = $result['error'] !== '' ? $result['error'] : 'Failed to deploy certificate to Nginx Web.';

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => $adminId,
                'action' => 'deployed_web',
                'status' => 'error',
                'message' => $errorMsg,
                'details' => ['output' => $result['output'], 'exit_code' => $result['exit_code']],
            ]);

            throw new CertificateDeploymentException($errorMsg);
        }

        DB::transaction(function () use ($certificate, $adminId, $result): void {
            Certificate::query()
                ->where('is_default_web', true)
                ->where('id', '!=', $certificate->id)
                ->update(['is_default_web' => false]);

            $certificate->update(['is_default_web' => true]);

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => $adminId,
                'action' => 'deployed_web',
                'status' => 'success',
                'message' => "Successfully deployed certificate '{$certificate->name}' to Nginx Web server.",
                'details' => ['storage_identifier' => $certificate->storage_identifier, 'output' => $result['output']],
            ]);
        });

        return true;
    }

    /**
     * Deploy a certificate to FreeSWITCH Telephony (SIP TLS :5061 and WebRTC WSS :7443).
     */
    public function deployTelephony(Certificate $certificate, ?int $adminId = null): bool
    {
        $result = $this->executor->deployTelephony($certificate->storage_identifier);

        if (! $result['success']) {
            $errorMsg = $result['error'] !== '' ? $result['error'] : 'Failed to deploy certificate to FreeSWITCH Telephony.';

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => $adminId,
                'action' => 'deployed_telephony',
                'status' => 'error',
                'message' => $errorMsg,
                'details' => ['output' => $result['output'], 'exit_code' => $result['exit_code']],
            ]);

            throw new CertificateDeploymentException($errorMsg);
        }

        DB::transaction(function () use ($certificate, $adminId, $result): void {
            Certificate::query()
                ->where('is_default_telephony', true)
                ->where('id', '!=', $certificate->id)
                ->update(['is_default_telephony' => false]);

            $certificate->update(['is_default_telephony' => true]);

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => $adminId,
                'action' => 'deployed_telephony',
                'status' => 'success',
                'message' => "Successfully deployed certificate '{$certificate->name}' to FreeSWITCH Telephony.",
                'details' => ['storage_identifier' => $certificate->storage_identifier, 'output' => $result['output']],
            ]);
        });

        return true;
    }

    /**
     * Deploy a certificate to both Web and Telephony services.
     */
    public function deployAll(Certificate $certificate, ?int $adminId = null): bool
    {
        $this->deployWeb($certificate, $adminId);
        $this->deployTelephony($certificate, $adminId);

        return true;
    }

    /**
     * Query runtime deployment status across Web and Telephony.
     */
    public function getDeploymentStatus(): array
    {
        $rawStatus = $this->executor->status();

        $activeWebCert = Certificate::query()->activeWeb()->first();
        $activeTelephonyCert = Certificate::query()->activeTelephony()->first();

        return [
            'active_web' => $rawStatus['active_web'],
            'telephony_active' => $rawStatus['telephony_active'],
            'active_web_certificate' => $activeWebCert,
            'active_telephony_certificate' => $activeTelephonyCert,
        ];
    }
}
