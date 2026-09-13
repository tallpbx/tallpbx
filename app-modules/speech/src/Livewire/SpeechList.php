<?php

declare(strict_types=1);

namespace Modules\Speech\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Speech\Services\SpeechServiceInterface;

/**
 * Admin page listing all TTS engine configurations.
 *
 * Displays a table of speech configs with engine, voice, language,
 * rate, and enabled status. Admins can create, edit, or delete
 * individual configurations.
 */
class SpeechList extends BaseListComponent
{
    /** @var Collection<int, SpeechConfig> All TTS configurations */
    public Collection $configs;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

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
     * Load all configs on component mount.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch all speech configs ordered by engine name.
     */
    private function load(): void
    {
        $this->configs = $this->service->all();
    }

    /** Open the shared confirmation modal for a speech configuration. */
    public function confirmConfigDeletion(string $id): void
    {
        $config = $this->service->find($id);
        $this->pendingDeletionId = $config->id;
        $this->pendingDeletionName = $config->engine.' / '.($config->voice ?? $config->language);
    }

    /** Close the speech-configuration confirmation modal without deleting anything. */
    public function cancelConfigDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the speech configuration that the user confirmed. */
    public function deleteConfig(): void
    {
        $config = $this->service->find($this->pendingDeletionId);
        $this->service->delete($config);
        $this->cancelConfigDeletion();
        $this->load();
        $this->showSuccess('Speech configuration deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /**
     * Render the speech config list view.
     */
}
