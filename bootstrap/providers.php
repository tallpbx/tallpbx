<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;
use Laravel\Boost\BoostServiceProvider;

return [
    AppServiceProvider::class,
    BoostServiceProvider::class,
    ModuleServiceProvider::class,
];
