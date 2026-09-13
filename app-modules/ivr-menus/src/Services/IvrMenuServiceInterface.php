<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\IvrMenus\Models\IvrMenu;

/**
 * Service interface for IVR menu CRUD operations.
 */
interface IvrMenuServiceInterface
{
    /**
     * Create a new IVR menu with the given data.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): IvrMenu;

    /**
     * Update an existing IVR menu.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(IvrMenu $menu, array $data): IvrMenu;

    /**
     * Delete an IVR menu and its options.
     */
    public function delete(IvrMenu $menu): void;

    /**
     * Get all IVR menus for a given tenant, ordered by name.
     *
     * @return Collection<int, IvrMenu>
     */
    public function getByTenant(int $tenantId): Collection;
}
