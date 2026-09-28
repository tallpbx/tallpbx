<?php

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Support\Facades\Schema;

it('stores the composer package on module registry rows', function (): void {
    expect(Schema::hasColumn('modules', 'composer_package'))->toBeTrue();

    Module::create([
        'name' => 'private-module',
        'display_name' => 'Private Module',
        'version' => '1.0.0',
        'enabled' => true,
        'composer_package' => 'acme/private-module',
    ]);

    expect(Module::where('name', 'private-module')->value('composer_package'))->toBe('acme/private-module');
});
