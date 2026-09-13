<?php

declare(strict_types=1);

namespace Modules\Speech\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Speech\Models\SpeechConfig;

/**
 * Service implementation for TTS configuration management.
 *
 * Wraps all write operations in database transactions to
 * maintain data integrity across the speech_config table.
 * All queries bypass the global tenant scope because this
 * service is used by admin panels that need cross-tenant access.
 */
class SpeechService implements SpeechServiceInterface
{
    /**
     * Find a speech config by ID, bypassing tenant scope.
     */
    public function find(string $id): SpeechConfig
    {
        return SpeechConfig::withoutGlobalScope('tenant')->findOrFail($id);
    }

    /**
     * Get all speech configs across all tenants, ordered by engine.
     */
    public function all(): Collection
    {
        return SpeechConfig::withoutGlobalScope('tenant')
            ->orderBy('engine')
            ->get();
    }

    /**
     * Create a new speech config inside a transaction.
     */
    public function create(array $data): SpeechConfig
    {
        return DB::transaction(fn () => SpeechConfig::withoutGlobalScope('tenant')->create($data));
    }

    /**
     * Update an existing speech config inside a transaction.
     */
    public function update(SpeechConfig $config, array $data): SpeechConfig
    {
        return DB::transaction(function () use ($config, $data): SpeechConfig {
            $config->withoutGlobalScope('tenant')->update($data);

            return $config->fresh();
        });
    }

    /**
     * Delete a speech config inside a transaction.
     */
    public function delete(SpeechConfig $config): void
    {
        DB::transaction(fn () => $config->withoutGlobalScope('tenant')->delete());
    }
}
