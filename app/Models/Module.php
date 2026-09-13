<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a registered module in the system.
 *
 * Modules are self-contained feature bundles discovered via module.json
 * manifests. They can be enabled, disabled, or protected from removal.
 */
class Module extends Model
{
    use HasUuids;

    public const StatusEnabled = 'enabled';

    public const StatusDisabled = 'disabled';

    public const StatusUninstalled = 'uninstalled';

    protected $fillable = [
        'name',
        'display_name',
        'version',
        'enabled',
        'status',
        'protected',
        'required',
        'priority',
        'settings',
    ];

    /**
     * Attribute casting configuration.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'status' => 'string',
            'protected' => 'boolean',
            'required' => 'boolean',
            'priority' => 'integer',
            'settings' => 'array',
        ];
    }
}
