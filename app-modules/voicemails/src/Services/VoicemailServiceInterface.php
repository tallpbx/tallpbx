<?php

declare(strict_types=1);

namespace Modules\Voicemails\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Voicemails\Models\Voicemail;

/**
 * Service for managing voicemail mailboxes.
 */
interface VoicemailServiceInterface
{
    /**
     * Create a new voicemail mailbox.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Voicemail;

    /**
     * Update an existing voicemail mailbox.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Voicemail $voicemail, array $data): Voicemail;

    /**
     * Delete a voicemail mailbox.
     */
    public function delete(Voicemail $voicemail): void;

    /**
     * Get all voicemail mailboxes for a specific tenant.
     *
     * @return Collection<int, Voicemail>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Find a voicemail by its mailbox number within a tenant.
     */
    public function findByMailbox(int $tenantId, string $mailbox): ?Voicemail;
}
