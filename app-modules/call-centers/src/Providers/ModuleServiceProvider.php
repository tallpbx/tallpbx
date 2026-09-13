<?php

declare(strict_types=1);

namespace Modules\CallCenters\Providers;

use Modules\CallCenters\Services\CallCenterService;
use Modules\CallCenters\Services\CallCenterServiceInterface;

/**
 * Service provider for the call-centers module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        CallCenterServiceInterface::class => CallCenterService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags CallCenterService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(CallCenterServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-centers';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallCenters';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Items with guard 'admin' appear in the admin sidebar.
     * Items with guard 'web' appear in the client/tenant sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [['key' => 'admin.call-centers', 'label' => 'admin.call_centers', 'route' => 'panel.call-centers.queues.index', 'permission' => 'call-centers.view', 'icon' => 'heroicon-o-queue-list', 'parent' => 'pbx.monitoring', 'guard' => 'admin', 'order' => 60]];
    }

    /**
     * Register permissions for the call-centers module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return ['call-centers.view' => 'View call centers', 'call-centers.create' => 'Create call center queues', 'call-centers.edit' => 'Edit call center queues', 'call-centers.delete' => 'Delete call center queues'];
    }
}
