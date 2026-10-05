<?php

declare(strict_types=1);

namespace Modules\Certificates\Console\Commands;

use Illuminate\Console\Command;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Models\Certificate;

/**
 * Artisan command to display the TLS certificate inventory, expiration countdown,
 * and active Web and Telephony service bindings.
 */
class CertificateStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificates:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display TLS certificates inventory, expiration countdown, and active service deployments';

    /**
     * Execute the console command.
     *
     * @param CertificateDeploymentServiceInterface $deploymentService The service deployer
     * @param CertificateExecutorInterface $executor The bounded root helper executor
     * @return int Exit code
     */
    public function handle(
        CertificateDeploymentServiceInterface $deploymentService,
        CertificateExecutorInterface $executor,
    ): int {
        $this->info('=== TallPBX Certificate Manager Status ===');

        $certificates = Certificate::orderBy('valid_to', 'asc')->get();

        if ($certificates->isEmpty()) {
            $this->warn('No certificates registered in the database.');
        } else {
            $rows = $certificates->map(function (Certificate $cert): array {
                $days = $cert->days_until_expiration;

                if ($days === null) {
                    $status = 'UNKNOWN';
                    $daysText = '-';
                } elseif ($days <= 0) {
                    $status = 'EXPIRED';
                    $daysText = "EXPIRED ({$days}d)";
                } elseif ($days <= 30) {
                    $status = "EXPIRING ({$days}d)";
                    $daysText = (string) $days;
                } else {
                    $status = 'VALID';
                    $daysText = (string) $days;
                }

                return [
                    (string) $cert->id,
                    $cert->name,
                    $cert->common_name,
                    $cert->type,
                    $cert->valid_to ? $cert->valid_to->toDateTimeString() : 'N/A',
                    $daysText,
                    $cert->is_default_web ? 'ACTIVE' : '-',
                    $cert->is_default_telephony ? 'ACTIVE' : '-',
                    $cert->auto_renew ? 'YES' : 'NO',
                    $status,
                ];
            })->all();

            $this->table(
                ['ID', 'Name', 'Common Name', 'Type', 'Valid Until', 'Days Left', 'Web', 'Telephony', 'Auto Renew', 'Status'],
                $rows
            );
        }

        $this->newLine();
        $this->info('=== Active Service Deployments ===');

        $statusData = $deploymentService->getDeploymentStatus();
        $activeWeb = Certificate::where('is_default_web', true)->first();
        $activeTelephony = Certificate::where('is_default_telephony', true)->first();

        $this->table(
            ['Service', 'Database Binding', 'Filesystem Status'],
            [
                [
                    'Nginx Web (:443)',
                    $activeWeb ? "{$activeWeb->name} ({$activeWeb->common_name})" : 'None configured',
                    ($statusData['web_active'] ?? false) ? 'ACTIVE (/etc/tallpbx/certs/active/)' : 'INACTIVE',
                ],
                [
                    'FreeSWITCH SIP/WSS (:5061/:7443)',
                    $activeTelephony ? "{$activeTelephony->name} ({$activeTelephony->common_name})" : 'None configured',
                    ($statusData['telephony_active'] ?? false) ? 'ACTIVE (/etc/freeswitch/tls/)' : 'INACTIVE',
                ],
            ]
        );

        $this->newLine();
        $this->info('=== Bounded Helper Status ===');
        $version = trim($executor->version());
        $execStatus = $executor->status();

        $this->table(
            ['Property', 'Status'],
            [
                ['Helper Version', $version !== '' ? $version : 'Not available'],
                ['Active Web Identifier', $execStatus['active_web'] !== '' ? $execStatus['active_web'] : 'None'],
                ['Telephony Cert Active', ($execStatus['telephony_active'] ?? false) ? 'YES' : 'NO'],
            ]
        );

        return self::SUCCESS;
    }
}
