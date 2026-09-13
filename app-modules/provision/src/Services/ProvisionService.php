<?php

declare(strict_types=1);

namespace Modules\Provision\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;
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
    /** @var array<string, array<string, string>> */
    private array $registeredTemplates = [];

    public function createTemplate(array $data): ProvisionTemplate
    {
        return DB::transaction(fn () => ProvisionTemplate::create($data));
    }

    public function updateTemplate(ProvisionTemplate $template, array $data): ProvisionTemplate
    {
        return DB::transaction(function () use ($template, $data): ProvisionTemplate {
            $template->update($data);

            return $template->fresh();
        });
    }

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
    public function resolveTemplate(string $vendor, ?string $model): ?string
    {
        if ($model && isset($this->registeredTemplates[$vendor][$model])) {
            return $this->registeredTemplates[$vendor][$model];
        }

        if (isset($this->registeredTemplates[$vendor]['default'])) {
            return $this->registeredTemplates[$vendor]['default'];
        }

        // Use module-bundled default if it exists
        $defaultPath = "{$vendor}/{$model}/default";
        if ($model && view()->exists("provision::templates.{$defaultPath}")) {
            return $defaultPath;
        }

        // Fall back to vendor-wide default
        $vendorDefault = "{$vendor}/default";
        if (view()->exists("provision::templates.{$vendorDefault}")) {
            return $vendorDefault;
        }

        // Then try DB-persisted templates
        $dbTemplate = ProvisionTemplate::withoutGlobalScope('tenant')
            ->where('enabled', true)
            ->when($model, fn ($q) => $q->where('model', $model))
            ->first();

        if ($dbTemplate) {
            return $dbTemplate->file_path;
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
        $templatePath = $this->resolveTemplate($vendor, $model);

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
     * Render a template by filesystem path or view name.
     *
     * The device's settings are merged over the resolved line so real
     * SipAccount credentials win while vendor-specific settings survive.
     *
     * @param  array{sip_username: string, sip_password: string, display_name: string}  $line
     */
    private function renderTemplate(string $templatePath, Device $device, array $line): string
    {
        $settings = array_merge($device->settings ?? [], $line);

        // Filesystem path (from vendor modules)
        if (file_exists($templatePath)) {
            return Blade::render(
                file_get_contents($templatePath),
                ['device' => $device, 'settings' => $settings]
            );
        }

        // View name (from module-bundled or DB templates)
        $viewName = 'provision::templates.'.str_replace(['/', '.blade.php'], ['.', ''], $templatePath);

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
            $extensionName = Extension::withoutGlobalScope('tenant')
                ->whereKey($account->extension_id)
                ->value('display_name');

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
