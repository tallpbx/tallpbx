<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops the obsolete source_ref column from module registry rows', function (): void {
    // Git-based module restore is dead under the modular-monolith rules:
    // core modules are permanent monorepo code that can never be uninstalled,
    // and custom extensions are untracked. Vendor restore re-requires the
    // Composer package, so only composer_package remains meaningful.
    expect(Schema::hasColumn('modules', 'source_ref'))->toBeFalse()
        ->and(Schema::hasColumn('modules', 'composer_package'))->toBeTrue();
});
