<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\NumberTranslations\Models\NumberTranslation;

/**
 * Contract for number translation CRUD operations.
 *
 * All operations internally handle tenant-scope bypass
 * where needed so callers never need to call
 * withoutGlobalScope('tenant') directly.
 */
interface NumberTranslationServiceInterface
{
    /**
     * Find a number translation by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): NumberTranslation;

    /**
     * Get all number translations across all tenants, ordered by name.
     *
     * @return Collection<int, NumberTranslation>
     */
    public function all(): Collection;

    /**
     * Apply the tenant's enabled translation rules to a destination number.
     *
     * Rules are applied in order (order then name) as regex replaces.
     * Rules with invalid regex are logged and skipped. Returns the
     * destination unchanged when no rules apply or no tenant is set.
     */
    public function translate(string $destination, string $direction): string;

    /**
     * Create a new translation rule.
     */
    public function create(array $data): NumberTranslation;

    /**
     * Update an existing translation rule and return the fresh copy.
     */
    public function update(NumberTranslation $translation, array $data): NumberTranslation;

    /**
     * Delete a translation rule.
     */
    public function delete(NumberTranslation $translation): void;
}
