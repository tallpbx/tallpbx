<?php

declare(strict_types=1);

namespace Modules\FileStores\Providers;

use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use Modules\FileStores\Console\ReconcileMediaAssets;
use Modules\FileStores\Services\FileStoreAdapterFactory;
use Modules\FileStores\Services\FileStoreAdapterFactoryInterface;
use Modules\FileStores\Services\FileStoreService;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\MediaArchiveDestinationService;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;
use Modules\FileStores\Services\MediaStorageService;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * Registers File Stores services, navigation, and declarative permissions.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        FileStoreServiceInterface::class => FileStoreService::class,
        FileStoreAdapterFactoryInterface::class => FileStoreAdapterFactory::class,
        MediaArchiveDestinationServiceInterface::class => MediaArchiveDestinationService::class,
        MediaStorageServiceInterface::class => MediaStorageService::class,
    ];

    /**
     * Register module-owned console commands after base module bootstrapping.
     */
    public function boot(MenuService $menu, PermissionService $permission, ModuleState $modules): void
    {
        parent::boot($menu, $permission, $modules);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileMediaAssets::class]);
        }
    }

    /**
     * Return the module's kebab-case identifier.
     */
    protected function moduleName(): string
    {
        return 'file-stores';
    }

    /**
     * Return the module's PHP namespace root.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\FileStores';
    }

    /**
     * Register File Stores in the unified panel navigation.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'file-stores',
                'label' => 'File Stores',
                'route' => 'panel.file-stores.index',
                'permission' => 'file-stores.view',
                'icon' => 'heroicon-o-folder',
                'parent' => 'pbx.advanced',
                'order' => 91,
            ],
        ];
    }

    /**
     * Register permissions for admin-managed destination profiles.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'file-stores.view' => 'View file store destinations',
            'file-stores.create' => 'Create file store destinations',
            'file-stores.update' => 'Update file store destinations',
            'file-stores.delete' => 'Delete file store destinations',
            'file-stores.test-connection' => 'Test file store connections',
        ];
    }
}
