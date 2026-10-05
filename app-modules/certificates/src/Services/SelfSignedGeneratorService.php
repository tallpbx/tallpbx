<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Illuminate\Support\Str;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Contracts\SelfSignedGeneratorServiceInterface;
use Modules\Certificates\Exceptions\CertificateException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;

/**
 * Service for generating cryptographic self-signed certificates for lab and PBX environments.
 */
class SelfSignedGeneratorService implements SelfSignedGeneratorServiceInterface
{
    /**
     * Create a new self-signed certificate generator instance.
     */
    public function __construct(
        private readonly CertificateExecutorInterface $executor,
        private readonly CertificateParserServiceInterface $parser,
        private readonly CertificateValidatorServiceInterface $validator,
        private readonly CertificateDeploymentServiceInterface $deploymentService,
    ) {}

    /**
     * Generate a self-signed certificate and register it in the inventory.
     */
    public function generate(
        string $name,
        string $commonName,
        int $days = 365,
        array $sanDomains = [],
        bool $autoDeployWeb = false,
        bool $autoDeployTelephony = false,
        ?int $adminId = null,
    ): Certificate {
        $trimmedCommonName = trim($commonName);

        if ($trimmedCommonName === '') {
            throw new CertificateException('Common Name cannot be empty.');
        }

        $storageId = 'self_' . Str::slug($trimmedCommonName, '_') . '_' . time();
        $sanCsv = implode(',', array_filter(array_map('trim', $sanDomains)));

        $result = $this->executor->generateSelfSigned($storageId, $trimmedCommonName, $days, $sanCsv);

        if (! $result['success']) {
            throw new CertificateException($result['error'] !== '' ? $result['error'] : 'Failed to generate self-signed certificate.');
        }

        // Parse the generated certificate metadata
        $certPath = "/etc/tallpbx/certs/{$storageId}/fullchain.pem";
        $metadata = [
            'common_name' => $trimmedCommonName,
            'san_domains' => array_values(array_unique(array_merge([$trimmedCommonName], $sanDomains))),
            'issuer' => 'TallPBX Self-Signed',
            'valid_from' => now(),
            'valid_to' => now()->addDays($days),
            'serial_number' => null,
            'fingerprint_sha256' => null,
        ];

        if (file_exists($certPath)) {
            $parsed = $this->parser->parseFile($certPath);
            $metadata = array_merge($metadata, $parsed);
        }

        $certificate = Certificate::create([
            'name' => $name,
            'type' => Certificate::TYPE_SELF_SIGNED,
            'common_name' => $metadata['common_name'],
            'san_domains' => $metadata['san_domains'],
            'issuer' => $metadata['issuer'],
            'valid_from' => $metadata['valid_from'],
            'valid_to' => $metadata['valid_to'],
            'serial_number' => $metadata['serial_number'],
            'fingerprint_sha256' => $metadata['fingerprint_sha256'],
            'is_default_web' => false,
            'is_default_telephony' => false,
            'auto_renew' => false,
            'storage_identifier' => $storageId,
        ]);

        CertificateAuditLog::create([
            'certificate_id' => $certificate->id,
            'admin_id' => $adminId,
            'action' => 'generated',
            'status' => 'success',
            'message' => "Generated self-signed certificate '{$certificate->name}' ({$certificate->common_name}) valid for {$days} days.",
            'details' => ['storage_identifier' => $storageId, 'san_domains' => $sanDomains],
        ]);

        if ($autoDeployWeb) {
            $this->deploymentService->deployWeb($certificate, $adminId);
        }

        if ($autoDeployTelephony) {
            $this->deploymentService->deployTelephony($certificate, $adminId);
        }

        return $certificate->fresh();
    }
}
