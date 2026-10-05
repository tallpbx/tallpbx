<?php

declare(strict_types=1);

namespace Modules\Certificates\Console\Commands;

use Illuminate\Console\Command;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;

/**
 * Artisan command to deploy an existing TLS certificate to Nginx Web,
 * FreeSWITCH Telephony, or both services.
 */
class CertificateDeployCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificates:deploy
                            {id : The certificate ID, name, or Common Name}
                            {--service=all : Target service to deploy to: web, telephony, or all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy a certificate to Nginx Web, FreeSWITCH Telephony, or both';

    /**
     * Execute the console command.
     *
     * @param CertificateDeploymentServiceInterface $deploymentService The service deployer
     * @return int Exit code
     */
    public function handle(CertificateDeploymentServiceInterface $deploymentService): int
    {
        $identifier = (string) $this->argument('id');
        $service = strtolower((string) $this->option('service'));

        if (! in_array($service, ['web', 'telephony', 'all'], true)) {
            $this->error("Invalid service '{$service}'. Supported options are: web, telephony, all.");

            return self::FAILURE;
        }

        $certificate = is_numeric($identifier)
            ? Certificate::find((int) $identifier)
            : Certificate::where('common_name', $identifier)
                ->orWhere('name', $identifier)
                ->first();

        if ($certificate === null) {
            $this->error("Certificate '{$identifier}' not found in the database.");

            return self::FAILURE;
        }

        $this->info("Deploying certificate '{$certificate->name}' ({$certificate->common_name}) to {$service}...");

        try {
            match ($service) {
                'web' => $deploymentService->deployToWeb($certificate),
                'telephony' => $deploymentService->deployToTelephony($certificate),
                'all' => $deploymentService->deployToAll($certificate),
            };

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => null,
                'action' => 'deployed',
                'status' => 'success',
                'message' => "Certificate deployed to {$service} via CLI",
                'details' => ['service' => $service],
            ]);

            $this->info("Successfully deployed '{$certificate->name}' to {$service}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Deployment failed: {$e->getMessage()}");

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => null,
                'action' => 'deploy_failed',
                'status' => 'failed',
                'message' => "Deployment to {$service} failed via CLI: {$e->getMessage()}",
                'details' => ['service' => $service, 'error' => $e->getMessage()],
            ]);

            return self::FAILURE;
        }
    }
}
