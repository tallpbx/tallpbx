<?php

declare(strict_types=1);

namespace Modules\Certificates\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Notifications\CertificateExpiringNotification;
use Modules\Certificates\Notifications\CertificateRenewalFailedNotification;

/**
 * Artisan command to automatically renew eligible Let's Encrypt certificates
 * nearing expiration and re-deploy them to active services.
 */
class CertificateRenewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificates:renew
                            {--force : Renew regardless of expiration date}
                            {--dry-run : Simulate renewal without contacting CA or issuing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically renew eligible Let\'s Encrypt certificates nearing expiration';

    /**
     * Execute the console command.
     *
     * @param LetsEncryptAcmeServiceInterface $acmeService The ACME issuance and renewal service
     * @param CertificateDeploymentServiceInterface $deploymentService The service deployer for Nginx and FreeSWITCH
     * @return int Exit code
     */
    public function handle(
        LetsEncryptAcmeServiceInterface $acmeService,
        CertificateDeploymentServiceInterface $deploymentService,
    ): int {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== TallPBX Certificate Renewal Sweep ===');

        $query = Certificate::where('type', 'lets_encrypt')
            ->where('auto_renew', true);

        if (! $force) {
            $query->where(function ($q): void {
                $q->where('valid_to', '<=', now()->addDays(30))
                    ->orWhereNull('valid_to');
            });
        }

        $certificates = $query->get();

        if ($certificates->isEmpty()) {
            $this->line('No Let\'s Encrypt certificates require renewal at this time.');
        } else {
            $this->line(sprintf('Found %d certificate(s) eligible for renewal.', $certificates->count()));
        }

        $failures = 0;

        foreach ($certificates as $cert) {
            $daysLeft = $cert->days_until_expiration;
            $daysDesc = $daysLeft !== null ? "{$daysLeft} days remaining" : 'expiration unknown';

            if ($dryRun) {
                $this->comment("[DRY RUN] Would renew: {$cert->name} ({$cert->common_name}) - {$daysDesc}");
                continue;
            }

            $this->info("Renewing certificate: {$cert->name} ({$cert->common_name})...");

            try {
                $acmeService->renewCertificate($cert);
                $cert->refresh();
                $cert->update(['last_renew_error' => null]);

                if ($cert->is_default_web && $cert->is_default_telephony) {
                    $deploymentService->deployToAll($cert);
                    $this->line('  -> Re-deployed to Web and Telephony.');
                } elseif ($cert->is_default_web) {
                    $deploymentService->deployToWeb($cert);
                    $this->line('  -> Re-deployed to Nginx Web.');
                } elseif ($cert->is_default_telephony) {
                    $deploymentService->deployToTelephony($cert);
                    $this->line('  -> Re-deployed to FreeSWITCH Telephony.');
                }

                CertificateAuditLog::create([
                    'certificate_id' => $cert->id,
                    'admin_id' => null,
                    'action' => 'renew',
                    'status' => 'success',
                    'message' => "Automated renewal succeeded for {$cert->common_name}",
                    'details' => ['valid_to' => $cert->valid_to?->toIso8601String()],
                ]);

                $this->info("Successfully renewed: {$cert->name}");
            } catch (\Throwable $e) {
                $failures++;
                $errorMsg = $e->getMessage();
                $this->error("Failed to renew {$cert->name}: {$errorMsg}");

                $cert->update(['last_renew_error' => $errorMsg]);

                CertificateAuditLog::create([
                    'certificate_id' => $cert->id,
                    'admin_id' => null,
                    'action' => 'renew_failed',
                    'status' => 'failed',
                    'message' => "Automated renewal failed for {$cert->common_name}: {$errorMsg}",
                    'details' => ['error' => $errorMsg],
                ]);

                $admins = Admin::where('enabled', true)->get();
                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new CertificateRenewalFailedNotification($cert, $errorMsg));
                }
            }
        }

        // Notify administrators about expiring certificates that cannot auto-renew
        $this->notifyExpiringNonAutoRenewCertificates();

        if ($failures > 0) {
            $this->warn("Renewal sweep completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->info('Certificate renewal sweep completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Check for expiring certificates that cannot auto-renew (custom or self-signed)
     * and alert administrators if within 30 days of expiration.
     */
    protected function notifyExpiringNonAutoRenewCertificates(): void
    {
        $expiring = Certificate::where(function ($q): void {
            $q->where('type', '!=', 'lets_encrypt')
                ->orWhere('auto_renew', false);
        })
            ->whereNotNull('valid_to')
            ->where('valid_to', '<=', now()->addDays(30))
            ->get();

        if ($expiring->isEmpty()) {
            return;
        }

        $admins = Admin::where('enabled', true)->get();
        if ($admins->isEmpty()) {
            return;
        }

        foreach ($expiring as $cert) {
            $days = $cert->days_until_expiration ?? 0;
            Notification::send($admins, new CertificateExpiringNotification($cert, $days));
        }
    }
}
