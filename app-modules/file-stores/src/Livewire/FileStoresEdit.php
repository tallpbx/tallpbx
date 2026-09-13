<?php

declare(strict_types=1);

namespace Modules\FileStores\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Support\Facades\Auth;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\FileStoreServiceInterface;

/**
 * Creates and updates a system-owned file store profile.
 */
class FileStoresEdit extends BaseEditComponent
{
    public ?string $fileStoreId = null;

    public string $name = '';

    public string $provider = 'local';

    /** @var array<string, mixed> */
    public array $settings = ['root' => ''];

    /**
     * Initialize a new profile or load an existing profile with secrets blanked.
     */
    public function mount(?string $fileStoreId = null): void
    {
        $this->authorizeSystemAdmin();
        $this->loadTenants();

        if ($fileStoreId === null) {
            return;
        }

        $fileStore = FileStore::findOrFail($fileStoreId);
        $this->fileStoreId = $fileStore->id;
        $this->name = $fileStore->name;
        $this->provider = $fileStore->provider;
        $this->settings = $this->blankSensitiveSettings($fileStore->settings);
    }

    /**
     * Reset form fields for the newly selected provider type.
     */
    public function updatedProvider(): void
    {
        $this->settings = $this->defaultSettings($this->provider);
    }

    /**
     * Persist the current profile using the shared validation and encryption service.
     */
    public function save(FileStoreServiceInterface $fileStoreService): void
    {
        $this->authorizeSystemAdmin();

        $data = [
            'name' => $this->name,
            'provider' => $this->provider,
            'settings' => $this->settings,
        ];

        if ($this->fileStoreId === null) {
            $fileStoreService->create($data);
        } else {
            $fileStoreService->update(FileStore::findOrFail($this->fileStoreId), $data);
        }

        $this->redirectRoute('panel.file-stores.index', navigate: true);
    }

    /**
     * Return provider identifiers for the selector.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_filter(
            FileStoreServiceInterface::Providers,
            fn (string $provider): bool => $provider !== 'local',
        ));
    }

    /**
     * Provide an empty, provider-specific settings shape for a new profile.
     *
     * @return array<string, mixed>
     */
    private function defaultSettings(string $provider): array
    {
        return match ($provider) {
            's3' => ['bucket' => '', 'region' => '', 'access_key' => '', 'secret' => '', 'endpoint' => ''],
            'ftp' => ['host' => '', 'username' => '', 'password' => '', 'root' => '', 'port' => 21, 'ssl' => false, 'passive' => true],
            'sftp', 'ssh' => ['host' => '', 'username' => '', 'password' => '', 'private_key' => '', 'passphrase' => '', 'root' => '', 'port' => 22, 'host_fingerprint' => ''],
            'dropbox' => ['access_token' => ''],
            'email' => ['recipient' => '', 'sender' => ''],
            default => ['root' => ''],
        };
    }

    /**
     * Keep sensitive values write-only while retaining non-sensitive settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function blankSensitiveSettings(array $settings): array
    {
        foreach (FileStore::SensitiveSettings as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = '';
            }
        }

        return $settings;
    }

    /**
     * Require the dedicated system-admin guard for every Livewire action.
     */
    private function authorizeSystemAdmin(): void
    {
        abort_unless(Auth::guard('admin')->check(), 403);
    }
}
