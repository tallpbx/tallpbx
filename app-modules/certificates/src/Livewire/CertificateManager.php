<?php

declare(strict_types=1);

namespace Modules\Certificates\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Contracts\SelfSignedGeneratorServiceInterface;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Models\CertificateDnsCredential;
use Throwable;

#[Layout('layouts.app')]

/**
 * Certificate Manager Livewire component.
 *
 * Provides a unified administrative interface for managing SSL/TLS certificates,
 * issuing Let's Encrypt certificates (HTTP-01 & Cloudflare DNS-01), importing custom PEM
 * certificates with live modulus validation, generating self-signed lab certificates,
 * storing DNS API tokens in the DNS Vault, and deploying certificates atomically across
 * Nginx Web and FreeSWITCH Telephony.
 */
class CertificateManager extends Component
{
    use HasOperationalFeedback;
    use WithPagination;

    /**
     * Active tab in the navigation bar.
     */
    #[Url(as: 'tab')]
    public string $activeTab = 'inventory';

    /**
     * Search filter for certificates inventory.
     */
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * ID of certificate currently opened in details modal.
     */
    public ?int $viewingCertificateId = null;

    /**
     * ID of certificate currently opened in service assignment modal.
     */
    public ?int $assigningCertificateId = null;

    /**
     * Service assignment checkbox for Web.
     */
    public bool $assignWeb = false;

    /**
     * Service assignment checkbox for Telephony.
     */
    public bool $assignTelephony = false;

    // ─── Let's Encrypt Form Fields ──────────────────────────────────────

    /**
     * Display name for the Let's Encrypt certificate.
     */
    public string $le_name = '';

    /**
     * Primary domain name for Let's Encrypt.
     */
    public string $le_domain = '';

    /**
     * Administrator email address for ACME registration.
     */
    public string $le_email = '';

    /**
     * Challenge type: 'http' or 'dns'.
     */
    public string $le_challenge_type = 'http';

    /**
     * Selected DNS credential ID when using DNS-01 challenge.
     */
    public ?int $le_dns_credential_id = null;

    /**
     * Whether to include wildcard domain (*.domain.com) for DNS-01.
     */
    public bool $le_wildcard = false;

    /**
     * Whether to use Let's Encrypt Staging environment.
     */
    public bool $le_staging = false;

    /**
     * Whether to auto-deploy to Web upon issuance.
     */
    public bool $le_auto_deploy_web = false;

    /**
     * Whether to auto-deploy to Telephony upon issuance.
     */
    public bool $le_auto_deploy_telephony = false;

    // ─── Custom Import Form Fields ──────────────────────────────────────

    /**
     * Display name for the imported custom certificate.
     */
    public string $custom_name = '';

    /**
     * PEM-encoded certificate string.
     */
    public string $custom_cert = '';

    /**
     * PEM-encoded private key string.
     */
    public string $custom_key = '';

    /**
     * PEM-encoded intermediate CA chain string.
     */
    public string $custom_chain = '';

    /**
     * Whether to auto-deploy to Web upon custom import.
     */
    public bool $custom_auto_deploy_web = false;

    /**
     * Whether to auto-deploy to Telephony upon custom import.
     */
    public bool $custom_auto_deploy_telephony = false;

    // ─── Self-Signed Form Fields ────────────────────────────────────────

    /**
     * Display name for self-signed certificate.
     */
    public string $self_name = '';

    /**
     * Common Name (CN) for self-signed certificate.
     */
    public string $self_common_name = '';

    /**
     * Comma-separated SAN domains or IP addresses for self-signed certificate.
     */
    public string $self_san_domains = '';

    /**
     * Validity duration in days for self-signed certificate.
     */
    public int $self_days = 365;

    /**
     * Whether to auto-deploy to Web upon self-signed generation.
     */
    public bool $self_auto_deploy_web = false;

    /**
     * Whether to auto-deploy to Telephony upon self-signed generation.
     */
    public bool $self_auto_deploy_telephony = false;

    // ─── DNS Vault Form Fields ──────────────────────────────────────────

