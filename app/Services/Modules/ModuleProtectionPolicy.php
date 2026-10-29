<?php

declare(strict_types=1);

namespace App\Services\Modules;

use InvalidArgumentException;

/**
 * Enforces module protection invariants across lifecycle operations.
 */
class ModuleProtectionPolicy
{
    /**
     * Determine whether a module is protected against uninstallation.
     *
     * A module is protected if its manifest specifies 'protected' => true OR 'required' => true.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function isProtected(array $manifest): bool
    {
        return (bool) ($manifest['protected'] ?? false)
            || (bool) ($manifest['required'] ?? false);
    }

    /**
     * Assert that a module can be uninstalled, throwing if it is protected or required.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidArgumentException
     */
    public function assertCanUninstall(array $manifest): void
    {
        if ($this->isProtected($manifest)) {
            throw new InvalidArgumentException('Required and protected modules cannot be uninstalled.');
        }
    }
}
