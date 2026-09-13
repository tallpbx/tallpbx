<?php

declare(strict_types=1);

/**
 * Keep data tables visually consistent with the established Groups-list baseline.
 */
it('uses the shared table presentation for every table-backed panel list', function (): void {
    $listViews = glob(base_path('app-modules/*/resources/views/*list*.blade.php'));

    expect($listViews)->not->toBeFalse();

    foreach ($listViews as $listView) {
        $contents = file_get_contents($listView);

        if (! str_contains($contents, '<table')) {
            continue;
        }

        expect($contents, basename($listView))
            ->toMatch('/class="table table-zebra(?: w-full)?"/');
    }
});

/**
 * Keep the File Stores list actions at the same compact size as Groups actions.
 */
it('uses the shared compact action presentation on the file stores list', function (): void {
    $contents = file_get_contents(base_path('app-modules/file-stores/resources/views/file-stores-list.blade.php'));

    expect($contents)
        ->toContain('class="btn btn-ghost btn-xs"')
        ->toContain('class="btn btn-ghost btn-xs text-error"')
        ->toContain('<x-heroicon-o-signal class="w-4 h-4" />')
        ->toContain('<x-heroicon-o-pencil-square class="w-4 h-4" />')
        ->toContain('<x-heroicon-o-trash class="w-4 h-4" />');
});
