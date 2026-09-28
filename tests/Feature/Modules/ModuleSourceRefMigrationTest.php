<?php

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Support\Facades\Schema;

it('stores the git source revision on module registry rows', function (): void {
    // The source_ref column records which git revision still held a module's
    // files at uninstall time, keeping module:restore working even after the
    // deletion itself is committed to the repository.
    expect(Schema::hasColumn('modules', 'source_ref'))->toBeTrue();

    Module::create([
        'name' => 'revived-module',
        'display_name' => 'Revived Module',
        'version' => '1.0.0',
        'enabled' => false,
        'source_ref' => 'deadbeef1234abcd',
    ]);

    expect(Module::where('name', 'revived-module')->value('source_ref'))->toBe('deadbeef1234abcd');
});
