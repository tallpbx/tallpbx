<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use App\Services\SettingServiceInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Backups\Models\Backup;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use RuntimeException;

/**
 * Manages encrypted destination profiles and provider-relative file streams.
 */
class FileStoreService implements FileStoreServiceInterface
{
    /**
     * Create a file store service with an adapter factory.
     */
    public function __construct(
        private readonly FileStoreAdapterFactoryInterface $adapterFactory,
        private readonly SettingServiceInterface $settings,
    ) {}

    /**
     * Create a new encrypted file store profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FileStore
    {
        return FileStore::create($this->validatedData($data));
    }

    /**
     * Update a file store, preserving existing sensitive settings when blank.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FileStore $fileStore, array $data): FileStore
    {
        $settings = $data['settings'] ?? $fileStore->settings;

        if (is_array($settings)) {
            $settings = $this->mergeSettings($fileStore->settings, $settings);
        }

        $validated = $this->validatedData([
            ...$data,
            'provider' => $data['provider'] ?? $fileStore->provider,
            'name' => $data['name'] ?? $fileStore->name,
            'settings' => $settings,
        ], $fileStore);

        $fileStore->update($validated);

        return $fileStore->fresh();
    }

    /**
     * Delete a profile while leaving destination contents untouched.
     */
    public function delete(FileStore $fileStore): void
    {
        $selectedMediaArchiveStore = $this->settings->get('media.archive_file_store_id');

        $backupNames = Backup::query()
            ->where('file_store_id', $fileStore->id)
            ->orderBy('name')
            ->pluck('name');

        if ($backupNames->isNotEmpty()) {
            $backupList = $backupNames->map(fn (string $name): string => '“'.$name.'”')->join(', ');

            throw new RuntimeException('Cannot delete “'.$fileStore->name.'” because it is used by backup '.$backupList.'. Delete or move that backup first.');
        }

        if ($selectedMediaArchiveStore === $fileStore->id) {
            throw new RuntimeException('Cannot delete “'.$fileStore->name.'” because it is the selected media archive destination. Select another destination first.');
        }

        if (MediaAsset::withoutGlobalScope('tenant')->where('file_store_id', $fileStore->id)->exists()) {
            throw new RuntimeException('Cannot delete “'.$fileStore->name.'” because media files are stored there. Move or delete those media files first.');
        }

        $fileStore->delete();
    }

    /**
     * Verify that a destination can be reached without writing a test object.
     */
    public function testConnection(FileStore $fileStore): void
    {
        $this->adapterFactory->make($fileStore)->testConnection();
    }

    /**
     * List files below a provider-relative prefix.
     *
     * @return list<string>
     */
    public function listFiles(FileStore $fileStore, string $prefix = ''): array
    {
        $this->assertRelativePath($prefix, true);

        return $this->adapterFactory->make($fileStore)->listFiles($prefix);
    }

    /**
     * Determine whether a provider-relative file exists.
     */
    public function fileExists(FileStore $fileStore, string $path): bool
    {
        $this->assertRelativePath($path);

        return $this->adapterFactory->make($fileStore)->fileExists($path);
    }

    /**
     * Open a readable stream from a provider-relative file path.
     *
     * @return resource
     */
    public function readStream(FileStore $fileStore, string $path)
    {
        $this->assertRelativePath($path);

        return $this->adapterFactory->make($fileStore)->readStream($path);
    }

    /**
     * Write a stream to a provider-relative file path.
     *
     * @param  resource  $stream
     */
    public function writeStream(FileStore $fileStore, string $path, $stream): void
    {
        $this->assertRelativePath($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('File store writes require a valid resource stream.');
        }

        $this->adapterFactory->make($fileStore)->writeStream($path, $stream);
    }

    /**
     * Delete a provider-relative file path.
     */
    public function deleteFile(FileStore $fileStore, string $path): void
    {
        $this->assertRelativePath($path);

        $this->adapterFactory->make($fileStore)->deleteFile($path);
    }

