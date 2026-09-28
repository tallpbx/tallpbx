<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Traits\ModuleAwareTestGuard;

/**
 * Base test case for the application's feature and unit tests.
 */
abstract class TestCase extends BaseTestCase
{
    use ModuleAwareTestGuard;
}
