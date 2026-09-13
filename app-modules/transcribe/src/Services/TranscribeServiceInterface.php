<?php

declare(strict_types=1);

namespace Modules\Transcribe\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Transcribe\Models\Transcription;

/**
 * Contract for transcription management operations.
 *
 * Currently supports listing, finding, and deleting transcriptions.
 * All operations internally handle tenant-scope bypass where
 * needed so callers never need to call withoutGlobalScope('tenant')
 * directly.
 */
interface TranscribeServiceInterface
{
    /**
     * Find a transcription by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): Transcription;

    /**
     * Get all transcriptions across all tenants, ordered by newest first.
     *
     * @return Collection<int, Transcription>
     */
    public function all(): Collection;

    /**
     * Delete a transcription record from the database.
     */
    public function delete(Transcription $transcription): void;
}