    /**
     * Resolve an existing local path after rejecting traversal and symlink escape.
     */
    public function resolveLocalPath(FileStore $fileStore, string $path): ?string
    {
        $this->assertRelativePath($path);

        if ($fileStore->provider !== 'local') {
            return null;
        }

        $root = $fileStore->settings['root'] ?? null;

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('Local file stores require a root path.');
        }

        $canonicalRoot = realpath($root);

        if ($canonicalRoot === false) {
            return null;
        }

        $resolvedPath = realpath($canonicalRoot.DIRECTORY_SEPARATOR.$path);

        if ($resolvedPath === false
            || ! is_file($resolvedPath)
            || ! $this->isPathWithinRoot($resolvedPath, $canonicalRoot)) {
            return null;
        }

        return $resolvedPath;
    }

    /**
     * Validate data and normalize settings for its selected provider.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, provider: string, settings: array<string, mixed>}
     */
    private function validatedData(array $data, ?FileStore $fileStore = null): array
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', Rule::unique('file_stores', 'name')->ignore($fileStore)],
            'provider' => ['required', 'string', Rule::in(FileStoreServiceInterface::Providers)],
            'settings' => ['required', 'array'],
        ]);

        $validator->after(function ($validator) use ($data): void {
            $provider = $data['provider'] ?? null;
            $settings = $data['settings'] ?? [];

            if (! is_string($provider) || ! is_array($settings)) {
                return;
            }

            $this->validateProviderSettings($validator, $provider, $settings);
        });

        /** @var array{name: string, provider: string, settings: array<string, mixed>} $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * Add provider-specific setting errors to the shared validator.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $settings
     */
    private function validateProviderSettings($validator, string $provider, array $settings): void
    {
        $rules = match ($provider) {
            'local' => ['root' => ['required', 'string']],
            's3' => [
                'bucket' => ['required', 'string'],
                'region' => ['required', 'string'],
                'access_key' => ['required', 'string'],
                'secret' => ['required', 'string'],
                'endpoint' => ['nullable', 'url'],
            ],
            'ftp' => [
                'host' => ['required', 'string'],
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
                'root' => ['nullable', 'string'],
                'port' => ['nullable', 'integer', 'between:1,65535'],
                'ssl' => ['nullable', 'boolean'],
                'passive' => ['nullable', 'boolean'],
            ],
            'sftp', 'ssh' => [
                'host' => ['required', 'string'],
                'username' => ['required', 'string'],
                'password' => ['nullable', 'string', 'required_without:private_key'],
                'private_key' => ['nullable', 'string', 'required_without:password'],
                'passphrase' => ['nullable', 'string'],
                'root' => ['nullable', 'string'],
                'port' => ['nullable', 'integer', 'between:1,65535'],
                'host_fingerprint' => ['nullable', 'string'],
            ],
            'dropbox' => [
                'access_token' => ['required', 'string'],
            ],
            'email' => [
                'recipient' => ['required', 'email'],
                'sender' => ['nullable', 'email'],
            ],
            default => [],
        };

        $providerValidator = Validator::make($settings, $rules);

        foreach ($providerValidator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add("settings.{$field}", $message);
            }
        }
    }

    /**
     * Preserve encrypted credential values when an edit form submits blanks.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeSettings(array $existing, array $incoming): array
    {
        foreach (FileStore::SensitiveSettings as $key) {
            if (array_key_exists($key, $incoming) && ($incoming[$key] === null || $incoming[$key] === '')) {
                unset($incoming[$key]);
            }
        }

        return [...$existing, ...$incoming];
    }

    /**
     * Reject absolute and traversal paths before reaching an adapter.
     */
    private function assertRelativePath(string $path, bool $allowEmpty = false): void
    {
        if (($allowEmpty && $path === '') || ($path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! preg_match('#(^|/)\.\.(?:/|$)#', $path))) {
            return;
        }

        throw new RuntimeException('File store paths must be relative and may not traverse directories.');
    }

    /**
     * Confirm a canonical path is equal to or nested beneath a canonical root.
     */
    private function isPathWithinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
