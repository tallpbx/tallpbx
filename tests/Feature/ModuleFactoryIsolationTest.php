<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps module factory classes inside their owning modules', function (): void {
    // Module code must never reference the root app's Pbx factory namespace.
    $violations = collect(File::allFiles(base_path('app-modules')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'Database\\Factories\\Pbx'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

it('resolves every module model factory from its own module namespace', function (): void {
    // Every model using HasFactory must yield a factory class that lives in
    // the same Modules\{Studly}\Database\Factories namespace, resolved by
    // Laravel's default factory resolution (no newFactory() overrides).
    $models = collect(File::allFiles(base_path('app-modules')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'use HasFactory'))
        ->map(function ($file): ?string {
            $contents = $file->getContents();

            if (! preg_match('/namespace\s+(Modules\\\\[A-Za-z0-9_]+)\\\\Models;/', $contents, $ns)) {
                return null;
            }

            if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $contents, $class)) {
                return null;
            }

            $fqcn = $ns[1].'\\Models\\'.$class[1];

            return class_exists($fqcn) ? $fqcn : null;
        })
        ->filter();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        $factory = $model::factory();

        expect(get_class($factory))->toStartWith('Modules\\')
            ->and(get_class($factory))->toEndWith('Factory');
    }
});
