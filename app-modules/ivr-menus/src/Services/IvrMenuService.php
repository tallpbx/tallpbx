<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\IvrMenus\Models\IvrMenu;

/**
 * Service implementing IVR menu CRUD operations with
 * tenant-scoped uniqueness validation and FreeSWITCH
 * dialplan XML generation.
 */
class IvrMenuService implements ContextWideDialplanXmlContributor, IvrMenuServiceInterface
{
    /** Create the IVR service with managed-media path resolution. */
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage) {}

    /**
     * Feature-level routing — IVR menus run after emergency/blocks/DID matching.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    public function create(array $data): IvrMenu
    {
        $this->validateUniqueName($data['tenant_id'], $data['name'] ?? '', null);

        return IvrMenu::create($data);
    }

    public function update(IvrMenu $menu, array $data): IvrMenu
    {
        if (isset($data['name']) && $data['name'] !== $menu->name) {
            $this->validateUniqueName($data['tenant_id'] ?? $menu->tenant_id, $data['name'], $menu->id);
        }

        $menu->update($data);

        return $menu->fresh();
    }

    public function delete(IvrMenu $menu): void
    {
        if ($menu->mediaAsset !== null) {
            $this->mediaStorage->requestDeletion($menu->mediaAsset->id);
        }

        $menu->delete();
    }

    public function getByTenant(int $tenantId): Collection
    {
        return IvrMenu::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Validate that the menu name is unique within the given tenant.
     *
     * @throws ValidationException
     */
    private function validateUniqueName(int $tenantId, string $name, ?string $excludeId): void
    {
        $query = IvrMenu::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An IVR menu with this name already exists in this tenant.'],
            ]);
        }
    }

    /**
     * Generate dialplan XML for IVR menus.
     *
     * Each enabled IVR menu contributes an extension that plays the greeting.
     * Interactive menus bind digit actions and wait for input, while
     * announcement-only menus play their prompt and immediately hang up.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $menus = IvrMenu::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with(['options', 'mediaAsset'])
            ->orderBy('name')
            ->get();

        if ($menus->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($menus as $menu) {
            $safeName = htmlspecialchars($menu->name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $digitLen = $menu->digit_length > 0 ? $menu->digit_length : 1;
            $timeout = $menu->timeout > 0 ? $menu->timeout * 1000 : 5000; // Convert seconds to ms
            $enabledOptions = $menu->options->filter(fn ($option): bool => $option->enabled);

            $xml .= "      <extension name=\"ivr_{$safeName}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeName}$\">\n";

            $xml .= "          <action application=\"answer\"/>\n";

            if ($enabledOptions->isNotEmpty()) {
                $xml .= "          <action application=\"sleep\" data=\"1000\"/>\n";
            }

            $greeting = $this->resolveGreetingPath($menu->mediaAsset?->id, $menu->greeting);

            if ($greeting !== null) {
                $safeGreeting = htmlspecialchars($greeting, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"playback\" data=\"{$safeGreeting}\"/>\n";
            }

            if ($enabledOptions->isNotEmpty()) {
                foreach ($enabledOptions as $option) {
                    $safeDigit = htmlspecialchars($option->digit, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $safeAction = htmlspecialchars($option->action, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $safeData = $option->action_data !== null
                        ? htmlspecialchars($option->action_data, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        : '';

                    if ($safeData !== '') {
                        $xml .= "          <action application=\"bind_digit_action\" data=\"{$safeDigit}~~{$safeAction}~~{$safeData}\"/>\n";
                    } else {
                        $xml .= "          <action application=\"bind_digit_action\" data=\"{$safeDigit}~~{$safeAction}\"/>\n";
                    }
                }

                $xml .= "          <action application=\"read\" data=\"{$digitLen} {$digitLen} 'touchexec/' {$digitLen} {$timeout}\"/>\n";
            }

            $xml .= "          <action application=\"hangup\" data=\"NORMAL_CLEARING\"/>\n";

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }

    /** Resolve a managed greeting first while preserving legacy paths during migration. */
    private function resolveGreetingPath(?string $mediaAssetId, ?string $legacyPath): ?string
    {
        if ($mediaAssetId !== null) {
            try {
                return $this->mediaStorage->resolveLocalPath($mediaAssetId);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $legacyPath !== '' ? $legacyPath : null;
    }
}
