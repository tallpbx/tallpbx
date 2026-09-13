<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Defines how much of the application tree a permission repair may change.
 */
enum PermissionRepairScope: string
{
    case Generated = 'generated';
    case Runtime = 'runtime';
    case Full = 'full';
}
