<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Traits\ModuleAwareTestGuard;

/**
 * Base test case for the application's feature and unit tests.
 */
abstract class TestCase extends BaseTestCase
{
    use ModuleAwareTestGuard;

    /**
     * Boot the application with the test-only view isolation applied.
     *
     * Tests must never write into the live panel's compiled-view folder
     * (storage/framework/views): root-run tests used to leave root-owned files
     * there that broke the panel with "touch(): Utime failed". Every test boot
     * therefore compiles views into a dedicated test-only folder instead.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $compiledViews = storage_path('framework/testing-views');

        // Parallel test workers race to create this folder on first use, so
        // the create is double-checked: losing the race is fine, a real
        // failure to create it is loud instead of surfacing as a confusing
        // assertion failure later.
        if (! is_dir($compiledViews) && ! @mkdir($compiledViews, 0775, true) && ! is_dir($compiledViews)) {
            throw new RuntimeException(sprintf('Unable to create the test-only view folder at %s.', $compiledViews));
        }

        $app['config']->set('view.compiled', $compiledViews);

        // parent::createApplication() already booted service providers and
        // resolved the Blade compiler with the default view.compiled path. In
        // non-parallel runs ParallelTesting::setUpTestCase never fires, so patch
        // the already-resolved compilers here just as Laravel's TestViews and
        // Livewire's SupportTesting do for parallel workers.
        if ($app->resolved('blade.compiler')) {
            $compiler = $app['blade.compiler'];

            (function () use ($compiledViews): void {
                $this->cachePath = $compiledViews;
            })->bindTo($compiler, $compiler)();
        }

        if ($app->resolved('livewire.compiler')) {
            $app['livewire.compiler']->cacheManager->cacheDirectory = $compiledViews.'/livewire';
        }

        return $app;
    }
}
