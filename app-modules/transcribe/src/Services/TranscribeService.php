<?php

declare(strict_types=1);

namespace Modules\Transcribe\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Transcribe\Models\Transcription;

/**
 * Service implementation for transcription CRUD operations.
 *
 * Wraps all write operations in database transactions to
 * maintain data integrity. All queries bypass the global
 * tenant scope because this service is used by admin panels
 * that need cross-tenant access.
 */
class TranscribeService implements TranscribeServiceInterface
{
    /**
     * Find a transcription by ID, bypassing tenant scope.
     */
    public function find(string $id): Transcription
    {
        return Transcription::withoutGlobalScope('tenant')->findOrFail($id);
    }

    /**
     * Get all transcriptions across all tenants, ordered by newest first.
     */
    public function all(): Collection
    {
        return Transcription::withoutGlobalScope('tenant')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Delete a transcription inside a database transaction.
     */
    public function delete(Transcription $transcription): void
    {
        DB::transaction(fn () => $transcription->withoutGlobalScope('tenant')->delete());
    }
}
