<?php

declare(strict_types=1);

namespace Modules\Speech\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Speech\Models\SpeechConfig;

/**
 * Contract for TTS configuration management.
 *
 * Provides full CRUD operations for speech engine settings
 * per tenant. All operations internally handle tenant-scope
 * bypass where needed so callers never need to call
 * withoutGlobalScope('tenant') directly.
 */
interface SpeechServiceInterface
{
    /**
     * Find a speech configuration by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): SpeechConfig;

    /**
     * Get all speech configurations across all tenants, ordered by engine.
     *
     * @return Collection<int, SpeechConfig>
     */
    public function all(): Collection;

    /**
     * Create a new speech configuration.
     */
    public function create(array $data): SpeechConfig;

    /**
     * Update an existing speech configuration.
     */
    public function update(SpeechConfig $config, array $data): SpeechConfig;

    /**
     * Delete a speech configuration.
     */
    public function delete(SpeechConfig $config): void;
}
