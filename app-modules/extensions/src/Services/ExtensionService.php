<?php

declare(strict_types=1);

namespace Modules\Extensions\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Extensions\Models\Extension;

/**
 * Service implementation for managing extensions with tenant-scoped
 * uniqueness validation.
 */
class ExtensionService implements ExtensionServiceInterface
{
    /**
     * Create a new extension.
     */
    public function create(array $data): Extension
    {
        $this->validateUniqueNumber($data['tenant_id'], $data['extension_number']);

        return Extension::create($data);
    }

    /**
     * Update an existing extension.
     */
    public function update(Extension $extension, array $data): Extension
    {
        if (isset($data['extension_number']) && $data['extension_number'] !== $extension->extension_number) {
            $tenantId = $data['tenant_id'] ?? $extension->tenant_id;
            $this->validateUniqueNumber($tenantId, $data['extension_number'], $extension->id);
        }

        $extension->update($data);

        return $extension->fresh();
    }

    /**
     * Create a contiguous numeric extension range.
     */
    public function createRange(array $data, int $start, int $end, int $increment = 1, ?string $displayNameTemplate = null): Collection
    {
        if ($increment < 1 || $end < $start) {
            throw ValidationException::withMessages([
                'rangeEnd' => ['The extension range must increase by at least one.'],
            ]);
        }

        return DB::transaction(function () use ($data, $start, $end, $increment, $displayNameTemplate): Collection {
            $extensions = new Collection;

            for ($number = $start; $number <= $end; $number += $increment) {
                $extensionData = $data;
                $extensionData['extension_number'] = (string) $number;

                if (($extensionData['number_alias'] ?? null) !== null) {
                    $extensionData['number_alias'] = (string) $number;
                }

                if ($displayNameTemplate !== null && trim($displayNameTemplate) !== '') {
                    $extensionData['display_name'] = str_replace('{number}', (string) $number, $displayNameTemplate);
                }

                $extensions->push($this->create($extensionData));
            }

            return $extensions;
        });
    }

    /**
     * Replace the portal users assigned to an extension.
     */
    public function syncUsers(Extension $extension, array $userIds): Extension
    {
        $extension->users()->sync($userIds);

        return $extension->fresh('users');
    }

    /**
     * Delete an extension.
     */
    public function delete(Extension $extension): void
    {
        $extension->delete();
    }

    /**
     * Get all extensions for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Ensure the extension number is unique within the tenant.
     *
     * @throws ValidationException
     */
    private function validateUniqueNumber(int $tenantId, string $number, ?string $excludeId = null): void
    {
        $query = Extension::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('extension_number', $number);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'extension_number' => ['The extension number is already taken within this tenant.'],
            ]);
        }
    }
}
