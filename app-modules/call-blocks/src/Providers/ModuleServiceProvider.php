<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Providers;

use Modules\CallBlocks\Services\CallBlockService;
use Modules\CallBlocks\Services\CallBlockServiceInterface;

/**
 * Service provider for the call-blocks module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        CallBlockServiceInterface::class => CallBlockService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags CallBlockService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(CallBlockServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-blocks';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallBlocks';
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
        return [
            // Unified panel sidebar — visible to both admin and tenant users.
            // Permission gating via MenuService::cannotView() controls visibility.
            [
                'key' => 'call-blocks',
                'label' => 'admin.call_blocks',
                'route' => 'panel.call-blocks.index',
                'permission' => 'call-blocks.view',
                'icon' => 'heroicon-o-no-symbol',
                'parent' => 'pbx.routing',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the call-blocks module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-blocks.view' => 'View call block rules',
            'call-blocks.create' => 'Create new call block rules',
            'call-blocks.edit' => 'Edit existing call block rules',
            'call-blocks.delete' => 'Delete call block rules',
        ];
    }
}
