<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Providers;

use Modules\FeatureCodes\Services\FeatureCodeService;
use Modules\FeatureCodes\Services\FeatureCodeServiceInterface;

/**
 * Service provider for the feature-codes module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        FeatureCodeServiceInterface::class => FeatureCodeService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags FeatureCodeService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(FeatureCodeServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'feature-codes';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\FeatureCodes';
    }

    protected function hasTranslations(): bool
    {
        return true;
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
                'key' => 'feature-codes',
                'label' => 'admin.feature_codes',
                'route' => 'panel.feature-codes.index',
                'permission' => 'feature-codes.view',
                'icon' => 'heroicon-o-key',
                'parent' => 'pbx.features',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the feature-codes module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'feature-codes.view' => 'View feature codes',
            'feature-codes.create' => 'Create new feature codes',
            'feature-codes.edit' => 'Edit existing feature codes',
            'feature-codes.delete' => 'Delete feature codes',
        ];
    }
}
