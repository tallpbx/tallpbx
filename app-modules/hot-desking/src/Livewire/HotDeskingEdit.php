<?php

declare(strict_types=1);

namespace Modules\HotDesking\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Extensions\Models\Extension;
use Modules\HotDesking\Models\HotDeskSession;
use Modules\HotDesking\Services\HotDeskingServiceInterface;

/**
 * Livewire component for creating and editing hot desking sessions.
 */
class HotDeskingEdit extends BaseEditComponent
{
    public ?string $sessionId = null;

    public ?string $extensionId = null;

    public ?string $deviceExtensionId = null;

    public ?string $description = null;

    public bool $isActive = true;

    /** @var Collection<int, Extension> */
    public Collection $availableExtensions;

    private HotDeskingServiceInterface $hotDeskingService;

    public function boot(HotDeskingServiceInterface $hotDeskingService): void
    {
        $this->hotDeskingService = $hotDeskingService;
    }

    /**
     * Initialize the component.
     */
    public function mount(?string $sessionId = null): void
    {
        $this->loadTenants();

        if ($sessionId !== null) {
            $this->sessionId = $sessionId;
            $session = HotDeskSession::withoutGlobalScope('tenant')->findOrFail($sessionId);
            $this->assertCanAccessTenantRecord($session);

            $this->tenantId = $session->tenant_id;
            $this->extensionId = $session->extension_id;
            $this->deviceExtensionId = $session->device_extension_id;
            $this->description = $session->description;
            $this->isActive = $session->is_active;
        }

        $this->loadAvailableExtensions();
    }

    /**
     * Determine if in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->sessionId !== null;
    }

    /**
     * Handle tenantId update for admin users.
     */
    public function updatedTenantId(): void
    {
        $this->extensionId = null;
        $this->deviceExtensionId = null;
        $this->loadAvailableExtensions();
    }

    /**
     * Load available extensions for the active tenant.
     */
    public function loadAvailableExtensions(): void
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            $this->availableExtensions = new Collection;

            return;
        }

        $this->availableExtensions = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('extension_number')
            ->get();
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'extensionId' => ['required', 'uuid', 'different:deviceExtensionId', 'exists:extensions,id'],
            'deviceExtensionId' => ['required', 'uuid', 'exists:extensions,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'isActive' => ['boolean'],
        ];

        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'extensionId.different' => 'The user extension and physical desk extension must be different.',
        ];
    }

    /**
     * Save the hot desking session.
     */
    public function save(): void
    {
        $this->validate();
        $tenantId = $this->resolveTenantId();

        if ($this->sessionId !== null) {
            $session = HotDeskSession::withoutGlobalScope('tenant')->findOrFail($this->sessionId);
            $this->assertCanAccessTenantRecord($session);

            $session->update([
                'extension_id' => $this->extensionId,
                'device_extension_id' => $this->deviceExtensionId,
                'description' => $this->description,
                'is_active' => $this->isActive,
                'logout_at' => $this->isActive ? null : now(),
            ]);

            $this->showSuccess('Hot desking session updated.');
        } else {
            $this->hotDeskingService->login(
                $tenantId,
                $this->extensionId,
                $this->deviceExtensionId,
                request()->ip(),
                $this->description
            );

            $this->showSuccess('Hot desking session started.');
        }

        $this->redirect(route('panel.hot-desking.index'));
    }
}