    /**
     * Display name for the new DNS credential.
     */
    public string $new_dns_name = '';

    /**
     * Provider identifier for new DNS credential.
     */
    public string $new_dns_provider = 'cloudflare';

    /**
     * Secret API token for new DNS credential.
     */
    public string $new_dns_api_token = '';

    /**
     * Authorize that the current panel actor possesses the specified permission.
     *
     * @throws AuthorizationException
     */
    protected function authorizeAction(string $ability): void
    {
        $actor = auth('admin')->user() ?? auth('web')->user();

        if (! $actor || ! Gate::forUser($actor)->check($ability)) {
            throw new AuthorizationException("You don't have permission: {$ability}");
        }
    }

    /**
     * Notify user of a successful operation.
     */
    protected function notifySuccess(string $message): void
    {
        $this->showSuccess($message);
    }

    /**
     * Notify user of an operational error.
     */
    protected function notifyError(string $message): void
    {
        $this->showError($message);
    }

    /**
     * Reset pagination when search query is modified.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Switch active tab.
     */
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->dismissFeedback();
    }

    /**
     * Computed property: Certificates collection matching current search.
     *
     * @return Collection<int, Certificate>
     */
    #[Computed]
    public function certificates(): Collection
    {
        return Certificate::query()
            ->when($this->search !== '', function ($q): void {
                $term = "%{$this->search}%";
                $q->where(function ($sub) use ($term): void {
                    $sub->where('name', 'like', $term)
                        ->orWhere('common_name', 'like', $term)
                        ->orWhere('issuer', 'like', $term);
                });
            })
            ->orderByDesc('is_default_web')
            ->orderByDesc('is_default_telephony')
            ->orderBy('valid_to')
            ->get();
    }

    /**
     * Computed property: Runtime deployment status across Web and Telephony.
     *
     * @return array{active_web: string, telephony_active: bool, active_web_certificate: ?Certificate, active_telephony_certificate: ?Certificate}
     */
    #[Computed]
    public function deploymentStatus(): array
    {
        return app(CertificateDeploymentServiceInterface::class)->getDeploymentStatus();
    }

    /**
     * Computed property: Count of certificates expiring within 30 days.
     */
    #[Computed]
    public function expiringSoonCount(): int
    {
        return Certificate::query()
            ->where('valid_to', '<=', now()->addDays(30))
            ->where('valid_to', '>=', now())
            ->count();
    }

    /**
     * Computed property: Stored DNS API credentials.
     *
     * @return Collection<int, CertificateDnsCredential>
     */
    #[Computed]
    public function dnsCredentials(): Collection
    {
        return CertificateDnsCredential::query()->orderBy('name')->get();
    }

    /**
     * Computed property: Paginated audit logs.
     *
     * @return LengthAwarePaginator<CertificateAuditLog>
     */
    #[Computed]
    public function auditLogs(): LengthAwarePaginator
    {
        return CertificateAuditLog::query()
            ->with(['certificate', 'admin'])
            ->latest()
            ->paginate(15);
    }

    /**
     * Computed property: Live modulus matching status for custom certificate input.
     */
    #[Computed]
    public function modulusMatches(): ?bool
    {
        $cert = trim($this->custom_cert);
        $key = trim($this->custom_key);

        if ($cert === '' || $key === '') {
            return null;
        }

        try {
            return app(CertificateValidatorServiceInterface::class)->validateKeypair($cert, $key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Computed property: Certificate currently being viewed in details modal.
     */
    #[Computed]
    public function viewingCertificate(): ?Certificate
    {
        return $this->viewingCertificateId
            ? Certificate::find($this->viewingCertificateId)
            : null;
    }

    /**
     * Computed property: Certificate currently being assigned to services in modal.
     */
    #[Computed]
    public function assigningCertificate(): ?Certificate
    {
        return $this->assigningCertificateId
            ? Certificate::find($this->assigningCertificateId)
            : null;
    }

    /**
     * Open service assignment modal.
     */
    public function openAssignModal(int $id): void
    {
        $cert = Certificate::findOrFail($id);
        $this->assigningCertificateId = $cert->id;
        $this->assignWeb = (bool) $cert->is_default_web;
        $this->assignTelephony = (bool) $cert->is_default_telephony;
    }

    /**
     * Open certificate details modal.
     */
    public function openDetailsModal(int $id): void
    {
        $this->viewingCertificateId = $id;
    }

    /**
     * Close all active modals.
     */
    public function closeModals(): void
    {
        $this->viewingCertificateId = null;
        $this->assigningCertificateId = null;
    }

    /**
     * Save service assignment from modal.
     */
    public function saveServiceAssignment(CertificateDeploymentServiceInterface $deployer): void
    {
        $this->authorizeAction('certificates.deploy');

        if (! $this->assigningCertificateId) {
            return;
        }

        $cert = Certificate::findOrFail($this->assigningCertificateId);
        $adminId = auth('admin')->id();

        try {
            if ($this->assignWeb && ! $cert->is_default_web) {
                $deployer->deployWeb($cert, $adminId);
            }
            if ($this->assignTelephony && ! $cert->is_default_telephony) {
                $deployer->deployTelephony($cert, $adminId);
            }

            $this->closeModals();
            $this->notifySuccess("Updated service assignment for '{$cert->name}'.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Deploy certificate directly to Nginx Web.
     */
    public function deployWeb(int $id, CertificateDeploymentServiceInterface $deployer): void
    {
        $this->authorizeAction('certificates.deploy');
        $cert = Certificate::findOrFail($id);

        try {
            $deployer->deployWeb($cert, auth('admin')->id());
            $this->notifySuccess("Successfully deployed '{$cert->name}' to Nginx Web server.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Deploy certificate directly to FreeSWITCH Telephony.
     */
    public function deployTelephony(int $id, CertificateDeploymentServiceInterface $deployer): void
    {
        $this->authorizeAction('certificates.deploy');
        $cert = Certificate::findOrFail($id);

        try {
            $deployer->deployTelephony($cert, auth('admin')->id());
            $this->notifySuccess("Successfully deployed '{$cert->name}' to FreeSWITCH Telephony.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Deploy certificate to both Web and Telephony.
     */
    public function deployAll(int $id, CertificateDeploymentServiceInterface $deployer): void
    {
        $this->authorizeAction('certificates.deploy');
        $cert = Certificate::findOrFail($id);

        try {
            $deployer->deployAll($cert, auth('admin')->id());
            $this->notifySuccess("Successfully deployed '{$cert->name}' to Web and Telephony.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Manually renew a Let's Encrypt certificate.
     */
    public function renewCertificate(int $id, LetsEncryptAcmeServiceInterface $acmeService): void
    {
        $this->authorizeAction('certificates.renew');
        $cert = Certificate::findOrFail($id);

        try {
            $acmeService->renew($cert, auth('admin')->id());
            $this->notifySuccess("Successfully renewed '{$cert->name}'.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Delete an inactive certificate from the system.
     */
    public function deleteCertificate(int $id, CertificateExecutorInterface $executor): void
    {
        $this->authorizeAction('certificates.delete');
        $cert = Certificate::findOrFail($id);

        if ($cert->is_default_web || $cert->is_default_telephony) {
            $this->notifyError('Cannot delete an active certificate. Reassign Web and Telephony services first.');

            return;
        }

        try {
            $executor->delete($cert->storage_identifier);
            $certName = $cert->name;
            $adminId = auth('admin')->id();

            CertificateAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'deleted',
                'status' => 'success',
                'message' => "Deleted certificate '{$certName}' ({$cert->common_name}).",
                'details' => ['storage_identifier' => $cert->storage_identifier],
            ]);

            $cert->delete();
            $this->notifySuccess("Certificate '{$certName}' was deleted.");
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Issue a new Let's Encrypt certificate via HTTP-01 or DNS-01.
     */
    public function issueLetsEncrypt(LetsEncryptAcmeServiceInterface $acmeService): void
    {
        $this->authorizeAction('certificates.create');

        $this->validate([
            'le_name' => ['required', 'string', 'max:255'],
            'le_domain' => ['required', 'string'],
            'le_email' => ['required', 'email'],
            'le_challenge_type' => ['required', 'in:http,dns'],
            'le_dns_credential_id' => ['required_if:le_challenge_type,dns', 'nullable', 'exists:certificate_dns_credentials,id'],
        ]);

        $adminId = auth('admin')->id();

        try {
            if ($this->le_challenge_type === 'http') {
                $cert = $acmeService->issueHttp(
                    name: $this->le_name,
                    domain: $this->le_domain,
                    email: $this->le_email,
                    staging: $this->le_staging,
                    autoDeployWeb: $this->le_auto_deploy_web,
                    autoDeployTelephony: $this->le_auto_deploy_telephony,
                    adminId: $adminId,
                );
            } else {
                $dnsCred = CertificateDnsCredential::findOrFail($this->le_dns_credential_id);
                $cert = $acmeService->issueDns(
                    name: $this->le_name,
                    domain: $this->le_domain,
                    email: $this->le_email,
                    dnsCredential: $dnsCred,
                    wildcard: $this->le_wildcard,
                    staging: $this->le_staging,
                    autoDeployWeb: $this->le_auto_deploy_web,
                    autoDeployTelephony: $this->le_auto_deploy_telephony,
                    adminId: $adminId,
                );
            }

            $this->reset([
                'le_name', 'le_domain', 'le_email', 'le_staging',
                'le_wildcard', 'le_auto_deploy_web', 'le_auto_deploy_telephony',
            ]);
            $this->notifySuccess("Successfully issued Let's Encrypt certificate '{$cert->name}'.");
            $this->activeTab = 'inventory';
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Import a custom PEM certificate, private key, and optional intermediate chain.
     */
    public function importCustomCertificate(
        CertificateValidatorServiceInterface $validator,
        CertificateParserServiceInterface $parser,
        CertificateDeploymentServiceInterface $deployer,
        CertificateExecutorInterface $executor,
    ): void {
        $this->authorizeAction('certificates.create');

        $this->validate([
            'custom_name' => ['required', 'string', 'max:255'],
            'custom_cert' => ['required', 'string'],
            'custom_key' => ['required', 'string'],
            'custom_chain' => ['nullable', 'string'],
        ]);

        try {
            $validator->validateKeypair($this->custom_cert, $this->custom_key);
            $parsed = $parser->parse($this->custom_cert);

            $storageId = 'custom_'.Str::slug($parsed['common_name'], '_').'_'.time();

            // Write temporary files for executor with 0600 permissions
            $tempCert = tempnam(sys_get_temp_dir(), 'custom_cert_');
            $tempKey = tempnam(sys_get_temp_dir(), 'custom_key_');
            $tempChain = $this->custom_chain !== '' ? tempnam(sys_get_temp_dir(), 'custom_chain_') : null;

            if ($tempCert === false || $tempKey === false || ($this->custom_chain !== '' && $tempChain === false)) {
                throw new \RuntimeException('Failed to allocate temporary files for certificate import.');
            }

            file_put_contents($tempCert, $this->custom_cert);
            file_put_contents($tempKey, $this->custom_key);
            if ($tempChain && $this->custom_chain !== '') {
                file_put_contents($tempChain, $this->custom_chain);
            }

            chmod($tempCert, 0600);
            chmod($tempKey, 0600);
            if ($tempChain) {
                chmod($tempChain, 0600);
            }

            try {
                $result = $executor->importCustom($storageId, $tempCert, $tempKey, $tempChain);
            } finally {
                @unlink($tempCert);
                @unlink($tempKey);
                if ($tempChain) {
                    @unlink($tempChain);
                }
            }

            if (! $result['success']) {
                $errorMsg = $result['error'] !== '' ? $result['error'] : 'Failed to import certificate into system storage.';
                $this->notifyError($errorMsg);

                return;
            }

            $cert = Certificate::create([
                'name' => $this->custom_name,
                'type' => Certificate::TYPE_CUSTOM,
                'common_name' => $parsed['common_name'],
                'san_domains' => $parsed['san_domains'],
                'issuer' => $parsed['issuer'],
                'valid_from' => $parsed['valid_from'],
                'valid_to' => $parsed['valid_to'],
                'serial_number' => $parsed['serial_number'],
                'fingerprint_sha256' => $parsed['fingerprint_sha256'],
                'is_default_web' => false,
                'is_default_telephony' => false,
                'auto_renew' => false,
                'storage_identifier' => $storageId,
            ]);

            $adminId = auth('admin')->id();

            CertificateAuditLog::create([
                'certificate_id' => $cert->id,
                'admin_id' => $adminId,
                'action' => 'imported',
                'status' => 'success',
                'message' => "Imported custom certificate '{$cert->name}' ({$cert->common_name}).",
                'details' => ['storage_identifier' => $storageId],
            ]);

            if ($this->custom_auto_deploy_web) {
                $deployer->deployWeb($cert, $adminId);
            }

            if ($this->custom_auto_deploy_telephony) {
                $deployer->deployTelephony($cert, $adminId);
            }

            $this->reset([
                'custom_name', 'custom_cert', 'custom_key',
                'custom_chain', 'custom_auto_deploy_web', 'custom_auto_deploy_telephony',
            ]);
            $this->notifySuccess("Custom certificate '{$cert->name}' imported successfully.");
            $this->activeTab = 'inventory';
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Generate a self-signed certificate.
     */
    public function generateSelfSigned(SelfSignedGeneratorServiceInterface $generator): void
    {
        $this->authorizeAction('certificates.create');

        $this->validate([
            'self_name' => ['required', 'string', 'max:255'],
            'self_common_name' => ['required', 'string'],
            'self_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $sanArray = array_filter(array_map('trim', explode(',', $this->self_san_domains)));
        $adminId = auth('admin')->id();

        try {
            $cert = $generator->generate(
                name: $this->self_name,
                commonName: $this->self_common_name,
                days: (int) $this->self_days,
                sanDomains: $sanArray,
                autoDeployWeb: $this->self_auto_deploy_web,
                autoDeployTelephony: $this->self_auto_deploy_telephony,
                adminId: $adminId,
            );

            $this->reset([
                'self_name', 'self_common_name', 'self_san_domains',
                'self_auto_deploy_web', 'self_auto_deploy_telephony',
            ]);
            $this->self_days = 365;
            $this->notifySuccess("Successfully generated self-signed certificate '{$cert->name}'.");
            $this->activeTab = 'inventory';
        } catch (Throwable $e) {
            $this->notifyError($e->getMessage());
        }
    }

    /**
     * Save a new DNS credential to the vault.
     */
    public function createDnsCredential(): void
    {
        $this->authorizeAction('certificates.create');

        $this->validate([
            'new_dns_name' => ['required', 'string', 'max:255'],
            'new_dns_api_token' => ['required', 'string', 'min:10'],
        ]);

        CertificateDnsCredential::create([
            'name' => $this->new_dns_name,
            'provider' => 'cloudflare',
            'credentials' => [
                'api_token' => trim($this->new_dns_api_token),
            ],
        ]);

        $this->reset(['new_dns_name', 'new_dns_api_token']);
        $this->notifySuccess('Cloudflare DNS credential saved to vault.');
    }

    /**
     * Delete an unused DNS credential from the vault.
     */
    public function deleteDnsCredential(int $id): void
    {
        $this->authorizeAction('certificates.delete');
        $cred = CertificateDnsCredential::findOrFail($id);

        if ($cred->certificates()->exists()) {
            $this->notifyError('Cannot delete DNS credential because it is associated with active certificates.');

            return;
        }

        $cred->delete();
        $this->notifySuccess('DNS credential deleted from vault.');
    }

    /**
     * Render the certificate manager view.
     */
    public function render(): View
    {
        return view('certificates::certificate-manager');
    }
}
