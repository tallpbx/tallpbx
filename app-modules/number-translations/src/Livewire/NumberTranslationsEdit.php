<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Livewire;

use App\Support\BaseEditComponent;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

class NumberTranslationsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $matchPattern = '';

    public string $replacePattern = '';

    public string $direction = 'outbound';

    public int $order = 0;

    public ?string $translationId = null;

    private NumberTranslationServiceInterface $service;

    public function boot(NumberTranslationServiceInterface $service): void
    {
        $this->service = $service;
    }

    public function mount(?string $translationId = null): void
    {
        $this->loadTenants();

        if ($translationId === null) {
            return;
        }

        $translation = NumberTranslation::withoutGlobalScope('tenant')->findOrFail($translationId);
        // Tenant users may only open records of their active tenant.
        $this->assertCanAccessTenantRecord($translation);
        $this->translationId = $translation->id;
        $this->tenantId = $translation->tenant_id;
        $this->name = $translation->name;
        $this->matchPattern = $translation->match_pattern;
        $this->replacePattern = $translation->replace_pattern ?? '';
        $this->direction = $translation->direction;
        $this->order = (int) $translation->order;
        $this->enabled = $translation->enabled;
    }

    public function getIsEditProperty(): bool
    {
        return $this->translationId !== null;
    }

    public function save(): void
    {
        $this->validate();

        // Admins choose the tenant from the dropdown; tenant users are
        // always pinned to their active tenant context.
        $data = [
            'tenant_id' => $this->resolveTenantId(),
            'name' => $this->name,
            'match_pattern' => $this->matchPattern,
            'replace_pattern' => $this->replacePattern ?: null,
            'direction' => $this->direction,
            'order' => $this->order,
            'enabled' => $this->enabled,
        ];

        if ($this->isEdit) {
            $translation = NumberTranslation::withoutGlobalScope('tenant')->findOrFail($this->translationId);
            // Re-check ownership at save time in case the form state was
            // tampered with between mount and submit.
            $this->assertCanAccessTenantRecord($translation);
            $this->service->update($translation, $data);
        } else {
            $this->service->create($data);
        }

        $this->redirect(route('panel.number-translations.index'), navigate: true);
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'matchPattern' => 'required|string|max:255',
            'replacePattern' => 'nullable|string|max:255',
            'direction' => 'required|in:inbound,outbound,both',
            'order' => 'required|integer|min:0',
            'enabled' => 'boolean',
        ];

        // Only admin users pick a tenant from the dropdown; tenant users are
        // auto-scoped to their active tenant context.
        if ($this->isAdminGuard()) {
            $rules['tenantId'] = 'required|exists:tenants,id';
        }

        return $rules;
    }
}
