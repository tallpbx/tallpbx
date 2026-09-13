<?php

declare(strict_types=1);

namespace Modules\Speech\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Speech\Services\SpeechServiceInterface;

/**
 * Create/edit form for TTS engine configurations.
 *
 * Provides fields for selecting the TTS engine, voice, language,
 * speech rate, and enabled status. Loads existing config data
 * when editing.
 */
class SpeechEdit extends BaseEditComponent
{
    /** @var Collection<int, Tenant> Available tenants */
    public Collection $tenants;

    public string $engine = '';

    public string $voice = '';

    public string $language = '';

    public string $rate = '1.00';

    public ?string $configId = null;

    /** The speech service instance. */
    private SpeechServiceInterface $service;

    /**
     * Inject the speech service via Livewire's dependency injection.
     */
    public function boot(SpeechServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Initialize form state, loading existing data when editing.
     */
    public function mount(?string $configId = null): void
    {
        $this->loadTenants();

        if ($configId === null) {
            return;
        }

        $config = $this->service->find($configId);
        $this->configId = $config->id;
        $this->tenantId = $config->tenant_id;
        $this->engine = $config->engine;
        $this->voice = $config->voice ?? '';
        $this->language = $config->language ?? '';
        $this->rate = number_format((float) $config->rate, 2);
        $this->enabled = $config->enabled;
    }

    /**
     * Whether we are editing an existing config or creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->configId !== null;
    }

    /**
     * Validate and persist the speech configuration.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'engine' => $this->engine,
            'voice' => $this->voice ?: null,
            'language' => $this->language ?: null,
            'rate' => (float) $this->rate,
            'enabled' => $this->enabled,
        ];

        if ($this->isEdit) {
            $config = $this->service->find($this->configId);
            $this->service->update($config, $data);
        } else {
            $this->service->create($data);
        }

        $this->redirect(route('panel.speech.index'), navigate: true);
    }

    /**
     * Validation rules for the speech config form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'engine' => 'required|string|max:255',
            'voice' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
            'rate' => 'nullable|numeric|between:0.1,3.0',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Render the speech config form view.
     */
}
