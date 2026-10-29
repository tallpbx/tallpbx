<?php

declare(strict_types=1);

namespace Modules\Provision\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Modules\Devices\Models\Device;
use Modules\Extensions\Services\ExtensionServiceInterface;
use Modules\Provision\Models\ProvisionTemplate;

/**
 * Service for provisioning devices with vendor-specific configuration.
 *
 * Resolves the appropriate template for a device (model-specific → vendor default
 * → database-stored template) and renders it with the device's settings.
 * Vendor modules can register additional templates via registerTemplate().
 */
class ProvisionService implements ProvisionServiceInterface
{
    public const TEMPLATE_PATH_PATTERN = '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)?(\.blade\.php)?$/';

    /** @var array<string, array<string, string>> */
    private array $registeredTemplates = [];

    /**
     * Create the provisioning service with extension contract support.
     */
    public function __construct(
        private readonly ExtensionServiceInterface $extensionService,
    ) {}

    /**
     * Create a new provisioning template.
     */
    public function createTemplate(array $data): ProvisionTemplate
    {
        return DB::transaction(fn () => ProvisionTemplate::create($data));
    }

    /**
     * Update an existing provisioning template and return the fresh copy.
     */
    public function updateTemplate(ProvisionTemplate $template, array $data): ProvisionTemplate
    {
        return DB::transaction(function () use ($template, $data): ProvisionTemplate {
            $template->update($data);

            return $template->fresh();
        });
    }

    /**
     * Delete a provisioning template.
     */
    public function deleteTemplate(ProvisionTemplate $template): void
    {
        DB::transaction(fn () => $template->delete());
    }

    /**
     * Register a vendor template file path for a specific model.
     */
    public function registerTemplate(string $vendor, string $model, string $path): void
    {
        $this->registeredTemplates[$vendor][$model] = $path;
    }

    /**
     * Resolve the template file path for a vendor and model.
     */
    public function resolveTemplate(string $vendor, ?string $model = null, ?int $tenantId = null): ?string
    {
        $vendor = strtolower($vendor);

        if ($model && isset($this->registeredTemplates[$vendor][$model])) {
            return $this->registeredTemplates[$vendor][$model];
        }

        if (isset($this->registeredTemplates[$vendor]['default'])) {
            return $this->registeredTemplates[$vendor]['default'];
        }

        // Try DB-persisted templates (tenant-specific takes precedence over global)
        $dbTemplateQuery = ProvisionTemplate::withoutGlobalScope('tenant')
            ->where('enabled', true)
            ->where('vendor', $vendor)
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
                    ->orderByRaw('tenant_id IS NULL ASC'),
                fn ($q) => $q->whereNull('tenant_id')
            );

        if ($model) {
            $dbModelTemplate = (clone $dbTemplateQuery)->where('model', $model)->first();
            if ($dbModelTemplate) {
                return $dbModelTemplate->file_path;
            }

            // Use module-bundled model default if it exists
            $defaultPath = "{$vendor}/{$model}/default";
            if (View::exists("provision::templates.{$defaultPath}")) {
                return $defaultPath;
            }
        }

        // Try DB-persisted vendor-wide template (where model is null or empty)
        $dbVendorTemplate = (clone $dbTemplateQuery)
            ->where(fn ($q) => $q->whereNull('model')->orWhere('model', ''))
            ->first();

        if ($dbVendorTemplate) {
            return $dbVendorTemplate->file_path;
        }

        // Fall back to vendor-wide bundled default
        $vendorDefault = "{$vendor}/default";
        if (View::exists("provision::templates.{$vendorDefault}")) {
            return $vendorDefault;
        }

        return null;
    }

    /**
     * Provision a device by MAC address. Returns rendered config.
     */
    public function provision(string $mac): array
    {
        $device = Device::withoutGlobalScope('tenant')
            ->where('mac_address', $mac)
            ->where('enabled', true)
            ->firstOrFail();

        $vendor = $device->vendor;
        $model = $device->model;
        $templatePath = $this->resolveTemplate(
            $vendor,
            $model,
            $device->tenant_id !== null ? (int) $device->tenant_id : null
        );

        if ($templatePath === null) {
            abort(404, 'No provisioning template found for this device.');
        }

        $content = $this->renderTemplate($templatePath, $device, $this->resolveLine($device));

        return [
            'content' => $content,
            'content_type' => 'text/plain; charset=UTF-8',
        ];
    }

    /**
     * Render a template by normalized view name.
     *
     * The device's settings are merged over the resolved line so real
     * SipAccount credentials win while vendor-specific settings survive.
     *
     * @param  array{sip_username: string, sip_password: string, display_name: string}  $line
     */
    private function renderTemplate(string $templatePath, Device $device, array $line): string
    {
        $settings = array_merge($device->settings ?? [], $line);

        $templatesDir = realpath(base_path('app-modules/provision/resources/views/templates'));

        $normalized = $templatePath;
        if ($templatesDir !== false && str_starts_with($templatePath, $templatesDir.'/')) {
            $normalized = substr($templatePath, strlen($templatesDir) + 1);
        }

        if (! preg_match(self::TEMPLATE_PATH_PATTERN, $normalized)) {
            throw new \InvalidArgumentException("Invalid template path [{$templatePath}].");
        }

        if (str_ends_with($normalized, '.blade.php')) {
            $normalized = substr($normalized, 0, -strlen('.blade.php'));
        }

        $viewKey = str_replace('/', '.', $normalized);
        $viewName = 'provision::templates.'.$viewKey;

        if (! View::exists($viewName)) {
            abort(404, "Provisioning template view [{$viewName}] not found.");
        }

        return view($viewName, ['device' => $device, 'settings' => $settings])->render();
    }

    /**
     * Resolve the real line credentials for a device.
     *
     * Prefers the linked SipAccount (the credentials FreeSWITCH checks at
     * registration); free-form settings fill anything the account does not
     * carry and are the only source when no account is linked. A linked
     * account from another tenant is ignored (tenant binding).
     *
     * @return array{sip_username: string, sip_password: string, display_name: string}
     */
    private function resolveLine(Device $device): array
    {
        $settings = $device->settings ?? [];
        $account = $device->sipAccount;

        if ($account !== null && $account->tenant_id !== $device->tenant_id) {
            $account = null;
        }

        // The extension is queried without the tenant scope because the
        // public endpoint has no tenant context (the scope throws then).
        $displayName = $settings['display_name'] ?? 'IP Phone';

        if ($account !== null && $account->extension_id !== null) {
            $extension = $this->extensionService->find($account->extension_id);
            $extensionName = $extension?->display_name;

            if ($extensionName !== null) {
                $displayName = $extensionName;
            }
        }

        return [
            'sip_username' => (string) ($account?->auth_username ?? $settings['sip_username'] ?? ''),
            'sip_password' => (string) ($account?->auth_password ?? $settings['sip_password'] ?? ''),
            'display_name' => (string) $displayName,
        ];
    }
}
